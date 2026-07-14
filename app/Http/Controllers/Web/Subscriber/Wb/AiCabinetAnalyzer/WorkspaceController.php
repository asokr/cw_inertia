<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\AiCabinetAnalyzer;

use App\Http\Controllers\Web\Subscriber\Concerns\EnsuresAiCabinetAnalyzerOwnership;
use App\Services\Subscriber\Wb\WbAiCabinetAnalyzerAiAnalysesService;
use App\Services\Subscriber\Wb\WbAiCabinetAnalyzerReportsService;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\StartAiCabinetAnalyzerReportRequest;
use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerCabinet;
use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends SubscriberToolController
{
    use EnsuresAiCabinetAnalyzerOwnership;

    public function __construct(
        private readonly WbAiCabinetAnalyzerReportsService $reportsService,
        private readonly WbAiCabinetAnalyzerAiAnalysesService $aiAnalysesService,
    ) {
    }

    public function show(Request $request, AiCabinetAnalyzerCabinet $cabinet): Response
    {
        $this->ensureCabinetOwnership($cabinet);

        $reportPayload = $this->resolveReportPayload($request, $cabinet);
        $report = $this->buildReportProp($request, $reportPayload);
        $meta = $this->buildMetaProp($reportPayload);

        $nomenclatureFilters = [
            'nmid' => (string) $request->input('nmid', ''),
            'advert_id' => (string) $request->input('advert_id', ''),
            'page' => max(1, (int) $request->input('page', 1)),
            'per_page' => max(1, min(200, (int) $request->input('per_page', 15))),
        ];

        [$nomenclatures, $nomenclaturesMeta] = $this->buildNomenclaturesProps(
            $request,
            $report,
            $nomenclatureFilters
        );

        $templates = $this->buildTemplatesProp();
        [$analyses, $analysesMeta] = $this->buildAnalysesProps($request, $report);

        return Inertia::render('Subscriber/Wb/AiCabinetAnalyzer/Cabinet/Show', [
            'cabinet' => [
                'id' => $cabinet->id,
                'name' => $cabinet->name,
            ],
            'report' => $report,
            'meta' => $meta,
            'nomenclatures' => $nomenclatures,
            'nomenclaturesMeta' => $nomenclaturesMeta,
            'nomenclatureFilters' => $nomenclatureFilters,
            'templates' => $templates,
            'analyses' => $analyses,
            'analysesMeta' => $analysesMeta,
            'defaultPeriod' => $this->defaultPeriod(),
        ]);
    }

    public function startReport(StartAiCabinetAnalyzerReportRequest $request, AiCabinetAnalyzerCabinet $cabinet): RedirectResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->reportsService->start(
            $request->duplicate(null, array_merge(
                $request->validated(),
                ['cabinet_id' => $cabinet->id]
            ))
        );
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()
                ->withInput()
                ->with('error', $this->apiMessage($payload, 'Не удалось запустить сбор данных'));
        }

        $reportId = (int) ($payload['data']['report_id'] ?? 0);

        return redirect()
            ->route('subscriber.wb.ai-cabinet-analyzer.cabinets.show', [
                'cabinet' => $cabinet->id,
                'report_id' => $reportId,
            ])
            ->with('success', $this->apiMessage($payload, 'Анализ запущен'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveReportPayload(Request $request, AiCabinetAnalyzerCabinet $cabinet): ?array
    {
        $reportId = $request->integer('report_id');

        if ($reportId > 0) {
            $report = AiCabinetAnalyzerReport::query()
                ->where('id', $reportId)
                ->where('cabinet_id', $cabinet->id)
                ->first();

            if (! $report) {
                return null;
            }

            $payload = $this->decodeApiResponse(
                $this->reportsService->show($request, (string) $report->id)
            );

            return ($payload['success'] ?? false) === true ? ($payload['data'] ?? null) : null;
        }

        $processing = AiCabinetAnalyzerReport::query()
            ->where('cabinet_id', $cabinet->id)
            ->where('status', AiCabinetAnalyzerReport::STATUS_PROCESSING)
            ->orderByDesc('id')
            ->first();

        if ($processing) {
            $payload = $this->decodeApiResponse(
                $this->reportsService->show($request, (string) $processing->id)
            );

            if (($payload['success'] ?? false) === true) {
                return $payload['data'] ?? null;
            }
        }

        $latestPayload = $this->decodeApiResponse(
            $this->reportsService->latestByCabinet($request, (string) $cabinet->id)
        );

        if (($latestPayload['success'] ?? false) === true && ($latestPayload['data'] ?? null) !== null) {
            return $latestPayload['data'];
        }

        $fallback = AiCabinetAnalyzerReport::query()
            ->where('cabinet_id', $cabinet->id)
            ->orderByDesc('id')
            ->first();

        if (! $fallback) {
            return null;
        }

        $payload = $this->decodeApiResponse(
            $this->reportsService->show($request, (string) $fallback->id)
        );

        return ($payload['success'] ?? false) === true ? ($payload['data'] ?? null) : null;
    }

    /**
     * @param  array<string, mixed>|null  $reportPayload
     * @return array<string, mixed>|null
     */
    private function buildReportProp(Request $request, ?array $reportPayload): ?array
    {
        if ($reportPayload === null) {
            return null;
        }

        $period = data_get($reportPayload, 'result_json.meta.period', []);
        $reportId = (int) ($reportPayload['id'] ?? 0);
        $status = (string) ($reportPayload['status'] ?? '');
        $error = data_get($reportPayload, 'result_json.meta.error');
        $updatedAt = $reportPayload['updated_at'] ?? null;

        if ($status === AiCabinetAnalyzerReport::STATUS_PROCESSING && $reportId > 0) {
            $statusPayload = $this->decodeApiResponse(
                $this->reportsService->status($request, (string) $reportId)
            );

            if (($statusPayload['success'] ?? false) === true) {
                $status = (string) ($statusPayload['data']['status'] ?? $status);
                $error = $statusPayload['data']['error'] ?? $error;
                $updatedAt = $statusPayload['data']['updated_at'] ?? $updatedAt;
            }
        }

        return [
            'id' => $reportId,
            'status' => $status,
            'error' => $error,
            'updated_at' => $updatedAt,
            'begin_date' => $period['begin_date'] ?? null,
            'end_date' => $period['end_date'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $reportPayload
     * @return array<string, mixed>|null
     */
    private function buildMetaProp(?array $reportPayload): ?array
    {
        if ($reportPayload === null) {
            return null;
        }

        $meta = data_get($reportPayload, 'result_json.meta');

        return is_array($meta) ? $meta : null;
    }

    /**
     * @param  array<string, mixed>|null  $report
     * @param  array<string, mixed>  $filters
     * @return array{0: array<int, mixed>, 1: array<string, mixed>}
     */
    private function buildNomenclaturesProps(Request $request, ?array $report, array $filters): array
    {
        $emptyMeta = [
            'current_page' => $filters['page'],
            'per_page' => $filters['per_page'],
            'total' => 0,
            'last_page' => 1,
        ];

        if ($report === null || ($report['status'] ?? '') !== AiCabinetAnalyzerReport::STATUS_DONE) {
            return [[], $emptyMeta];
        }

        $reportId = (int) $report['id'];
        $hasSearch = trim((string) $filters['nmid']) !== '' || trim((string) $filters['advert_id']) !== '';

        $query = array_filter([
            'page' => $filters['page'],
            'per_page' => $filters['per_page'],
            'nmid' => trim((string) $filters['nmid']) !== '' ? (int) $filters['nmid'] : null,
            'advert_id' => trim((string) $filters['advert_id']) !== '' ? (int) $filters['advert_id'] : null,
        ], static fn ($value) => $value !== null && $value !== '');

        $subRequest = $request->duplicate($query);

        $response = $hasSearch
            ? $this->reportsService->searchNomenclatures($subRequest, (string) $reportId)
            : $this->reportsService->nomenclatures($subRequest, (string) $reportId);

        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return [[], $emptyMeta];
        }

        $itemsPaginator = data_get($payload, 'data.items');

        return [
            is_array($itemsPaginator['data'] ?? null) ? $itemsPaginator['data'] : [],
            [
                'current_page' => (int) ($itemsPaginator['current_page'] ?? $filters['page']),
                'per_page' => (int) ($itemsPaginator['per_page'] ?? $filters['per_page']),
                'total' => (int) ($itemsPaginator['total'] ?? 0),
                'last_page' => (int) ($itemsPaginator['last_page'] ?? 1),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildTemplatesProp(): array
    {
        $payload = $this->decodeApiResponse($this->aiAnalysesService->templates());

        if (($payload['success'] ?? false) !== true) {
            return [];
        }

        return array_values(array_map(static function ($template) {
            $row = is_array($template) ? $template : $template->toArray();

            return [
                'id' => $row['id'],
                'name' => $row['name'],
                'description' => $row['description'] ?? '',
            ];
        }, $payload['data'] ?? []));
    }

    /**
     * @param  array<string, mixed>|null  $report
     * @return array{0: array<int, mixed>, 1: array<string, mixed>}
     */
    private function buildAnalysesProps(Request $request, ?array $report): array
    {
        $emptyMeta = [
            'current_page' => 1,
            'per_page' => 15,
            'total' => 0,
            'last_page' => 1,
        ];

        if ($report === null) {
            return [[], $emptyMeta];
        }

        $payload = $this->decodeApiResponse(
            $this->aiAnalysesService->indexByReport(
                $request->duplicate(['per_page' => 15]),
                (string) $report['id']
            )
        );

        if (($payload['success'] ?? false) !== true) {
            return [[], $emptyMeta];
        }

        $paginator = $payload['data'] ?? [];

        return [
            is_array($paginator['data'] ?? null) ? $paginator['data'] : [],
            [
                'current_page' => (int) ($paginator['current_page'] ?? 1),
                'per_page' => (int) ($paginator['per_page'] ?? 15),
                'total' => (int) ($paginator['total'] ?? 0),
                'last_page' => (int) ($paginator['last_page'] ?? 1),
            ],
        ];
    }

    /**
     * @return array{begin_date: string, end_date: string}
     */
    private function defaultPeriod(): array
    {
        $now = now();
        $begin = $now->copy()->startOfMonth()->toDateString();
        $end = $now->toDateString();

        return [
            'begin_date' => $begin,
            'end_date' => $end,
        ];
    }
}