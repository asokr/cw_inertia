<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\Profitability;

use App\Http\Controllers\Web\Subscriber\Concerns\EnsuresWbProfitabilityCabinetOwnership;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\StoreProfitabilityReportRequest;
use App\Models\Subscribers\Wb\Profitability\ProfitabilityCabinet;
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
    use EnsuresWbProfitabilityCabinetOwnership;

    public function __construct(
        private readonly WbProfitabilityReportService $reportService,
    ) {
    }

    public function show(Request $request, ProfitabilityCabinet $cabinet): Response
    {
        $this->ensureCabinetOwnership($cabinet);

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
            'itemsBaseUrl' => route('subscriber.wb.profitability.cabinets.items', $cabinet),
            'exportStartUrl' => route('subscriber.wb.profitability.cabinets.export.start', $cabinet),
            'exportStatusUrl' => route('subscriber.wb.profitability.cabinets.export.status', $cabinet),
            'exportDownloadUrl' => route('subscriber.wb.profitability.cabinets.export.download', $cabinet),
        ]);
    }

    public function items(Request $request, ProfitabilityCabinet $cabinet): JsonResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $payload = $this->reportService->getItemsPage(
            (int) $cabinet->id,
            (int) auth()->id(),
            $request
        );

        return response()->json($payload);
    }

    public function exportStart(Request $request, ProfitabilityCabinet $cabinet): JsonResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $result = $this->reportService->startExport(
            (int) $cabinet->id,
            (int) auth()->id()
        );

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    public function exportStatus(Request $request, ProfitabilityCabinet $cabinet): JsonResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        return response()->json(
            $this->reportService->exportStatus((int) $cabinet->id, (int) auth()->id())
        );
    }

    public function exportDownload(Request $request, ProfitabilityCabinet $cabinet): StreamedResponse|JsonResponse
    {
        $this->ensureCabinetOwnership($cabinet);

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

    public function store(StoreProfitabilityReportRequest $request, ProfitabilityCabinet $cabinet): RedirectResponse
    {
        $this->ensureCabinetOwnership($cabinet);

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
