<?php

namespace App\Http\Controllers\Web\Subscriber\Oz\PriceCalc;

use App\Http\Controllers\Web\Subscriber\Concerns\ResolvesSelectedOzCabinet;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\ImportOzPriceCalcExcelRequest;
use App\Models\Subscribers\Oz\OzCabinet;
use App\Services\Subscriber\Oz\OzPriceCalcFboService;
use App\Services\Subscriber\Oz\OzPriceCalcFbsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkspaceController extends SubscriberToolController
{
    use ResolvesSelectedOzCabinet;

    public function __construct(
        private readonly OzPriceCalcFboService $fboService,
        private readonly OzPriceCalcFbsService $fbsService,
    ) {
    }

    public function show(Request $request): Response
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinet($request, 'Ценообразование Ozon', [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => 'Ценообразование Ozon'],
        ]);
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var OzCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $mode = $this->resolveMode($request);

        $rowsPayload = $this->decodeApiResponse(
            $this->indexResponse($request, $cabinet->id, $mode)
        );

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

    public function syncFbo(Request $request): RedirectResponse|Response
    {
        return $this->dispatchAction(
            $request,
            fn (OzCabinet $cabinet) => $this->fboService->sync($request, $cabinet->id),
            'Синхронизация запущена',
            'Не удалось запустить синхронизацию'
        );
    }

    public function syncFbs(Request $request): RedirectResponse|Response
    {
        return $this->dispatchAction(
            $request,
            fn (OzCabinet $cabinet) => $this->fbsService->sync($request, $cabinet->id),
            'Синхронизация запущена',
            'Не удалось запустить синхронизацию'
        );
    }

    public function calculateFbo(Request $request): RedirectResponse|Response
    {
        return $this->dispatchAction(
            $request,
            fn (OzCabinet $cabinet) => $this->fboService->calculate($request, $cabinet->id),
            'Калькуляция запущена',
            'Не удалось запустить калькуляцию'
        );
    }

    public function calculateFbs(Request $request): RedirectResponse|Response
    {
        return $this->dispatchAction(
            $request,
            fn (OzCabinet $cabinet) => $this->fbsService->calculate($request, $cabinet->id),
            'Калькуляция запущена',
            'Не удалось запустить калькуляцию'
        );
    }

    public function importFbo(ImportOzPriceCalcExcelRequest $request): RedirectResponse|Response
    {
        return $this->dispatchAction(
            $request,
            fn (OzCabinet $cabinet) => $this->fboService->import($request, $cabinet->id),
            'Импорт запущен',
            'Импорт не выполнен'
        );
    }

    public function importFbs(ImportOzPriceCalcExcelRequest $request): RedirectResponse|Response
    {
        return $this->dispatchAction(
            $request,
            fn (OzCabinet $cabinet) => $this->fbsService->import($request, $cabinet->id),
            'Импорт запущен',
            'Импорт не выполнен'
        );
    }

    public function exportFbo(Request $request): RedirectResponse|Response
    {
        return $this->dispatchAction(
            $request,
            fn (OzCabinet $cabinet) => $this->fboService->export($request, $cabinet->id),
            'Экспорт запущен',
            'Не удалось запустить экспорт'
        );
    }

    public function exportFbs(Request $request): RedirectResponse|Response
    {
        return $this->dispatchAction(
            $request,
            fn (OzCabinet $cabinet) => $this->fbsService->export($request, $cabinet->id),
            'Экспорт запущен',
            'Не удалось запустить экспорт'
        );
    }

    public function exportDownloadFbo(Request $request): RedirectResponse|StreamedResponse|Response
    {
        return $this->streamExportFile($request, 'fbo');
    }

    public function exportDownloadFbs(Request $request): RedirectResponse|StreamedResponse|Response
    {
        return $this->streamExportFile($request, 'fbs');
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
        if ($mode === 'fbs') {
            $syncPayload = $this->decodeApiResponse($this->fbsService->status($request, $cabinetId));
            $calcPayload = $this->decodeApiResponse($this->fbsService->calculateStatus($request, $cabinetId));
            $importPayload = $this->decodeApiResponse($this->fbsService->importStatus($request, $cabinetId));
            $exportPayload = $this->decodeApiResponse($this->fbsService->exportStatus($request, $cabinetId));
        } else {
            $syncPayload = $this->decodeApiResponse($this->fboService->status($request, $cabinetId));
            $calcPayload = $this->decodeApiResponse($this->fboService->calculateStatus($request, $cabinetId));
            $importPayload = $this->decodeApiResponse($this->fboService->importStatus($request, $cabinetId));
            $exportPayload = $this->decodeApiResponse($this->fboService->exportStatus($request, $cabinetId));
        }

        return [
            'is_syncing' => (bool) ($syncPayload['data']['is_syncing'] ?? false),
            'is_calculating' => (bool) ($calcPayload['data']['is_calculating'] ?? false),
            'is_importing' => (bool) ($importPayload['data']['is_importing'] ?? false),
            'is_exporting' => (bool) ($exportPayload['data']['is_exporting'] ?? false),
            'last_error' => $syncPayload['data']['last_error'] ?? null,
            'export_file_url' => $exportPayload['data']['file_url'] ?? null,
        ];
    }

    private function resolveMode(Request $request): string
    {
        $mode = strtolower((string) $request->input('mode', 'fbo'));

        return $mode === 'fbs' ? 'fbs' : 'fbo';
    }

    private function indexResponse(Request $request, int $cabinetId, string $mode): \Symfony\Component\HttpFoundation\Response
    {
        if ($mode === 'fbs') {
            return $this->fbsService->index($request, $cabinetId);
        }

        return $this->fboService->index($request, $cabinetId);
    }

    private function dispatchAction(
        Request $request,
        callable $action,
        string $successFallback,
        string $errorFallback,
    ): RedirectResponse|Response {
        $cabinetOrResponse = $this->requireSelectedOzCabinet($request, 'Ценообразование Ozon', [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => 'Ценообразование Ozon'],
        ]);
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var OzCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $payload = $this->decodeApiResponse($action($cabinet));

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, $errorFallback));
        }

        return back()->with('success', $this->apiMessage($payload, $successFallback));
    }

    private function streamExportFile(Request $request, string $mode): RedirectResponse|StreamedResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinet($request, 'Ценообразование Ozon', [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => 'Ценообразование Ozon'],
        ]);
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var OzCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

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
