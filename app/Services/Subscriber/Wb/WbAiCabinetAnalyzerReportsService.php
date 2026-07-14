<?php

namespace App\Services\Subscriber\Wb;
use App\Jobs\Wb\AiCabinetAnalyzer\ProcessAiCabinetAnalyzerReport;
use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerCabinet;
use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerReport;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WbAiCabinetAnalyzerReportsService
{
    public function start(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cabinet_id' => 'required|integer|exists:wb_ai_cabinet_analyzer_cabinets,id',
            'begin_date' => 'nullable|date|required_with:end_date',
            'end_date' => 'nullable|date|required_with:begin_date|after_or_equal:begin_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $cabinet = AiCabinetAnalyzerCabinet::find((int) $request->cabinet_id);
        if (!$cabinet || (int) $cabinet->user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'messages' => ['Такого кабинета нет'],
            ], 200);
        }

        $beginDate = (string) $request->input('begin_date', Carbon::now()->subMonthNoOverflow()->startOfMonth()->toDateString());
        $endDate = (string) $request->input('end_date', Carbon::now()->subMonthNoOverflow()->endOfMonth()->toDateString());
        $defaultsApplied = !$request->filled('begin_date') && !$request->filled('end_date');

        $report = DB::transaction(function () use ($cabinet, $beginDate, $endDate, $defaultsApplied): AiCabinetAnalyzerReport {
            return AiCabinetAnalyzerReport::create([
                'cabinet_id' => (int) $cabinet->id,
                'status' => AiCabinetAnalyzerReport::STATUS_PROCESSING,
                'type' => 'ads_snapshot',
                'result_json' => [
                    'meta' => [
                        'generated_at' => null,
                        'period' => [
                            'begin_date' => $beginDate,
                            'end_date' => $endDate,
                        ],
                        'defaults_applied' => $defaultsApplied,
                        'warnings' => [],
                    ],
                    'campaigns' => [],
                    'items' => [],
                ],
            ]);
        });

        ProcessAiCabinetAnalyzerReport::dispatch((int) $report->id, (int) $request->user()->id)
            ->onQueue('wb_ai_cabinet_analyzer');

        return response()->json([
            'success' => true,
            'messages' => ['Анализ запущен'],
            'data' => [
                'report_id' => (int) $report->id,
                'status' => $report->status,
            ],
        ], 200);
    }

    public function status(Request $request, string $report)
    {
        $validator = Validator::make(['report' => $report], [
            'report' => 'required|integer|exists:wb_ai_cabinet_analyzer_reports,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $entry = AiCabinetAnalyzerReport::with('cabinet')->find((int) $report);
        if (!$entry || !$entry->cabinet || (int) $entry->cabinet->user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'messages' => ['Отчёт не найден'],
            ], 200);
        }

        return response()->json([
            'success' => true,
            'messages' => ['Статус отчёта'],
            'data' => [
                'id' => (int) $entry->id,
                'status' => (string) $entry->status,
                'error' => data_get($entry->result_json, 'meta.error'),
                'updated_at' => $entry->updated_at,
            ],
        ], 200);
    }

    public function show(Request $request, string $report)
    {
        $validator = Validator::make(['report' => $report], [
            'report' => 'required|integer|exists:wb_ai_cabinet_analyzer_reports,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $entry = AiCabinetAnalyzerReport::with('cabinet')->find((int) $report);
        if (!$entry || !$entry->cabinet || (int) $entry->cabinet->user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'messages' => ['Отчёт не найден'],
            ], 200);
        }

        return response()->json([
            'success' => true,
            'messages' => ['Данные отчёта'],
            'data' => [
                'id' => (int) $entry->id,
                'cabinet_id' => (int) $entry->cabinet_id,
                'status' => (string) $entry->status,
                'type' => (string) ($entry->type ?? ''),
                'result_json' => $entry->result_json,
                'created_at' => $entry->created_at,
                'updated_at' => $entry->updated_at,
            ],
        ], 200);
    }

    public function nomenclatures(Request $request, string $report)
    {
        $validator = Validator::make(
            array_merge($request->all(), ['report' => $report]),
            [
                'report' => 'required|integer|exists:wb_ai_cabinet_analyzer_reports,id',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:200',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $entry = $this->findAccessibleReport($request, (int) $report);
        if (!$entry) {
            return response()->json([
                'success' => false,
                'messages' => ['Отчёт не найден'],
            ], 200);
        }

        $items = (array) data_get($entry->result_json, 'items', []);
        $paginator = $this->paginateItems($items, $request);

        return response()->json([
            'success' => true,
            'messages' => ['Список номенклатур отчёта'],
            'data' => [
                'report_id' => (int) $entry->id,
                'meta' => data_get($entry->result_json, 'meta', []),
                'items' => $paginator,
            ],
        ], 200);
    }

    public function searchNomenclatures(Request $request, string $report)
    {
        $validator = Validator::make(
            array_merge($request->all(), ['report' => $report]),
            [
                'report' => 'required|integer|exists:wb_ai_cabinet_analyzer_reports,id',
                'nmid' => 'nullable|integer|min:1',
                'advert_id' => 'nullable|integer|min:1',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:200',
            ]
        );

        $validator->after(function ($validator) use ($request): void {
            if (!$request->filled('nmid') && !$request->filled('advert_id')) {
                $validator->errors()->add('nmid', 'Укажите nmid или advert_id для поиска');
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $entry = $this->findAccessibleReport($request, (int) $report);
        if (!$entry) {
            return response()->json([
                'success' => false,
                'messages' => ['Отчёт не найден'],
            ], 200);
        }

        $nmid = $request->filled('nmid') ? (int) $request->input('nmid') : null;
        $advertId = $request->filled('advert_id') ? (int) $request->input('advert_id') : null;

        $filteredItems = array_values(array_filter(
            (array) data_get($entry->result_json, 'items', []),
            static function (array $item) use ($nmid, $advertId): bool {
                if ($nmid !== null && (int) ($item['nmid'] ?? 0) !== $nmid) {
                    return false;
                }

                if ($advertId !== null) {
                    $advertIds = array_map('intval', (array) ($item['advert_ids'] ?? []));
                    if (!in_array($advertId, $advertIds, true)) {
                        return false;
                    }
                }

                return true;
            }
        ));

        $paginator = $this->paginateItems($filteredItems, $request);

        return response()->json([
            'success' => true,
            'messages' => ['Результаты поиска номенклатур'],
            'data' => [
                'report_id' => (int) $entry->id,
                'filters' => [
                    'nmid' => $nmid,
                    'advert_id' => $advertId,
                ],
                'items' => $paginator,
            ],
        ], 200);
    }

    public function latestByCabinet(Request $request, string $cabinetId)
    {
        $validator = Validator::make(['cabinet_id' => $cabinetId], [
            'cabinet_id' => 'required|integer|exists:wb_ai_cabinet_analyzer_cabinets,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $cabinet = AiCabinetAnalyzerCabinet::find((int) $cabinetId);
        if (!$cabinet || (int) $cabinet->user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'messages' => ['Такого кабинета нет'],
            ], 200);
        }

        $entry = AiCabinetAnalyzerReport::where('cabinet_id', (int) $cabinet->id)
            ->where('status', AiCabinetAnalyzerReport::STATUS_DONE)
            ->orderByDesc('id')
            ->first();

        if (!$entry) {
            return response()->json([
                'success' => false,
                'messages' => ['Актуальный анализ не найден'],
                'data' => null,
            ], 200);
        }

        return response()->json([
            'success' => true,
            'messages' => ['Данные последнего актуального отчёта'],
            'data' => [
                'id' => (int) $entry->id,
                'cabinet_id' => (int) $entry->cabinet_id,
                'status' => (string) $entry->status,
                'type' => (string) ($entry->type ?? ''),
                'result_json' => $entry->result_json,
                'created_at' => $entry->created_at,
                'updated_at' => $entry->updated_at,
            ],
        ], 200);
    }

    private function findAccessibleReport(Request $request, int $reportId): ?AiCabinetAnalyzerReport
    {
        $entry = AiCabinetAnalyzerReport::with('cabinet')->find($reportId);
        if (!$entry || !$entry->cabinet || (int) $entry->cabinet->user_id !== (int) $request->user()->id) {
            return null;
        }

        return $entry;
    }

    private function paginateItems(array $items, Request $request): LengthAwarePaginator
    {
        $perPage = max(1, min(200, (int) $request->input('per_page', 25)));
        $page = max(1, (int) $request->input('page', 1));
        $total = count($items);
        $offset = ($page - 1) * $perPage;

        $pageItems = array_values(array_slice($items, $offset, $perPage));

        return new LengthAwarePaginator(
            $pageItems,
            $total,
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );
    }
}
