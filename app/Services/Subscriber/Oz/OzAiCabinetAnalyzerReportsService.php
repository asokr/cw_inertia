<?php

namespace App\Services\Subscriber\Oz;

use App\Jobs\Oz\AiCabinetAnalyzer\ProcessOzAiCabinetAnalyzerReport;
use App\Models\Subscribers\Oz\AiCabinetAnalyzer\OzAiCabinetAnalyzerReport;
use App\Models\Subscribers\Oz\OzCabinet;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OzAiCabinetAnalyzerReportsService
{
    /**
     * @param  OzCabinet|null  $cabinet  Кабинет уже выбран в web-контроллере (selected cabinet).
     */
    public function start(Request $request, ?OzCabinet $cabinet = null)
    {
        $validator = Validator::make($request->all(), [
            'cabinet_id' => $cabinet ? 'nullable|integer|exists:oz_cabinets,id' : 'required|integer|exists:oz_cabinets,id',
            'begin_date' => 'nullable|date|required_with:end_date',
            'end_date' => 'nullable|date|required_with:begin_date|after_or_equal:begin_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        if ($cabinet === null) {
            $cabinet = OzCabinet::find((int) $request->input('cabinet_id'));
        }

        if (! $cabinet || (int) $cabinet->user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'messages' => ['Такого кабинета нет'],
            ], 200);
        }

        $beginDate = (string) $request->input('begin_date', Carbon::now()->subMonthNoOverflow()->startOfMonth()->toDateString());
        $endDate = (string) $request->input('end_date', Carbon::now()->subMonthNoOverflow()->endOfMonth()->toDateString());
        $defaultsApplied = ! $request->filled('begin_date') && ! $request->filled('end_date');

        $report = DB::transaction(function () use ($cabinet, $beginDate, $endDate, $defaultsApplied): OzAiCabinetAnalyzerReport {
            return OzAiCabinetAnalyzerReport::create([
                'cabinet_id' => (int) $cabinet->id,
                'status' => OzAiCabinetAnalyzerReport::STATUS_PROCESSING,
                'type' => 'products_snapshot',
                'result_json' => [
                    'meta' => [
                        'generated_at' => null,
                        'period' => [
                            'begin_date' => $beginDate,
                            'end_date' => $endDate,
                        ],
                        'defaults_applied' => $defaultsApplied,
                        'sources_collected' => [],
                        'warnings' => [],
                    ],
                    'products' => [],
                ],
            ]);
        });

        ProcessOzAiCabinetAnalyzerReport::dispatch((int) $report->id, (int) $request->user()->id)
            ->onQueue('oz_ai_cabinet_analyzer');

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
            'report' => 'required|integer|exists:oz_ai_cabinet_analyzer_reports,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $entry = OzAiCabinetAnalyzerReport::with('cabinet')->find((int) $report);
        if (! $entry || ! $entry->cabinet || (int) $entry->cabinet->user_id !== (int) $request->user()->id) {
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
            'report' => 'required|integer|exists:oz_ai_cabinet_analyzer_reports,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $entry = OzAiCabinetAnalyzerReport::with('cabinet')->find((int) $report);
        if (! $entry || ! $entry->cabinet || (int) $entry->cabinet->user_id !== (int) $request->user()->id) {
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

    public function products(Request $request, string $report)
    {
        $validator = Validator::make(
            array_merge($request->all(), ['report' => $report]),
            [
                'report' => 'required|integer|exists:oz_ai_cabinet_analyzer_reports,id',
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
        if (! $entry) {
            return response()->json([
                'success' => false,
                'messages' => ['Отчёт не найден'],
            ], 200);
        }

        $items = (array) data_get($entry->result_json, 'products', []);
        $paginator = $this->paginateItems($items, $request);

        return response()->json([
            'success' => true,
            'messages' => ['Список товаров отчёта'],
            'data' => [
                'report_id' => (int) $entry->id,
                'meta' => data_get($entry->result_json, 'meta', []),
                'items' => $paginator,
            ],
        ], 200);
    }

    public function searchProducts(Request $request, string $report)
    {
        $validator = Validator::make(
            array_merge($request->all(), ['report' => $report]),
            [
                'report' => 'required|integer|exists:oz_ai_cabinet_analyzer_reports,id',
                'product_id' => 'nullable|integer|min:1',
                'offer_id' => 'nullable|string|max:255',
                'q' => 'nullable|string|max:255',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:200',
            ]
        );

        $validator->after(function ($validator) use ($request): void {
            if (! $request->filled('product_id') && ! $request->filled('offer_id') && ! $request->filled('q')) {
                $validator->errors()->add('q', 'Укажите product_id, offer_id или строку поиска');
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $entry = $this->findAccessibleReport($request, (int) $report);
        if (! $entry) {
            return response()->json([
                'success' => false,
                'messages' => ['Отчёт не найден'],
            ], 200);
        }

        $productId = $request->filled('product_id') ? (int) $request->input('product_id') : null;
        $offerId = $request->filled('offer_id') ? trim((string) $request->input('offer_id')) : null;
        $q = $request->filled('q') ? mb_strtolower(trim((string) $request->input('q'))) : null;

        $filteredItems = array_values(array_filter(
            (array) data_get($entry->result_json, 'products', []),
            static function (array $item) use ($productId, $offerId, $q): bool {
                if ($productId !== null && (int) ($item['product_id'] ?? 0) !== $productId) {
                    return false;
                }

                if ($offerId !== null && strcasecmp((string) ($item['offer_id'] ?? ''), $offerId) !== 0) {
                    return false;
                }

                if ($q !== null && $q !== '') {
                    $haystack = mb_strtolower(implode(' ', array_filter([
                        (string) ($item['product_id'] ?? ''),
                        (string) ($item['offer_id'] ?? ''),
                        (string) ($item['sku'] ?? ''),
                        (string) ($item['name'] ?? ''),
                        (string) ($item['brand'] ?? ''),
                    ])));

                    if (! str_contains($haystack, $q)) {
                        return false;
                    }
                }

                return true;
            }
        ));

        $paginator = $this->paginateItems($filteredItems, $request);

        return response()->json([
            'success' => true,
            'messages' => ['Результаты поиска товаров'],
            'data' => [
                'report_id' => (int) $entry->id,
                'filters' => [
                    'product_id' => $productId,
                    'offer_id' => $offerId,
                    'q' => $q,
                ],
                'items' => $paginator,
            ],
        ], 200);
    }

    public function latestByCabinet(Request $request, string $cabinetId)
    {
        $validator = Validator::make(['cabinet_id' => $cabinetId], [
            'cabinet_id' => 'required|integer|exists:oz_cabinets,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $cabinet = OzCabinet::find((int) $cabinetId);
        if (! $cabinet || (int) $cabinet->user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'messages' => ['Такого кабинета нет'],
            ], 200);
        }

        $entry = OzAiCabinetAnalyzerReport::where('cabinet_id', (int) $cabinet->id)
            ->where('status', OzAiCabinetAnalyzerReport::STATUS_DONE)
            ->orderByDesc('id')
            ->first();

        if (! $entry) {
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

    private function findAccessibleReport(Request $request, int $reportId): ?OzAiCabinetAnalyzerReport
    {
        $entry = OzAiCabinetAnalyzerReport::with('cabinet')->find($reportId);
        if (! $entry || ! $entry->cabinet || (int) $entry->cabinet->user_id !== (int) $request->user()->id) {
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
