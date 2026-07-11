<?php

namespace App\Http\Controllers\Web\Subscriber\Oz\PriceCalc;

use App\Http\Controllers\Api\Subscriber\Ozon\PriceCalc\FboController as ApiFboController;
use App\Http\Controllers\Api\Subscriber\Ozon\PriceCalc\FbsController as ApiFbsController;
use App\Http\Controllers\Web\Subscriber\Concerns\DelegatesToApiGuard;
use App\Http\Controllers\Web\Subscriber\Concerns\EnsuresOzPriceCalcCabinetOwnership;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\ImportOzPriceCalcExcelRequest;
use App\Models\Subscribers\Oz\PriceCalc\OzPriceCalcCabinet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkspaceController extends SubscriberToolController
{
    use DelegatesToApiGuard;
    use EnsuresOzPriceCalcCabinetOwnership;

    public function __construct(
        private readonly ApiFboController $apiFboController,
        private readonly ApiFbsController $apiFbsController,
    ) {
    }

    public function show(Request $request, OzPriceCalcCabinet $cabinet): Response
    {
        $this->ensureCabinetOwnership($cabinet);

        $mode = $this->resolveMode($request);

        $rowsPayload = $this->withApiGuard($request, fn () => $this->decodeApiResponse(
            $this->indexResponse($request, $cabinet->id, $mode)
        ));

        $rowsData = ($rowsPayload['success'] ?? false) ? ($rowsPayload['data'] ?? []) : [];

        return Inertia::render('Subscriber/Oz/PriceCalc/Cabinet/Show', [
            'cabinet' => [
                'id' => $cabinet->id,
                'name' => $cabinet->name,
                'client_id' => $cabinet->client_id,
            ],
            'mode' => $mode,
            'rows' => $rowsData['data'] ?? [],
            'rowsMeta' => $this->buildRowsMeta($rowsData, $request),
            'columns' => ($rowsPayload['success'] ?? false) ? ($rowsPayload['columns'] ?? []) : [],
            'rowsError' => ($rowsPayload['success'] ?? false) ? null : $this->apiMessage($rowsPayload, 'Не удалось загрузить номенклатуру'),
            'jobStatus' => $this->buildJobStatus($request, $cabinet->id, $mode),
            'filters' => [
                'page' => (int) $request->input('page', 1),
                'per_page' => (int) $request->input('per_page', 250),
                'sort_key' => $request->input('sort_key'),
                'sort_dir' => $request->input('sort_dir', 'asc'),
                'search' => $request->input('search', ''),
            ],
        ]);
    }

    public function syncFbo(Request $request, OzPriceCalcCabinet $cabinet): RedirectResponse
    {
        return $this->dispatchAction(
            $request,
            $cabinet,
            fn () => $this->apiFboController->sync($request, $cabinet->id),
            'Синхронизация запущена',
            'Не удалось запустить синхронизацию'
        );
    }

    public function syncFbs(Request $request, OzPriceCalcCabinet $cabinet): RedirectResponse
    {
        return $this->dispatchAction(
            $request,
            $cabinet,
            fn () => $this->apiFbsController->sync($request, $cabinet->id),
            'Синхронизация запущена',
            'Не удалось запустить синхронизацию'
        );
    }

    public function calculateFbo(Request $request, OzPriceCalcCabinet $cabinet): RedirectResponse
    {
        return $this->dispatchAction(
            $request,
            $cabinet,
            fn () => $this->apiFboController->calculate($request, $cabinet->id),
            'Калькуляция запущена',
            'Не удалось запустить калькуляцию'
        );
    }

    public function calculateFbs(Request $request, OzPriceCalcCabinet $cabinet): RedirectResponse
    {
        return $this->dispatchAction(
            $request,
            $cabinet,
            fn () => $this->apiFbsController->calculate($request, $cabinet->id),
            'Калькуляция запущена',
            'Не удалось запустить калькуляцию'
        );
    }

    public function importFbo(ImportOzPriceCalcExcelRequest $request, OzPriceCalcCabinet $cabinet): RedirectResponse
    {
        return $this->dispatchAction(
            $request,
            $cabinet,
            fn () => $this->apiFboController->import($request, $cabinet->id),
            'Импорт запущен',
            'Импорт не выполнен'
        );
    }

    public function importFbs(ImportOzPriceCalcExcelRequest $request, OzPriceCalcCabinet $cabinet): RedirectResponse
    {
        return $this->dispatchAction(
            $request,
            $cabinet,
            fn () => $this->apiFbsController->import($request, $cabinet->id),
            'Импорт запущен',
            'Импорт не выполнен'
        );
    }

    public function exportFbo(Request $request, OzPriceCalcCabinet $cabinet): RedirectResponse
    {
        return $this->dispatchAction(
            $request,
            $cabinet,
            fn () => $this->apiFboController->export($request, $cabinet->id),
            'Экспорт запущен',
            'Не удалось запустить экспорт'
        );
    }

    public function exportFbs(Request $request, OzPriceCalcCabinet $cabinet): RedirectResponse
    {
        return $this->dispatchAction(
            $request,
            $cabinet,
            fn () => $this->apiFbsController->export($request, $cabinet->id),
            'Экспорт запущен',
            'Не удалось запустить экспорт'
        );
    }

    public function exportDownloadFbo(OzPriceCalcCabinet $cabinet): RedirectResponse|StreamedResponse
    {
        return $this->streamExportFile($cabinet, 'fbo');
    }

    public function exportDownloadFbs(OzPriceCalcCabinet $cabinet): RedirectResponse|StreamedResponse
    {
        return $this->streamExportFile($cabinet, 'fbs');
    }

    /**
     * @param  array<string, mixed>  $rowsData
     * @return array<string, mixed>
     */
    private function buildRowsMeta(array $rowsData, Request $request): array
    {
        return [
            'current_page' => (int) ($rowsData['current_page'] ?? $request->input('page', 1)),
            'per_page' => (int) ($rowsData['per_page'] ?? $request->input('per_page', 250)),
            'total' => (int) ($rowsData['total'] ?? 0),
            'last_page' => (int) ($rowsData['last_page'] ?? 1),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildJobStatus(Request $request, int $cabinetId, string $mode): array
    {
        return $this->withApiGuard($request, function () use ($request, $cabinetId, $mode) {
            if ($mode === 'fbs') {
                $syncPayload = $this->decodeApiResponse($this->apiFbsController->status($request, $cabinetId));
                $calcPayload = $this->decodeApiResponse($this->apiFbsController->calculateStatus($request, $cabinetId));
                $importPayload = $this->decodeApiResponse($this->apiFbsController->importStatus($request, $cabinetId));
                $exportPayload = $this->decodeApiResponse($this->apiFbsController->exportStatus($request, $cabinetId));
            } else {
                $syncPayload = $this->decodeApiResponse($this->apiFboController->status($request, $cabinetId));
                $calcPayload = $this->decodeApiResponse($this->apiFboController->calculateStatus($request, $cabinetId));
                $importPayload = $this->decodeApiResponse($this->apiFboController->importStatus($request, $cabinetId));
                $exportPayload = $this->decodeApiResponse($this->apiFboController->exportStatus($request, $cabinetId));
            }

            return [
                'is_syncing' => (bool) ($syncPayload['data']['is_syncing'] ?? false),
                'is_calculating' => (bool) ($calcPayload['data']['is_calculating'] ?? false),
                'is_importing' => (bool) ($importPayload['data']['is_importing'] ?? false),
                'is_exporting' => (bool) ($exportPayload['data']['is_exporting'] ?? false),
                'last_error' => $syncPayload['data']['last_error'] ?? null,
                'export_file_url' => $exportPayload['data']['file_url'] ?? null,
            ];
        });
    }

    private function resolveMode(Request $request): string
    {
        $mode = strtolower((string) $request->input('mode', 'fbo'));

        return $mode === 'fbs' ? 'fbs' : 'fbo';
    }

    private function indexResponse(Request $request, int $cabinetId, string $mode): \Symfony\Component\HttpFoundation\Response
    {
        if ($mode === 'fbs') {
            return $this->apiFbsController->index($request, $cabinetId);
        }

        return $this->apiFboController->index($request, $cabinetId);
    }

    private function dispatchAction(
        Request $request,
        OzPriceCalcCabinet $cabinet,
        callable $action,
        string $successFallback,
        string $errorFallback,
    ): RedirectResponse {
        $this->ensureCabinetOwnership($cabinet);

        $payload = $this->withApiGuard($request, fn () => $this->decodeApiResponse($action()));

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, $errorFallback));
        }

        return back()->with('success', $this->apiMessage($payload, $successFallback));
    }

    private function streamExportFile(OzPriceCalcCabinet $cabinet, string $mode): RedirectResponse|StreamedResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $path = "ozon/price-calc/{$cabinet->id}/{$mode}.xlsx";

        if (! Storage::disk('public')->exists($path)) {
            return back()->with('error', 'Файл экспорта не найден');
        }

        return Storage::disk('public')->download(
            $path,
            "ozon-{$mode}-{$cabinet->id}-".now()->format('Y-m-d').'.xlsx'
        );
    }
}