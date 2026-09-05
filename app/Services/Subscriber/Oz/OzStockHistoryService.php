<?php

namespace App\Services\Subscriber\Oz;

use App\Enums\OzStockHistorySnapshotStatus;
use App\Enums\OzStockHistoryTrackingStatus;
use App\Jobs\Oz\StockHistory\ProcessOzStockHistorySnapshotJob;
use App\Jobs\Oz\StockHistory\ProcessOzStockHistoryStartJob;
use App\Models\Subscribers\Oz\OzCabinet;
use App\Models\Subscribers\Oz\StockHistory\OzStockHistoryItem;
use App\Models\Subscribers\Oz\StockHistory\OzStockHistoryProduct;
use App\Models\Subscribers\Oz\StockHistory\OzStockHistorySetting;
use App\Models\Subscribers\Oz\StockHistory\OzStockHistorySnapshot;
use App\Models\Subscribers\Oz\StockHistory\OzStockHistoryWarehouse;
use App\Services\Oz\StockHistory\OzStockHistorySyncService;
use App\Support\Oz\OzStockHistoryCalendar;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OzStockHistoryService
{
    public const PER_PAGE = 100;

    public function __construct(
        private readonly OzStockHistorySyncService $syncService,
    ) {}

    public function settingsFor(int $cabinetId): OzStockHistorySetting
    {
        return OzStockHistorySetting::query()->firstOrCreate(
            ['cabinet_id' => $cabinetId],
            [
                'retention_days' => OzStockHistorySetting::DEFAULT_RETENTION_DAYS,
                'tracking_enabled' => false,
                'tracking_status' => OzStockHistoryTrackingStatus::Idle,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function trackingPayload(OzStockHistorySetting $settings, ?OzStockHistorySnapshot $lastSnapshot = null): array
    {
        $lastSnapshot ??= OzStockHistorySnapshot::query()
            ->where('cabinet_id', $settings->cabinet_id)
            ->where('status', OzStockHistorySnapshotStatus::Done)
            ->orderByDesc('stock_date')
            ->first();

        $hasHistory = OzStockHistoryItem::query()
            ->where('cabinet_id', $settings->cabinet_id)
            ->exists();

        $range = $this->availableDateRange((int) $settings->cabinet_id);

        return [
            'tracking_enabled' => (bool) $settings->tracking_enabled,
            'tracking_status' => $settings->tracking_status?->value ?? OzStockHistoryTrackingStatus::Idle->value,
            'is_loading' => $settings->tracking_status?->isLoading() ?? false,
            'retention_days' => (int) $settings->retention_days,
            'products_count' => (int) $settings->products_count,
            'products_synced_at' => $settings->products_synced_at?->timezone(OzStockHistoryCalendar::TIMEZONE)->format('d.m.Y H:i'),
            'last_error' => $settings->last_error,
            'last_stock_date' => $lastSnapshot?->stock_date,
            'last_stock_date_label' => $lastSnapshot?->stock_date
                ? Carbon::parse($lastSnapshot->stock_date)->locale('ru')->translatedFormat('j F')
                : null,
            'has_history' => $hasHistory,
            'available_from' => $range['from'],
            'available_to' => $range['to'],
            'min_retention_days' => OzStockHistorySetting::MIN_RETENTION_DAYS,
            'max_retention_days' => OzStockHistorySetting::MAX_RETENTION_DAYS,
        ];
    }

    /**
     * @return array{success: bool, messages: list<string>}
     */
    public function startTracking(OzCabinet $cabinet): array
    {
        $settings = $this->settingsFor((int) $cabinet->id);

        if ($settings->tracking_status?->isLoading()) {
            return [
                'success' => false,
                'messages' => ['Загрузка уже идёт.'],
            ];
        }

        $settings->tracking_status = OzStockHistoryTrackingStatus::LoadingProducts;
        $settings->tracking_enabled = false;
        $settings->last_error = null;
        $settings->save();

        ProcessOzStockHistoryStartJob::dispatch((int) $cabinet->id);

        return [
            'success' => true,
            'messages' => ['Загружаем товары кабинета.'],
        ];
    }

    /**
     * @return array{success: bool, messages: list<string>}
     */
    public function stopTracking(int $cabinetId): array
    {
        $settings = $this->settingsFor($cabinetId);
        $settings->tracking_enabled = false;
        $settings->tracking_status = OzStockHistoryTrackingStatus::Idle;
        $settings->last_error = null;
        $settings->save();

        return [
            'success' => true,
            'messages' => ['Отслеживание остановлено. Новые дни не добавляются.'],
        ];
    }

    /**
     * @return array{success: bool, messages: list<string>}
     */
    public function updateRetention(int $cabinetId, int $days): array
    {
        $days = max(
            OzStockHistorySetting::MIN_RETENTION_DAYS,
            min(OzStockHistorySetting::MAX_RETENTION_DAYS, $days),
        );

        $settings = $this->settingsFor($cabinetId);
        $settings->retention_days = $days;
        $settings->save();

        $this->syncService->pruneCabinet($cabinetId, $days);

        return [
            'success' => true,
            'messages' => ['Срок хранения обновлён.'],
        ];
    }

    /**
     * @return array{success: bool, messages: list<string>}
     */
    public function retryYesterdaySnapshot(int $cabinetId): array
    {
        $settings = $this->settingsFor($cabinetId);
        if (! $settings->tracking_enabled) {
            return [
                'success' => false,
                'messages' => ['Сначала включите отслеживание.'],
            ];
        }

        if ($settings->tracking_status?->isLoading()) {
            return [
                'success' => false,
                'messages' => ['Загрузка уже идёт.'],
            ];
        }

        ProcessOzStockHistorySnapshotJob::dispatch(
            $cabinetId,
            $this->syncService->yesterdayDate(),
            true,
            false,
        );

        return [
            'success' => true,
            'messages' => ['Обновляем остатки за вчера.'],
        ];
    }

    /**
     * @return array{from: string|null, to: string|null}
     */
    public function availableDateRange(int $cabinetId): array
    {
        $row = OzStockHistoryItem::query()
            ->where('cabinet_id', $cabinetId)
            ->selectRaw('MIN(stock_date) as min_date, MAX(stock_date) as max_date')
            ->first();

        $from = $row?->min_date;
        $to = $row?->max_date;

        return [
            'from' => $from ? Carbon::parse($from)->toDateString() : null,
            'to' => $to ? Carbon::parse($to)->toDateString() : null,
        ];
    }

    /**
     * @return array{from: string, to: string, dates: list<string>}
     */
    public function resolvePeriod(int $cabinetId, OzStockHistorySetting $settings, Request $request): array
    {
        $range = $this->availableDateRange($cabinetId);
        $retentionFrom = OzStockHistoryCalendar::today()
            ->subDays((int) $settings->retention_days - 1)
            ->toDateString();

        $availableTo = $range['to'] ?? OzStockHistoryCalendar::yesterdayDate();
        $availableFrom = $range['from'] ?? $retentionFrom;
        if ($availableFrom < $retentionFrom) {
            $availableFrom = $retentionFrom;
        }

        $to = $this->normalizeDate((string) $request->input('to', $availableTo), $availableTo);
        $from = $this->normalizeDate((string) $request->input('from', $availableFrom), $availableFrom);
        if ($from < $availableFrom) {
            $from = $availableFrom;
        }
        if ($from > $to) {
            $from = $to;
        }

        return [
            'from' => $from,
            'to' => $to,
            'dates' => $this->dateList($from, $to),
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listProducts(int $cabinetId, Request $request, array $period): array
    {
        $search = trim((string) $request->input('search', ''));
        $page = max(1, (int) $request->input('page', 1));
        $from = $period['from'];
        $to = $period['to'];
        $dates = $period['dates'];

        $visibleSkus = OzStockHistoryItem::query()
            ->where('cabinet_id', $cabinetId)
            ->where('stock_date', '>=', $from)
            ->where('stock_date', '<=', $to)
            ->groupBy('sku')
            ->havingRaw('MAX(qty) > 0')
            ->pluck('sku')
            ->map(static fn ($sku): int => (int) $sku)
            ->all();

        $query = OzStockHistoryProduct::query()
            ->where('cabinet_id', $cabinetId)
            ->whereIn('sku', $visibleSkus === [] ? [0] : $visibleSkus);

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($inner) use ($like): void {
                $inner->where('offer_id', 'like', $like)
                    ->orWhere('name', 'like', $like);
            });
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query
            ->orderByRaw('name is null')
            ->orderBy('name')
            ->orderBy('offer_id')
            ->paginate(self::PER_PAGE, ['*'], 'page', $page)
            ->withQueryString();

        $skus = collect($paginator->items())->map(fn ($p) => (int) $p->sku)->all();
        $seriesBySku = $this->totalsBySku($cabinetId, $skus, $from, $to);
        $stockoutSkus = array_fill_keys($this->stockoutSkuList($cabinetId, $from, $to, $skus), true);

        $items = [];
        foreach ($paginator->items() as $product) {
            $sku = (int) $product->sku;
            $totals = $seriesBySku[$sku] ?? [];
            $values = [];
            foreach ($dates as $date) {
                $values[] = array_key_exists($date, $totals) ? (int) $totals[$date] : null;
            }
            $lastQty = 0;
            foreach (array_reverse($dates) as $date) {
                if (array_key_exists($date, $totals)) {
                    $lastQty = (int) $totals[$date];
                    break;
                }
            }

            $items[] = [
                'sku' => $sku,
                'offer_id' => $product->offer_id,
                'name' => $product->name ?: ($product->offer_id ?: 'Товар'),
                'image_url' => $product->image_url,
                'qty' => $lastQty,
                'stockout' => isset($stockoutSkus[$sku]),
                'series' => $values,
            ];
        }

        return [
            'items' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @param  list<string>  $dates
     * @return array<string, mixed>|null
     */
    public function productHistory(int $cabinetId, int $sku, array $dates, string $from, string $to): ?array
    {
        $product = OzStockHistoryProduct::query()
            ->where('cabinet_id', $cabinetId)
            ->where('sku', $sku)
            ->first();

        if (! $product) {
            return null;
        }

        $hadStock = OzStockHistoryItem::query()
            ->where('cabinet_id', $cabinetId)
            ->where('sku', $sku)
            ->where('qty', '>', 0)
            ->exists();

        if (! $hadStock) {
            return null;
        }

        $warehouses = OzStockHistoryWarehouse::query()
            ->where('cabinet_id', $cabinetId)
            ->get()
            ->keyBy('warehouse_key');

        $everKeys = OzStockHistoryItem::query()
            ->where('cabinet_id', $cabinetId)
            ->where('sku', $sku)
            ->where('qty', '>', 0)
            ->distinct()
            ->pluck('warehouse_key')
            ->all();

        $items = OzStockHistoryItem::query()
            ->where('cabinet_id', $cabinetId)
            ->where('sku', $sku)
            ->where('stock_date', '>=', $from)
            ->where('stock_date', '<=', $to)
            ->get();

        $byWarehouse = [];
        foreach ($items as $item) {
            $key = (string) $item->warehouse_key;
            $date = Carbon::parse($item->stock_date)->toDateString();
            $byWarehouse[$key][$date] = (int) $item->qty;
        }

        $clusters = [];
        foreach ($everKeys as $warehouseKey) {
            $warehouse = $warehouses->get($warehouseKey);
            $clusterName = $warehouse?->cluster_name ?: 'Другие склады';
            $qtyByDate = $byWarehouse[$warehouseKey] ?? [];
            $values = [];
            $lastQty = 0;
            $zeroSince = null;
            foreach ($dates as $date) {
                $value = array_key_exists($date, $qtyByDate) ? (int) $qtyByDate[$date] : null;
                $values[] = $value;
                if ($value !== null) {
                    $lastQty = $value;
                    $zeroSince = $value === 0 ? ($zeroSince ?? $date) : null;
                }
            }
            $lastDate = $dates !== [] ? $dates[array_key_last($dates)] : null;
            if ($lastDate !== null && array_key_exists($lastDate, $qtyByDate)) {
                $lastQty = (int) $qtyByDate[$lastDate];
            }

            $clusters[$clusterName][] = [
                'warehouse_key' => $warehouseKey,
                'warehouse_name' => $warehouse?->warehouse_name ?: $warehouseKey,
                'qty' => $lastQty,
                'series' => $values,
                'empty_since' => $zeroSince,
                'empty_since_label' => $zeroSince
                    ? Carbon::parse($zeroSince)->locale('ru')->translatedFormat('j M')
                    : null,
            ];
        }

        $clusterItems = [];
        foreach ($clusters as $name => $warehousesList) {
            usort($warehousesList, static fn (array $a, array $b): int => strcmp($a['warehouse_name'], $b['warehouse_name']));
            $clusterItems[] = [
                'name' => $name,
                'qty' => $this->sumWarehouseQty($warehousesList),
                'series' => $this->sumWarehouseSeries($warehousesList, count($dates)),
                'warehouses' => $warehousesList,
            ];
        }

        usort($clusterItems, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return [
            'sku' => $sku,
            'offer_id' => $product->offer_id,
            'name' => $product->name ?: ($product->offer_id ?: 'Товар'),
            'image_url' => $product->image_url,
            'clusters' => $clusterItems,
        ];
    }

    /**
     * @param  list<array{qty: int}>  $warehouses
     */
    private function sumWarehouseQty(array $warehouses): int
    {
        $total = 0;
        foreach ($warehouses as $warehouse) {
            $total += (int) ($warehouse['qty'] ?? 0);
        }

        return $total;
    }

    /**
     * @param  list<array{series: list<int|null>}>  $warehouses
     * @return list<int|null>
     */
    private function sumWarehouseSeries(array $warehouses, int $days): array
    {
        $series = array_fill(0, max(0, $days), null);

        foreach ($warehouses as $warehouse) {
            foreach (($warehouse['series'] ?? []) as $index => $value) {
                if (! array_key_exists($index, $series) || $value === null) {
                    continue;
                }
                $series[$index] = (int) $series[$index] + (int) $value;
            }
        }

        return $series;
    }

    /**
     * @param  list<int>  $skus
     * @return array<int, array<string, int>>
     */
    private function totalsBySku(int $cabinetId, array $skus, string $from, string $to): array
    {
        if ($skus === []) {
            return [];
        }

        $rows = OzStockHistoryItem::query()
            ->where('cabinet_id', $cabinetId)
            ->whereIn('sku', $skus)
            ->where('stock_date', '>=', $from)
            ->where('stock_date', '<=', $to)
            ->select('sku', 'stock_date', DB::raw('SUM(qty) as total_qty'))
            ->groupBy('sku', 'stock_date')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $date = Carbon::parse($row->stock_date)->toDateString();
            $map[(int) $row->sku][$date] = (int) $row->total_qty;
        }

        return $map;
    }

    /**
     * @param  list<int>  $skus
     * @return list<int>
     */
    private function stockoutSkuList(int $cabinetId, string $from, string $to, array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        return OzStockHistoryItem::query()
            ->where('cabinet_id', $cabinetId)
            ->whereIn('sku', $skus)
            ->where('stock_date', '>=', $from)
            ->where('stock_date', '<=', $to)
            ->select('sku', 'warehouse_key')
            ->groupBy('sku', 'warehouse_key')
            ->havingRaw('MAX(qty) > 0')
            ->havingRaw('COALESCE(SUM(CASE WHEN stock_date = ? THEN qty ELSE 0 END), 0) = 0', [$to])
            ->pluck('sku')
            ->map(static fn ($sku): int => (int) $sku)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function dateList(string $from, string $to): array
    {
        $dates = [];
        $cursor = Carbon::parse($from, OzStockHistoryCalendar::TIMEZONE)->startOfDay();
        $end = Carbon::parse($to, OzStockHistoryCalendar::TIMEZONE)->startOfDay();
        while ($cursor->lte($end)) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        return $dates;
    }

    private function normalizeDate(string $value, string $fallback): string
    {
        try {
            return Carbon::parse($value, OzStockHistoryCalendar::TIMEZONE)->toDateString();
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
