<?php

namespace App\Http\Controllers\Api\Subscriber\Wb\Profitability;

use App\Models\JobStatus;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use App\Http\Traits\WBApiTrait;
use App\Http\Controllers\Controller;
use Maatwebsite\Excel\Facades\Excel;
use App\Jobs\ProcessProfitabilityReport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use App\Exports\ProfitabilityReportExport;
use App\Models\Subscribers\Wb\Profitability\Report;
use App\Models\Subscribers\Wb\Profitability\ProfitabilityCabinet;


class ProfitabilityController extends Controller
{

    use WBApiTrait;

    /**
     * Сохранить новый отчёт
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cabinet_id' => 'required|exists:wb_profitability_cabinets,id',
            'date_from'  => 'required|date',
            'date_to'    => 'required|date|after_or_equal:date_from',
            'dop_rashod' => 'nullable|numeric|min:0',
            'nalog_percent' => 'nullable|numeric|min:0|max:100',
        ], [
            'cabinet_id.exists' => 'Такого кабинета не существует',
            'required' => 'Не указаны необходимые параметры'
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $cabinet = ProfitabilityCabinet::findOrFail($request->cabinet_id);

        ProcessProfitabilityReport::dispatch(
            $cabinet->id,
            $request->date_from,
            $request->date_to,
            $request->user()->id,
            (float) $request->input('dop_rashod', 0),
            (float) $request->input('nalog_percent', 0)
        )->onQueue('profitability');

        return response()->json([
            'success'  => true,
            'messages' => ['Обновление поставлено в очередь'],
        ], 200);
    }


    /**
     * Показать конкретный отчёт по кабинету
     *
     */
    public function show(Request $request, $cabinetId)
    {

        $cabinet = ProfitabilityCabinet::find($cabinetId);
        if (!$cabinet) {
            return response()->json(["success" => false, "messages" => ["Данных нет"]], 200);
        }

        if ($cabinet->user_id !== auth()->user()->id) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);
        }

        // Получаем таймштамп обновления отчёта для генерации ключа кеша
        $reportMeta = Report::where('cabinet_id', $cabinet->id)->select('updated_at')->first();
        $timestamp = $reportMeta ? $reportMeta->updated_at->timestamp : 'empty';

        // Кешируем данные отчёта. Ключ зависит от времени обновления записи в БД.
        $cacheKey = "profitability_report_{$cabinet->id}_{$timestamp}";

        $data = Cache::remember($cacheKey, 604800, function () use ($cabinet) {
            $report = Report::where('cabinet_id', $cabinet->id)->first();

            if (!$report) {
                return null;
            }

            $reportData = $report->toArray();
            unset($reportData['items']);

            $lastRecord = JobStatus::where('job_name', ProcessProfitabilityReport::class)
                ->where('data->cabinet_id', $cabinet->id)
                ->latest()
                ->first();

            $status = 'done';
            $error = null;
            if ($lastRecord) {
                $status = $lastRecord->status;
                $error = $lastRecord->error;
            }

            return [
                'status' => $status,
                'error'  => $error,
                'report' => $reportData,
                'items'  => $this->loadGroupedReportItems($report),
            ];
        });

        if (
            is_array($data)
            && ($data['report']['sales_quantity'] ?? 0) > 0
            && $this->groupedItemsAreEmpty($data['items'] ?? null)
        ) {
            $report = Report::where('cabinet_id', $cabinet->id)->first();
            if ($report) {
                $data['items'] = $this->loadGroupedReportItems($report);
                Cache::put($cacheKey, $data, 604800);
            }
        }

        if (!$data) {
            return response()->json(["success" => true, "messages" => ["Данных нет"], "data" => null], 200)
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, post-check=0, pre-check=0')
                ->header('Pragma', 'no-cache');
        }

        $lastRecord = JobStatus::where('job_name', ProcessProfitabilityReport::class)
            ->where('data->cabinet_id', $cabinet->id)
            ->latest()
            ->first();

        if ($lastRecord) {
            $data = array_merge($data, $this->formatJobStatusPayload($lastRecord));
        }

        return response()->json(["success" => true, "messages" => ["Список сохранённых отчётов"], "data" => $data], 200)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, post-check=0, pre-check=0')
            ->header('Pragma', 'no-cache');
    }

    public function status(Request $request, $cabinetId)
    {

        $cabinet = ProfitabilityCabinet::find($cabinetId);
        if (!$cabinet) {
            return response()->json(["success" => false, "messages" => ["Данных нет"]], 200);
        }

        $lastRecord = JobStatus::where('job_name', ProcessProfitabilityReport::class)
            ->where('data->cabinet_id', $cabinetId)  // JSON-поиск по ключу cabinet_id
            ->latest()
            ->first();

        if ($lastRecord) {
            return response()->json([
                'success' => true,
                'messages' => ['Статус обработки'],
                'data' => $this->formatJobStatusPayload($lastRecord),
            ], 200);
        }
        return response()->json(["success" => false, "messages" => ["Попробуйте позже"]], 200);
    }

    /**
     * @return list<array{supplier_oper_name: string, items: list<array<string, mixed>>}>
     */
    private function loadGroupedReportItems(Report $report): array
    {
        $items = $report->items()
            ->select([
                'nm_id',
                'sa_name',
                'size',
                'barcode',
                'warehouse',
                'reasoning',
                'quantity',
                'sum_to_transfer',
                'purchase_cost',
                'logistics',
                'cost_adjustments',
                'dop_rashod',
                'cashback',
                'nalog',
                'margin',
                'profitability_percent',
                'supplier_oper_name',
            ])
            ->get();

        return $items
            ->groupBy('supplier_oper_name')
            ->map(fn ($groupItems, $key) => [
                'supplier_oper_name' => (string) $key,
                'items' => $groupItems->map(fn ($item) => [
                    'nm_id'                 => $item->nm_id,
                    'sa_name'               => $item->sa_name,
                    'size'                  => $item->size,
                    'barcode'               => $item->barcode,
                    'warehouse'             => $item->warehouse,
                    'reasoning'             => $item->reasoning,
                    'quantity'              => $item->quantity,
                    'sum_to_transfer'       => $item->sum_to_transfer,
                    'purchase_cost'         => $item->purchase_cost,
                    'logistics'             => $item->logistics,
                    'cost_adjustments'      => $item->cost_adjustments,
                    'dop_rashod'            => $item->dop_rashod,
                    'cashback'              => $item->cashback,
                    'nalog'                 => $item->nalog,
                    'margin'                => $item->margin,
                    'profitability_percent' => $item->profitability_percent,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    private function groupedItemsAreEmpty(mixed $groups): bool
    {
        if (! is_array($groups) || $groups === []) {
            return true;
        }

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $items = $group['items'] ?? null;
            if (is_array($items) && $items !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatJobStatusPayload(JobStatus $record): array
    {
        $data = $record->data ?? [];

        return [
            'status' => $record->status,
            'error' => $record->error,
            'stage' => $data['stage'] ?? null,
            'batch' => isset($data['batch']) ? (int) $data['batch'] : null,
            'rows_loaded' => isset($data['rows_loaded']) ? (int) $data['rows_loaded'] : null,
            'waiting_for_api' => (bool) ($data['waiting_for_api'] ?? false),
            'started_at' => $data['started_at'] ?? null,
        ];
    }


    public function exportXlsx(Request $request, ProfitabilityCabinet $cabinet)
    {
        if ($cabinet->user_id !== auth()->user()->id) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);
        }

        $report = Report::with('items')
            ->where('cabinet_id', $cabinet->id)
            ->firstOrFail();

        $reportData = $report->toArray();
        unset($reportData['items']);


        $groupedItems = $report->items
            ->groupBy('supplier_oper_name')
            ->map(function ($items, $key) {
                return [
                    'supplier_oper_name' => $key,
                    'items' => $items->map(fn($item) => [
                        'nm_id'                 => $item->nm_id,
                        'sa_name'               => $item->sa_name,
                        'size'                  => $item->size,
                        'barcode'               => $item->barcode,
                        'warehouse'             => $item->warehouse,
                        'reasoning'             => $item->reasoning,
                        'quantity'              => $item->quantity,
                        'sum_to_transfer'       => $item->sum_to_transfer,
                        'purchase_cost'         => $item->purchase_cost,
                        'logistics'             => $item->logistics,
                        'item_total'            => $item->item_total,
                        'cost_adjustments'      => $item->cost_adjustments,
                        'dop_rashod'            => $item->dop_rashod,
                        'cashback'              => $item->cashback,
                        'nalog'                 => $item->nalog,
                        'margin'                => $item->margin,
                        'profitability_percent' => $item->profitability_percent,
                        'reasoning'             => $item->reasoning,
                    ])->values(),
                ];
            })
            ->sortBy(function ($group) {
                $priorities = [
                    'Продажа' => 1,
                    'Возврат' => 2,
                    'Логистика' => 3,
                ];

                return $priorities[$group['supplier_oper_name']] ?? 999;
            })
            ->values();

        $fileName = "Расчёт_рентабельности_{$cabinet->name}_за_{$reportData['date_from']}_по_{$reportData['date_to']}.xlsx";

        return Excel::download(new ProfitabilityReportExport($reportData, $groupedItems), $fileName);
    }

    public function widget(Request $request, $cabinetId)
    {

        $cabinet = ProfitabilityCabinet::find($cabinetId);

        if (!$cabinet || $cabinet->user_id !== auth()->user()->id) {
            return response()->json([
                'success'  => false,
                'messages' => ['Такого кабинета нет'],
                'data'     => null,
            ], 200);
        }

        // Кешируем на 7 дней, сбрасывается при обновлении отчёта
        $cacheKey = "profitability_widget_{$cabinet->id}";

        $widgetData = Cache::remember($cacheKey, 604800, function () use ($cabinet) {
            $report = Report::where('cabinet_id', $cabinet->id)
                ->latest('updated_at')
                ->first();

            if (!$report) {
                return null;
            }

            $widgetData = Arr::only($report->toArray(), [
                'date_from',
                'date_to',
                'sales_quantity',
                'sales_amount',
                'returns_quantity',
                'returns_amount',
                'percent_buy',
                'penalties',
                'logistics',
                'purchase_cost',
                'margin',
                'deduction',
                'storage_fee',
                'acceptance',
                'cashback',
                'dop_rashod',
                'nalog',
                'nalog_percent',
                'correction_sales',
                'total_profitability',
                'itog',
            ]);

            // Загружаем только нужные поля для расчётов
            $items = $report->items()
                ->select(['nm_id', 'sa_name', 'supplier_oper_name', 'quantity', 'margin', 'sum_to_transfer', 'profitability_percent'])
                ->get();

            $products = $items
                ->filter(fn($item) => !empty($item->nm_id))
                ->groupBy('nm_id')
                ->map(function ($group) {
                    $salesRows = $group->filter(fn($item) => strcasecmp($item->supplier_oper_name, 'Продажа') === 0 && is_numeric($item->profitability_percent));
                    $totalQuantity = $salesRows->sum(fn($item) => (int) $item->quantity);
                    $totalMargin = round($group->sum(fn($item) => (float) $item->margin), 2);
                    $totalTransfer = round($group->sum(fn($item) => (float) $item->sum_to_transfer), 2);

                    $weightedProfitability = null;
                    if ($totalQuantity > 0) {
                        $weightedProfitability = $salesRows->sum(function ($item) {
                            return (float) $item->profitability_percent * (int) $item->quantity;
                        }) / $totalQuantity;
                    } elseif ($salesRows->count() > 0) {
                        $weightedProfitability = $salesRows->avg(fn($item) => (float) $item->profitability_percent);
                    }

                    $denominator = (float) $totalTransfer;
                    if ($denominator > 0.00001 || $denominator < -0.00001) {
                        $weightedProfitability = ($totalMargin / $denominator) * 100;
                    }

                    return [
                        'nm_id'                 => $group->first()->nm_id,
                        'sa_name'               => $salesRows->first()->sa_name ?? $group->first()->sa_name,
                        'profitability_percent' => $weightedProfitability !== null ? round($weightedProfitability, 2) : null,
                        'total_margin'          => $totalMargin,
                        'total_transfer'        => round($totalTransfer, 2),
                    ];
                })
                ->filter(fn($product) => $product['total_margin'] !== null);

            $topProfitable = $products
                ->sortByDesc('total_margin')
                ->take(5)
                ->values()
                ->toArray();

            $topLowMargin = $products
                ->sortBy('total_margin')
                ->take(5)
                ->values()
                ->toArray();

            $widgetData['top_profitable_products'] = $topProfitable;
            $widgetData['top_low_margin_products'] = $topLowMargin;

            return $widgetData;
        });

        if (!$widgetData) {
            return response()->json([
                'success'  => true,
                'messages' => ['Данных нет'],
                'data'     => null,
            ], 200);
        }

        // Картинки загружаем отдельно, чтобы не блокировать кеш при ошибках API
        $this->loadProductImages($widgetData['top_profitable_products']);
        $this->loadProductImages($widgetData['top_low_margin_products']);

        return response()->json([
            'success'  => true,
            'messages' => ['Данные для виджета'],
            'data'     => $widgetData,
        ], 200);
    }

    /**
     * Загружает картинки для массива продуктов
     */
    private function loadProductImages(array &$products): void
    {
        foreach ($products as &$product) {
            $cacheKey = "product_image_{$product['nm_id']}";
            $product['image'] = Cache::remember($cacheKey, 86400 * 7, function () use ($product) {
                try {
                    $images = $this->getProductImages(1, $product['nm_id']);
                    return $images[0]['imageS'] ?? null;
                } catch (\Throwable $e) {
                    return null;
                }
            });
        }
    }
}
