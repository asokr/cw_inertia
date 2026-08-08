<?php

namespace App\Http\Controllers\Web\Subscriber\Oz\AiCabinetAnalyzer;

use App\Http\Controllers\Web\Subscriber\Concerns\ResolvesSelectedOzCabinet;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\StartAiCabinetAnalyzerReportRequest;
use App\Models\Subscribers\Oz\AiCabinetAnalyzer\OzAiCabinetAnalyzerReport;
use App\Models\Subscribers\Oz\OzCabinet;
use App\Services\Subscriber\Oz\OzAiCabinetAnalyzerAiAnalysesService;
use App\Services\Subscriber\Oz\OzAiCabinetAnalyzerReportsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends SubscriberToolController
{
    use ResolvesSelectedOzCabinet;

    public function __construct(
        private readonly OzAiCabinetAnalyzerReportsService $reportsService,
        private readonly OzAiCabinetAnalyzerAiAnalysesService $aiAnalysesService,
    ) {
    }

    public function show(Request $request): Response
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinet($request, 'ИИ анализ кабинета Ozon', [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => 'ИИ анализ кабинета Ozon'],
        ]);
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var OzCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $this->failStaleProcessingReports($cabinet);

        $reportPayload = $this->resolveReportPayload($request, $cabinet);
        $report = $this->buildReportProp($request, $reportPayload);
        $meta = $this->buildMetaProp($reportPayload);

        $productFilters = [
            'product_id' => (string) $request->input('product_id', ''),
            'offer_id' => (string) $request->input('offer_id', ''),
            'q' => (string) $request->input('q', ''),
            'page' => max(1, (int) $request->input('page', 1)),
            'per_page' => max(1, min(200, (int) $request->input('per_page', 15))),
        ];

        [$products, $productsMeta] = $this->buildProductsProps($request, $report, $productFilters);

        $templates = $this->buildTemplatesProp();
        [$analyses, $analysesMeta] = $this->buildAnalysesProps($request, $report);

        return Inertia::render('Subscriber/Oz/AiCabinetAnalyzer/Cabinet/Show', [
            'cabinet' => [
                'id' => $cabinet->id,
                'name' => $cabinet->name,
            ],
            'report' => $report,
            'meta' => $meta,
            'products' => $products,
            'productsMeta' => $productsMeta,
            'productFilters' => $productFilters,
            'templates' => $templates,
            'analyses' => $analyses,
            'analysesMeta' => $analysesMeta,
            'defaultPeriod' => $this->defaultPeriod(),
        ]);
    }

    public function startReport(StartAiCabinetAnalyzerReportRequest $request): RedirectResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinet($request, 'ИИ анализ кабинета Ozon', [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => 'ИИ анализ кабинета Ozon'],
        ]);
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var OzCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $response = $this->reportsService->start($request, $cabinet);
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()
                ->withInput()
                ->with('error', $this->apiMessage($payload, 'Не удалось запустить сбор данных'));
        }

        $reportId = (int) ($payload['data']['report_id'] ?? 0);

        return redirect()
            ->route('subscriber.oz.ai-cabinet-analyzer.index', [
                'report_id' => $reportId,
            ])
            ->with('success', $this->apiMessage($payload, 'Анализ запущен'));
    }

    private function failStaleProcessingReports(OzCabinet $cabinet): void
    {
        $thresholdMinutes = 70;
        $threshold = now()->subMinutes($thresholdMinutes);

        $stale = OzAiCabinetAnalyzerReport::query()
            ->where('cabinet_id', $cabinet->id)
            ->where('status', OzAiCabinetAnalyzerReport::STATUS_PROCESSING)
            ->where('updated_at', '<', $threshold)
            ->get();

        foreach ($stale as $report) {
            $payload = is_array($report->result_json) ? $report->result_json : [];
            data_set(
                $payload,
                'meta.error',
                "Сбор данных прерван: отчёт слишком долго был в обработке (более {$thresholdMinutes} мин). Запустите сбор заново."
            );
            $report->status = OzAiCabinetAnalyzerReport::STATUS_FAILED;
            $report->result_json = $payload;
            $report->save();
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveReportPayload(Request $request, OzCabinet $cabinet): ?array
    {
        $reportId = $request->integer('report_id');

        if ($reportId > 0) {
            $report = OzAiCabinetAnalyzerReport::query()
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

        $processing = OzAiCabinetAnalyzerReport::query()
            ->where('cabinet_id', $cabinet->id)
            ->where('status', OzAiCabinetAnalyzerReport::STATUS_PROCESSING)
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

        $fallback = OzAiCabinetAnalyzerReport::query()
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

        if ($status === OzAiCabinetAnalyzerReport::STATUS_PROCESSING && $reportId > 0) {
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
    private function buildProductsProps(Request $request, ?array $report, array $filters): array
    {
        $emptyMeta = [
            'current_page' => $filters['page'],
            'per_page' => $filters['per_page'],
            'total' => 0,
            'last_page' => 1,
        ];

        if ($report === null || ($report['status'] ?? '') !== OzAiCabinetAnalyzerReport::STATUS_DONE) {
            return [[], $emptyMeta];
        }

        $reportId = (int) $report['id'];
        $hasSearch = trim((string) $filters['product_id']) !== ''
            || trim((string) $filters['offer_id']) !== ''
            || trim((string) $filters['q']) !== '';

        $query = array_filter([
            'page' => $filters['page'],
            'per_page' => $filters['per_page'],
            'product_id' => trim((string) $filters['product_id']) !== '' ? (int) $filters['product_id'] : null,
            'offer_id' => trim((string) $filters['offer_id']) !== '' ? trim((string) $filters['offer_id']) : null,
            'q' => trim((string) $filters['q']) !== '' ? trim((string) $filters['q']) : null,
        ], static fn ($value) => $value !== null && $value !== '');

        $subRequest = $request->duplicate($query);

        $response = $hasSearch
            ? $this->reportsService->searchProducts($subRequest, (string) $reportId)
            : $this->reportsService->products($subRequest, (string) $reportId);

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
                'data_sources' => array_values(array_filter(
                    (array) ($row['data_sources'] ?? ['products']),
                    static fn ($source) => in_array((string) $source, ['products'], true)
                )),
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

        $analysesPage = max(1, (int) $request->input('analyses_page', 1));

        $payload = $this->decodeApiResponse(
            $this->aiAnalysesService->indexByReport(
                $request->duplicate(array_merge($request->query(), [
                    'per_page' => 15,
                    'page' => $analysesPage,
                ])),
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

        return [
            'begin_date' => $now->copy()->startOfMonth()->toDateString(),
            'end_date' => $now->toDateString(),
        ];
    }
}
