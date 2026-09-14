<?php

namespace App\Services\Wb\StockHistory;

use App\Models\Subscribers\Wb\StockHistory\WbOrderHistoryDay;
use App\Models\Subscribers\Wb\StockHistory\WbOrderHistoryItem;
use App\Models\Subscribers\Wb\StockHistory\WbStockHistoryDay;
use App\Models\Subscribers\Wb\StockHistory\WbStockHistoryItem;
use App\Models\Subscribers\Wb\StockHistory\WbStockHistoryProduct;
use App\Models\Subscribers\Wb\StockHistory\WbStockHistorySetting;
use App\Models\Subscribers\Wb\StockHistory\WbStockHistoryWarehouse;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Support\Wb\WbStockHistoryCalendar;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Загрузка и сохранение истории остатков и заказов WB.
 */
class WbStockHistorySyncService
{
    public const TOTAL_WAREHOUSE_KEY = '_total';

    private const UPSERT_CHUNK = 400;

    private const CURRENT_STOCKS_TTL_SECONDS = 86400;

    private const OFFICES_TTL_SECONDS = 21600;

    public function __construct(
        private readonly WbStockHistoryApiClient $apiClient,
    ) {}

    public function yesterdayDate(): string
    {
        return WbStockHistoryCalendar::yesterdayDate();
    }

    /**
     * @return array{success: bool, messages: list<string>, products_count: int}
     */
    public function syncProducts(WbCabinet $cabinet): array
    {
        try {
            $cards = $this->apiClient->fetchCards((string) $cabinet->apikey);
        } catch (Throwable $e) {
            return [
                'success' => false,
                'messages' => [$this->userError($e, 'Не удалось загрузить товары кабинета.')],
                'products_count' => 0,
            ];
        }

        $now = now();
        $rows = [];
        $seen = [];

        foreach ($cards as $card) {
            $nmId = (int) ($card['nmId'] ?? 0);
            if ($nmId <= 0) {
                continue;
            }
            $sizes = $card['sizes'] ?? [];
            if ($sizes === []) {
                $sizes = [['chrtId' => 0, 'techSize' => '', 'barcode' => '']];
            }
            foreach ($sizes as $size) {
                $chrtId = (int) ($size['chrtId'] ?? 0);
                $key = $nmId.':'.$chrtId;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $rows[] = [
                    'cabinet_id' => $cabinet->id,
                    'nm_id' => $nmId,
                    'chrt_id' => $chrtId,
                    'vendor_code' => $this->cut((string) ($card['vendorCode'] ?? '')),
                    'subject' => $this->cut((string) ($card['subject'] ?? '')),
                    'name' => $this->cut((string) ($card['name'] ?? '')),
                    'tech_size' => $this->cut((string) ($size['techSize'] ?? ''), 64),
                    'barcode' => $this->cut((string) ($size['barcode'] ?? ''), 64),
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::transaction(function () use ($cabinet, $rows): void {
            WbStockHistoryProduct::query()
                ->where('cabinet_id', $cabinet->id)
                ->update(['is_active' => false]);

            foreach (array_chunk($rows, self::UPSERT_CHUNK) as $chunk) {
                WbStockHistoryProduct::query()->upsert(
                    $chunk,
                    ['cabinet_id', 'nm_id', 'chrt_id'],
                    ['vendor_code', 'subject', 'name', 'tech_size', 'barcode', 'is_active', 'updated_at'],
                );
            }
        });

        $count = count($rows);
        WbStockHistorySetting::query()->updateOrCreate(
            ['cabinet_id' => $cabinet->id],
            [
                'products_synced_at' => $now,
                'products_count' => $count,
            ],
        );

        return [
            'success' => true,
            'messages' => [],
            'products_count' => $count,
        ];
    }

    /**
     * @return array{success: bool, messages: list<string>, days: list<string>}
     */
    public function importStockHistory(WbCabinet $cabinet, string $from, string $to, bool $force = false): array
    {
        try {
            $rows = $this->apiClient->fetchDailyStockHistory((string) $cabinet->apikey, $from, $to);
        } catch (Throwable $e) {
            return [
                'success' => false,
                'messages' => [$this->userError($e, 'Не удалось загрузить историю остатков.')],
                'days' => [],
            ];
        }

        $dates = $this->dateList($from, $to);
        $existing = [];
        if ($force) {
            // Перезаписываем дни целиком: иначе старые снимки по складам смешаются с отчётом WB.
            WbStockHistoryItem::query()
                ->where('cabinet_id', $cabinet->id)
                ->whereBetween('stock_date', [$from, $to])
                ->delete();
        } else {
            $existing = WbStockHistoryDay::query()
                ->where('cabinet_id', $cabinet->id)
                ->whereBetween('stock_date', [$from, $to])
                ->pluck('stock_date')
                ->map(static fn ($d) => Carbon::parse($d)->toDateString())
                ->all();
            $existing = array_fill_keys($existing, true);
        }

        $datesToWrite = array_values(array_filter(
            $dates,
            static fn (string $date): bool => $force || ! isset($existing[$date]),
        ));

        if ($datesToWrite === [] && $rows === []) {
            return [
                'success' => true,
                'messages' => ['История остатков за выбранные дни уже загружена.'],
                'days' => [],
            ];
        }

        $now = now();
        $products = [];
        $warehouses = [
            self::TOTAL_WAREHOUSE_KEY => [
                'cabinet_id' => $cabinet->id,
                'warehouse_key' => self::TOTAL_WAREHOUSE_KEY,
                'warehouse_id' => null,
                'warehouse_name' => 'Все склады',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];
        $totals = [];
        $named = [];
        $writeSet = array_fill_keys($datesToWrite, true);
        $offices = $this->officesMap($cabinet);

        foreach ($rows as $row) {
            $nmId = (int) ($row['nmId'] ?? 0);
            $chrtId = (int) ($row['chrtId'] ?? 0);
            if ($nmId <= 0) {
                continue;
            }

            $productKey = $nmId.':'.$chrtId;
            $products[$productKey] = [
                'cabinet_id' => $cabinet->id,
                'nm_id' => $nmId,
                'chrt_id' => $chrtId,
                'vendor_code' => $this->cut((string) ($row['vendorCode'] ?? '')),
                'subject' => $this->cut((string) ($row['subjectName'] ?? '')),
                'name' => $this->cut((string) ($row['name'] ?? '')),
                'tech_size' => $this->cut((string) ($row['sizeName'] ?? ''), 64),
                'barcode' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $warehouseId = (int) ($row['warehouseId'] ?? 0);
            $resolved = $this->resolveWarehouse($warehouseId, (string) ($row['officeName'] ?? ''), $offices);

            foreach ((array) ($row['stocks'] ?? []) as $date => $qty) {
                $iso = (string) $date;
                if (! isset($writeSet[$iso])) {
                    continue;
                }
                $qty = max(0, (int) $qty);
                $totals[$productKey][$iso] = ($totals[$productKey][$iso] ?? 0) + $qty;
                if ($resolved !== null) {
                    $named[$resolved['key']]['warehouse'] = $resolved;
                    $named[$resolved['key']]['qty'][$productKey][$iso] = ($named[$resolved['key']]['qty'][$productKey][$iso] ?? 0) + $qty;
                }
            }
        }

        $items = [];
        foreach ($totals as $productKey => $byDate) {
            [$nmId, $chrtId] = array_map('intval', explode(':', $productKey, 2));
            foreach ($byDate as $iso => $qty) {
                $items[] = [
                    'cabinet_id' => $cabinet->id,
                    'nm_id' => $nmId,
                    'chrt_id' => $chrtId,
                    'warehouse_key' => self::TOTAL_WAREHOUSE_KEY,
                    'stock_date' => $iso,
                    'qty' => $qty,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        foreach ($named as $pack) {
            $warehouse = $pack['warehouse'];
            $warehouses[$warehouse['key']] = [
                'cabinet_id' => $cabinet->id,
                'warehouse_key' => $warehouse['key'],
                'warehouse_id' => $warehouse['id'],
                'warehouse_name' => $this->cut($warehouse['name']),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            foreach ($pack['qty'] as $productKey => $byDate) {
                [$nmId, $chrtId] = array_map('intval', explode(':', $productKey, 2));
                foreach ($byDate as $iso => $qty) {
                    $items[] = [
                        'cabinet_id' => $cabinet->id,
                        'nm_id' => $nmId,
                        'chrt_id' => $chrtId,
                        'warehouse_key' => $warehouse['key'],
                        'stock_date' => $iso,
                        'qty' => $qty,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        DB::transaction(function () use ($cabinet, $products, $warehouses, $items, $datesToWrite, $now): void {
            foreach (array_chunk(array_values($products), self::UPSERT_CHUNK) as $chunk) {
                WbStockHistoryProduct::query()->upsert(
                    $chunk,
                    ['cabinet_id', 'nm_id', 'chrt_id'],
                    ['vendor_code', 'subject', 'name', 'tech_size', 'is_active', 'updated_at'],
                );
            }
            foreach (array_chunk(array_values($warehouses), self::UPSERT_CHUNK) as $chunk) {
                WbStockHistoryWarehouse::query()->upsert(
                    $chunk,
                    ['cabinet_id', 'warehouse_key'],
                    ['warehouse_id', 'warehouse_name', 'updated_at'],
                );
            }
            foreach (array_chunk($items, self::UPSERT_CHUNK) as $chunk) {
                WbStockHistoryItem::query()->upsert(
                    $chunk,
                    ['cabinet_id', 'nm_id', 'chrt_id', 'warehouse_key', 'stock_date'],
                    ['qty', 'updated_at'],
                );
            }

            $dayRows = [];
            foreach ($datesToWrite as $date) {
                $dayRows[] = [
                    'cabinet_id' => $cabinet->id,
                    'stock_date' => $date,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($dayRows, self::UPSERT_CHUNK) as $chunk) {
                WbStockHistoryDay::query()->upsert(
                    $chunk,
                    ['cabinet_id', 'stock_date'],
                    ['updated_at'],
                );
            }
        });

        return [
            'success' => true,
            'messages' => ['История остатков загружена.'],
            'days' => $datesToWrite,
        ];
    }

    /**
     * @return array{success: bool, messages: list<string>, days: list<string>}
     */
    public function importOrders(WbCabinet $cabinet, string $from, string $to, bool $force = false): array
    {
        $flag = $from === $to ? 1 : 0;

        try {
            $rows = $this->apiClient->fetchOrders((string) $cabinet->apikey, $from, $flag);
        } catch (Throwable $e) {
            return [
                'success' => false,
                'messages' => [$this->userError($e, 'Не удалось загрузить историю заказов.')],
                'days' => [],
            ];
        }

        $dates = $this->dateList($from, $to);
        $existing = [];
        if (! $force) {
            $existing = WbOrderHistoryDay::query()
                ->where('cabinet_id', $cabinet->id)
                ->whereBetween('order_date', [$from, $to])
                ->pluck('order_date')
                ->map(static fn ($d) => Carbon::parse($d)->toDateString())
                ->all();
            $existing = array_fill_keys($existing, true);
        }

        $datesToWrite = array_values(array_filter(
            $dates,
            static fn (string $date): bool => $force || ! isset($existing[$date]),
        ));
        $writeSet = array_fill_keys($datesToWrite, true);

        $seenSrid = [];
        $counts = [];
        $meta = [];

        foreach ($rows as $row) {
            $srid = trim((string) ($row['srid'] ?? ''));
            if ($srid !== '') {
                if (isset($seenSrid[$srid])) {
                    continue;
                }
                $seenSrid[$srid] = true;
            }

            $orderDate = $this->orderDate((string) ($row['date'] ?? ''));
            if ($orderDate === null || ! isset($writeSet[$orderDate])) {
                continue;
            }

            $nmId = (int) ($row['nmId'] ?? 0);
            if ($nmId <= 0) {
                continue;
            }
            $techSize = (string) ($row['techSize'] ?? '');
            $barcode = (string) ($row['barcode'] ?? '');
            $key = $nmId."\n".$techSize."\n".$barcode."\n".$orderDate;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $meta[$key] = [
                'nm_id' => $nmId,
                'tech_size' => $this->cut($techSize, 64),
                'barcode' => $this->cut($barcode, 64),
                'vendor_code' => $this->cut((string) ($row['supplierArticle'] ?? '')),
                'subject' => $this->cut((string) ($row['subject'] ?? '')),
                'order_date' => $orderDate,
            ];
        }

        $now = now();
        $items = [];
        foreach ($counts as $key => $count) {
            $info = $meta[$key];
            $items[] = [
                'cabinet_id' => $cabinet->id,
                'nm_id' => $info['nm_id'],
                'tech_size' => $info['tech_size'],
                'barcode' => $info['barcode'],
                'vendor_code' => $info['vendor_code'] !== '' ? $info['vendor_code'] : null,
                'subject' => $info['subject'] !== '' ? $info['subject'] : null,
                'order_date' => $info['order_date'],
                'orders_count' => $count,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($cabinet, $items, $datesToWrite, $now): void {
            foreach (array_chunk($items, self::UPSERT_CHUNK) as $chunk) {
                WbOrderHistoryItem::query()->upsert(
                    $chunk,
                    ['cabinet_id', 'nm_id', 'tech_size', 'barcode', 'order_date'],
                    ['vendor_code', 'subject', 'orders_count', 'updated_at'],
                );
            }

            $dayRows = [];
            foreach ($datesToWrite as $date) {
                $dayRows[] = [
                    'cabinet_id' => $cabinet->id,
                    'order_date' => $date,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($dayRows, self::UPSERT_CHUNK) as $chunk) {
                WbOrderHistoryDay::query()->upsert(
                    $chunk,
                    ['cabinet_id', 'order_date'],
                    ['updated_at'],
                );
            }
        });

        return [
            'success' => true,
            'messages' => ['История заказов загружена.'],
            'days' => $datesToWrite,
        ];
    }

    /**
     * Снимок остатков по складам WB на дату (warehouseId → имя из /api/v3/offices).
     *
     * @return array{success: bool, messages: list<string>}
     */
    public function importWarehouseSnapshot(WbCabinet $cabinet, string $date): array
    {
        $offices = $this->officesMap($cabinet);

        try {
            $rows = $this->apiClient->fetchCurrentStocks((string) $cabinet->apikey);
        } catch (Throwable $e) {
            return [
                'success' => false,
                'messages' => [$this->userError($e, 'Не удалось загрузить остатки по складам.')],
            ];
        }

        $now = now();
        $warehouses = [];
        $items = [];
        $current = [];

        foreach ($rows as $row) {
            $nmId = (int) ($row['nmId'] ?? 0);
            $chrtId = (int) ($row['chrtId'] ?? 0);
            if ($nmId <= 0) {
                continue;
            }
            $sizeKey = $nmId.':'.$chrtId;
            if (! isset($current[$sizeKey])) {
                $current[$sizeKey] = [
                    'quantity' => 0,
                    'in_way_to_client' => 0,
                    'in_way_from_client' => 0,
                    'warehouses' => [],
                ];
            }
            $qty = (int) ($row['quantity'] ?? 0);
            $current[$sizeKey]['in_way_to_client'] += (int) ($row['inWayToClient'] ?? 0);
            $current[$sizeKey]['in_way_from_client'] += (int) ($row['inWayFromClient'] ?? 0);

            $resolved = $this->resolveWarehouse(
                (int) ($row['warehouseId'] ?? 0),
                (string) ($row['warehouseName'] ?? ''),
                $offices,
            );
            if ($resolved === null) {
                $current[$sizeKey]['quantity'] += $qty;

                continue;
            }

            $current[$sizeKey]['quantity'] += $qty;
            $current[$sizeKey]['warehouses'][] = [
                'warehouse_key' => $resolved['key'],
                'name' => $resolved['name'],
                'quantity' => $qty,
            ];
            $warehouses[$resolved['key']] = [
                'cabinet_id' => $cabinet->id,
                'warehouse_key' => $resolved['key'],
                'warehouse_id' => $resolved['id'],
                'warehouse_name' => $this->cut($resolved['name']),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $items[] = [
                'cabinet_id' => $cabinet->id,
                'nm_id' => $nmId,
                'chrt_id' => $chrtId,
                'warehouse_key' => $resolved['key'],
                'stock_date' => $date,
                'qty' => max(0, $qty),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach ($current as &$item) {
            $byKey = [];
            foreach ($item['warehouses'] as $warehouse) {
                $key = $warehouse['warehouse_key'];
                if (! isset($byKey[$key])) {
                    $byKey[$key] = $warehouse;
                    continue;
                }
                $byKey[$key]['quantity'] += (int) $warehouse['quantity'];
            }
            $list = array_values($byKey);
            usort($list, static function (array $a, array $b): int {
                return [$b['quantity'], $a['name']] <=> [$a['quantity'], $b['name']];
            });
            $item['warehouses'] = array_values(array_filter(
                $list,
                static fn (array $row): bool => (int) $row['quantity'] > 0,
            ));
        }
        unset($item);

        Cache::put($this->currentStocksCacheKey((int) $cabinet->id), $current, self::CURRENT_STOCKS_TTL_SECONDS);

        if ($warehouses !== []) {
            DB::transaction(function () use ($cabinet, $warehouses, $items, $date, $now): void {
                foreach (array_chunk(array_values($warehouses), self::UPSERT_CHUNK) as $chunk) {
                    WbStockHistoryWarehouse::query()->upsert(
                        $chunk,
                        ['cabinet_id', 'warehouse_key'],
                        ['warehouse_id', 'warehouse_name', 'updated_at'],
                    );
                }
                foreach (array_chunk($items, self::UPSERT_CHUNK) as $chunk) {
                    WbStockHistoryItem::query()->upsert(
                        $chunk,
                        ['cabinet_id', 'nm_id', 'chrt_id', 'warehouse_key', 'stock_date'],
                        ['qty', 'updated_at'],
                    );
                }
                WbStockHistoryDay::query()->upsert(
                    [[
                        'cabinet_id' => $cabinet->id,
                        'stock_date' => $date,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]],
                    ['cabinet_id', 'stock_date'],
                    ['updated_at'],
                );
            });
        }

        return [
            'success' => true,
            'messages' => [],
        ];
    }

    /**
     * Текущие остатки из последнего снимка job. Страница API WB не вызывает.
     *
     * @return array<string, array{
     *     quantity: int,
     *     in_way_to_client: int,
     *     in_way_from_client: int,
     *     warehouses: list<array{warehouse_key: string, name: string, quantity: int}>
     * }>
     */
    public function currentStocksBySize(int $cabinetId): array
    {
        $cached = Cache::get($this->currentStocksCacheKey($cabinetId));

        return is_array($cached) ? $cached : [];
    }

    /**
     * @return array<int, string>
     */
    public function officesMap(WbCabinet $cabinet): array
    {
        $cacheKey = 'wb-stock-history-offices-map-'.$cabinet->id;

        return Cache::remember($cacheKey, self::OFFICES_TTL_SECONDS, function () use ($cabinet): array {
            try {
                return $this->apiClient->fetchOffices((string) $cabinet->apikey);
            } catch (Throwable) {
                return [];
            }
        });
    }

    /**
     * @param  array<int, string>  $offices
     * @return array{id: int, key: string, name: string}|null
     */
    public function resolveWarehouse(int $warehouseId, string $fallbackName, array $offices): ?array
    {
        if ($this->apiClient->isPlaceholderWarehouseId($warehouseId)) {
            return null;
        }

        $name = trim((string) ($offices[$warehouseId] ?? ''));
        if ($name === '') {
            $name = trim($fallbackName);
        }
        if ($name === '' || $this->apiClient->isPlaceholderWarehouseName($name)) {
            $name = 'Склад '.$warehouseId;
        }

        return [
            'id' => $warehouseId,
            'key' => (string) $warehouseId,
            'name' => $name,
        ];
    }

    public function currentStocksCacheKey(int $cabinetId): string
    {
        return 'wb-stock-history-current-v3-'.$cabinetId;
    }

    public function isPlaceholderWarehouseName(string $name): bool
    {
        return $this->apiClient->isPlaceholderWarehouseName($name);
    }

    public function pruneCabinet(int $cabinetId, int $retentionDays): int
    {
        $cutoff = WbStockHistoryCalendar::today()
            ->subDays(max(1, $retentionDays))
            ->toDateString();

        $deleted = 0;
        $deleted += WbStockHistoryItem::query()
            ->where('cabinet_id', $cabinetId)
            ->where('stock_date', '<', $cutoff)
            ->delete();
        $deleted += WbStockHistoryDay::query()
            ->where('cabinet_id', $cabinetId)
            ->where('stock_date', '<', $cutoff)
            ->delete();
        $deleted += WbOrderHistoryItem::query()
            ->where('cabinet_id', $cabinetId)
            ->where('order_date', '<', $cutoff)
            ->delete();
        $deleted += WbOrderHistoryDay::query()
            ->where('cabinet_id', $cabinetId)
            ->where('order_date', '<', $cutoff)
            ->delete();

        return $deleted;
    }

    /**
     * @return list<string>
     */
    public function dateList(string $from, string $to): array
    {
        $dates = [];
        $cursor = Carbon::parse($from, WbStockHistoryCalendar::TIMEZONE)->startOfDay();
        $end = Carbon::parse($to, WbStockHistoryCalendar::TIMEZONE)->startOfDay();
        while ($cursor->lte($end)) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        return $dates;
    }

    private function orderDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw, WbStockHistoryCalendar::TIMEZONE)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function cut(string $value, int $limit = 255): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return mb_substr($value, 0, $limit);
    }

    private function userError(Throwable $e, string $fallback): string
    {
        $message = trim($e->getMessage());
        if ($e instanceof RuntimeException && $message !== '') {
            return $message;
        }

        return $fallback;
    }
}
