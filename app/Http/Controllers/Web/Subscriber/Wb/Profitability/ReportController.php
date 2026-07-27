<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\Profitability;

use App\Http\Controllers\Web\Subscriber\Concerns\ResolvesSelectedWbCabinet;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\StoreProfitabilityReportRequest;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Services\Subscriber\Wb\WbProfitabilityReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends SubscriberToolController
{
    use ResolvesSelectedWbCabinet;

    public function __construct(
        private readonly WbProfitabilityReportService $reportService,
    ) {
    }

    public function show(Request $request): Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinet($request, 'Рентабельность Wildberries', [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => 'Рентабельность Wildberries'],
        ]);
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $page = $this->reportService->getCabinetPageData(
            (int) $cabinet->id,
            (int) auth()->id()
        );

        return Inertia::render('Subscriber/Wb/Profitability/Cabinet/Show', [
            'cabinet' => [
                'id' => $cabinet->id,
                'name' => $cabinet->name,
            ],
            'jobStatus' => $page['jobStatus'],
            'report' => $page['report'],
            'widget' => $page['widget'],
            'groupMeta' => $page['groupMeta'],
            'itemsBaseUrl' => route('subscriber.wb.profitability.items'),
            'exportStartUrl' => route('subscriber.wb.profitability.export.start'),
            'exportStatusUrl' => route('subscriber.wb.profitability.export.status'),
            'exportDownloadUrl' => route('subscriber.wb.profitability.export.download'),
        ]);
    }

    public function items(Request $request): JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $payload = $this->reportService->getItemsPage(
            (int) $cabinet->id,
            (int) auth()->id(),
            $request
        );

        return response()->json($payload);
    }

    public function exportStart(Request $request): JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $result = $this->reportService->startExport(
            (int) $cabinet->id,
            (int) auth()->id()
        );

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    public function exportStatus(Request $request): JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        return response()->json(
            $this->reportService->exportStatus((int) $cabinet->id, (int) auth()->id())
        );
    }

    public function exportDownload(Request $request): StreamedResponse|JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $file = $this->reportService->resolveExportDownload(
            (int) $cabinet->id,
            (int) auth()->id()
        );

        if ($file === null) {
            return response()->json([
                'success' => false,
                'message' => 'Файл ещё не готов',
            ], 409);
        }

        $disk = $file['disk'] ?? 'private';

        return Storage::disk($disk)->download($file['path'], $file['filename']);
    }

    public function store(StoreProfitabilityReportRequest $request): RedirectResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinet($request, 'Рентабельность Wildberries', [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => 'Рентабельность Wildberries'],
        ]);
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $response = $this->reportService->store(
            $this->apiRequestWith($request, array_merge(
                $request->validated(),
                ['cabinet_id' => $cabinet->id]
            ))
        );
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()
                ->withInput()
                ->with('error', $this->apiMessage($payload, 'Не удалось запустить формирование отчёта'));
        }

        return back()->with('success', $this->apiMessage($payload, 'Обновление поставлено в очередь'));
    }
}
