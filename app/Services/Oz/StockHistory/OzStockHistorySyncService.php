<?php

namespace App\Services\Oz\StockHistory;

use App\Enums\OzStockHistorySnapshotStatus;
use App\Models\Subscribers\Oz\OzCabinet;
use App\Models\Subscribers\Oz\StockHistory\OzStockHistoryItem;
use App\Models\Subscribers\Oz\StockHistory\OzStockHistoryProduct;
use App\Models\Subscribers\Oz\StockHistory\OzStockHistorySetting;
use App\Models\Subscribers\Oz\StockHistory\OzStockHistorySnapshot;
use App\Models\Subscribers\Oz\StockHistory\OzStockHistoryWarehouse;
use App\Services\Ozon\OzonApiService;
use App\Support\Oz\OzStockHistoryCalendar;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Загрузка каталога и дневного снимка остатков FBO.
 */
class OzStockHistorySyncService
{
    private const PRODUCT_LIST_LIMIT = 1000;

    private const PRODUCT_INFO_BATCH = 100;

    private const STOCKS_SKU_BATCH = 100;

    private const UPSERT_CHUNK = 500;

    public function __construct(
        private readonly OzonApiService $ozonApiService,
    ) {}

    /**
     * @return array{success: bool, products_count: int, messages: list<string>}
     */
    public function syncProducts(OzCabinet $cabinet): array
    {
        $apiKey = (string) $cabinet->apikey;
        $clientId = (string) $cabinet->client_id;
        $guard = $this->makeGuard();

        $productRefs = $this->fetchAllProductIds($guard, $apiKey, $clientId);
        if ($productRefs === []) {
            return [
                'success' => false,
                'products_count' => 0,
                'messages' => ['В кабинете нет товаров.'],
            ];
        }

        $productIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['product_id'] ?? 0),
            $productRefs,
        ))));
        $productIds = array_values(array_filter($productIds, static fn (int $id): bool => $id > 0));

        $detailsById = $this->fetchProductsInfo($guard, $apiKey, $clientId, $productIds);

        $now = now();
        $rows = [];
        $seenSkus = [];

        foreach ($productRefs as $ref) {
            $productId = (int) ($ref['product_id'] ?? 0);
            $detail = $detailsById[$productId] ?? [];
            $offerId = (string) ($detail['offer_id'] ?? $ref['offer_id'] ?? '');
            $name = isset($detail['name']) ? (string) $detail['name'] : null;
            $image = $this->extractPrimaryImage($detail);

            foreach ($this->extractFboSkus($detail) as $sku) {
                if (isset($seenSkus[$sku])) {
                    continue;
                }
                $seenSkus[$sku] = true;
                $rows[] = [
                    'cabinet_id' => $cabinet->id,
                    'sku' => $sku,
                    'product_id' => $productId > 0 ? $productId : null,
                    'offer_id' => $offerId !== '' ? mb_substr($offerId, 0, 255) : null,
                    'name' => $name !== null && $name !== '' ? mb_substr($name, 0, 255) : null,
                    'image_url' => $image,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows === []) {
            return [
                'success' => false,
                'products_count' => 0,
                'messages' => ['Не удалось определить товары кабинета.'],
            ];
        }

        DB::transaction(function () use ($cabinet, $rows, $now): void {
            OzStockHistoryProduct::query()
                ->where('cabinet_id', $cabinet->id)
                ->update(['is_active' => false]);

            foreach (array_chunk($rows, self::UPSERT_CHUNK) as $chunk) {
                OzStockHistoryProduct::query()->upsert(
                    $chunk,
                    ['cabinet_id', 'sku'],
                    ['product_id', 'offer_id', 'name', 'image_url', 'is_active', 'updated_at'],
                );
            }
        });

        $count = count($rows);

        OzStockHistorySetting::query()->updateOrCreate(
            ['cabinet_id' => $cabinet->id],
            [
                'products_synced_at' => $now,
                'products_count' => $count,
            ],
        );

        return [
            'success' => true,
            'products_count' => $count,
            'messages' => ['Товары кабинета загружены.'],
        ];
    }

    /**
     * @return array{success: bool, rows_count: int, messages: list<string>}
     */
    public function snapshotStocks(OzCabinet $cabinet, string $stockDate, bool $force = false): array
    {
        $snapshot = OzStockHistorySnapshot::query()->firstOrNew([
            'cabinet_id' => $cabinet->id,
            'stock_date' => $stockDate,
        ]);

        if (! $force && $snapshot->exists && $snapshot->status === OzStockHistorySnapshotStatus::Done) {
            return [
                'success' => true,
                'rows_count' => (int) $snapshot->rows_count,
                'messages' => ['Остатки за этот день уже сохранены.'],
            ];
        }

        $snapshot->status = OzStockHistorySnapshotStatus::Running;
        $snapshot->error_message = null;
        $snapshot->save();

        try {
            $catalog = $this->syncProducts($cabinet);
            if (! ($catalog['success'] ?? false)) {
                throw new RuntimeException($catalog['messages'][0] ?? 'Не удалось загрузить товары кабинета.');
            }

            $skus = OzStockHistoryProduct::query()
                ->where('cabinet_id', $cabinet->id)
                ->where('is_active', true)
                ->pluck('sku')
                ->map(static fn ($sku): int => (int) $sku)
                ->filter(static fn (int $sku): bool => $sku > 0)
                ->values()
                ->all();

            $apiKey = (string) $cabinet->apikey;
            $clientId = (string) $cabinet->client_id;
            $guard = $this->makeGuard();

            $clusterMap = $this->fetchClusterWarehouseMap($guard, $apiKey, $clientId);
            $stockRows = $this->fetchAnalyticsStocks($guard, $apiKey, $clientId, $skus);

            $knownPairs = $this->knownPairs($cabinet->id);
            $now = now();
            $warehouseRows = [];
            $itemRows = [];

            foreach ($stockRows as $row) {
                $sku = (int) ($row['sku'] ?? 0);
                $qty = (int) ($row['qty'] ?? 0);
                if ($sku <= 0) {
                    continue;
                }

                $resolved = $this->resolveWarehouse($row, $clusterMap);
                $pairKey = $sku.':'.$resolved['warehouse_key'];
                $isKnown = isset($knownPairs[$pairKey]);

                if ($qty <= 0 && ! $isKnown) {
                    continue;
                }

                $warehouseRows[$resolved['warehouse_key']] = [
                    'cabinet_id' => $cabinet->id,
                    'warehouse_key' => $resolved['warehouse_key'],
                    'warehouse_id' => $resolved['warehouse_id'],
                    'warehouse_name' => mb_substr($resolved['warehouse_name'], 0, 255),
                    'cluster_id' => $resolved['cluster_id'],
                    'cluster_name' => $resolved['cluster_name'] !== null
                        ? mb_substr($resolved['cluster_name'], 0, 255)
                        : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $itemRows[] = [
                    'cabinet_id' => $cabinet->id,
                    'sku' => $sku,
                    'warehouse_key' => $resolved['warehouse_key'],
                    'stock_date' => $stockDate,
                    'qty' => max(0, $qty),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $knownPairs[$pairKey] = true;
            }

            DB::transaction(function () use ($warehouseRows, $itemRows): void {
                foreach (array_chunk(array_values($warehouseRows), self::UPSERT_CHUNK) as $chunk) {
                    OzStockHistoryWarehouse::query()->upsert(
                        $chunk,
                        ['cabinet_id', 'warehouse_key'],
                        ['warehouse_id', 'warehouse_name', 'cluster_id', 'cluster_name', 'updated_at'],
                    );
                }

                foreach (array_chunk($itemRows, self::UPSERT_CHUNK) as $chunk) {
                    OzStockHistoryItem::query()->upsert(
                        $chunk,
                        ['cabinet_id', 'sku', 'warehouse_key', 'stock_date'],
                        ['qty', 'updated_at'],
                    );
                }
            });

            $snapshot->status = OzStockHistorySnapshotStatus::Done;
            $snapshot->collected_at = $now;
            $snapshot->products_count = (int) ($catalog['products_count'] ?? 0);
            $snapshot->rows_count = count($itemRows);
            $snapshot->error_message = null;
            $snapshot->save();

            return [
                'success' => true,
                'rows_count' => count($itemRows),
                'messages' => ['Остатки сохранены.'],
            ];
        } catch (Throwable $e) {
            $snapshot->status = OzStockHistorySnapshotStatus::Failed;
            $snapshot->error_message = 'Не удалось обновить остатки. Попробуем снова вечером.';
            $snapshot->save();

            throw $e;
        }
    }

    public function yesterdayDate(): string
    {
        return OzStockHistoryCalendar::yesterdayDate();
    }

    public function pruneCabinet(int $cabinetId, int $retentionDays): int
    {
        $retentionDays = max(
            OzStockHistorySetting::MIN_RETENTION_DAYS,
            min(OzStockHistorySetting::MAX_RETENTION_DAYS, $retentionDays),
        );
        $cutoff = OzStockHistoryCalendar::today()->subDays($retentionDays)->toDateString();

        $deleted = OzStockHistoryItem::query()
            ->where('cabinet_id', $cabinetId)
            ->where('stock_date', '<', $cutoff)
            ->delete();

        OzStockHistorySnapshot::query()
            ->where('cabinet_id', $cabinetId)
            ->where('stock_date', '<', $cutoff)
            ->delete();

        return $deleted;
    }

    private function makeGuard(): OzStockHistoryRequestGuard
    {
        return new OzStockHistoryRequestGuard(
            maxAttempts: 4,
            minIntervalMs: app()->environment('testing') ? 0 : 350,
            rateLimitBackoffMs: app()->environment('testing') ? 0 : 30_000,
        );
    }

    /**
     * @return list<array{product_id: int|null, offer_id: string|null}>
     */
    private function fetchAllProductIds(OzStockHistoryRequestGuard $guard, string $apiKey, string $clientId): array
    {
        $items = [];
        $lastId = '';

        do {
            $response = $guard->requestWithRetry(
                fn () => $this->ozonApiService->getProductsList($apiKey, $clientId, [
                    'filter' => ['visibility' => 'ALL'],
                    'last_id' => $lastId,
                    'limit' => self::PRODUCT_LIST_LIMIT,
                ]),
                'product/list',
            );

            $batch = (array) Arr::get($response, 'data.result.items', []);
            foreach ($batch as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $items[] = [
                    'product_id' => isset($item['product_id']) ? (int) $item['product_id'] : null,
                    'offer_id' => isset($item['offer_id']) ? (string) $item['offer_id'] : null,
                ];
            }

            $lastId = (string) Arr::get($response, 'data.result.last_id', '');
        } while ($batch !== [] && $lastId !== '');

        return $items;
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchProductsInfo(
        OzStockHistoryRequestGuard $guard,
        string $apiKey,
        string $clientId,
        array $productIds,
    ): array {
        $byId = [];

        foreach (array_chunk($productIds, self::PRODUCT_INFO_BATCH) as $chunk) {
            $response = $guard->requestWithRetry(
                fn () => $this->ozonApiService->getProductsInfo($apiKey, $clientId, $chunk),
                'product/info/list',
            );

            $items = (array) Arr::get($response, 'data.items', Arr::get($response, 'data.result.items', []));
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $id = (int) ($item['id'] ?? $item['product_id'] ?? 0);
                if ($id > 0) {
                    $byId[$id] = $item;
                }
            }
        }

        if ($byId === [] && $productIds !== []) {
            throw new RuntimeException('Не удалось получить информацию о товарах.');
        }

        return $byId;
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return list<int>
     */
    private function extractFboSkus(array $detail): array
    {
        $skus = [];
        $fboSku = (int) ($detail['fbo_sku'] ?? 0);
        if ($fboSku > 0) {
            $skus[] = $fboSku;
        }

        foreach ((array) ($detail['sources'] ?? []) as $source) {
            if (! is_array($source)) {
                continue;
            }
            $type = strtolower((string) ($source['source'] ?? ''));
            $sku = (int) ($source['sku'] ?? 0);
            if ($sku > 0 && ($type === '' || str_contains($type, 'fbo'))) {
                $skus[] = $sku;
            }
        }

        if ($skus === []) {
            $sku = (int) ($detail['sku'] ?? 0);
            if ($sku > 0) {
                $skus[] = $sku;
            }
        }

        return array_values(array_unique($skus));
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function extractPrimaryImage(array $detail): ?string
    {
        $primary = $detail['primary_image'] ?? null;
        if (is_array($primary)) {
            $primary = $primary[0] ?? null;
        }
        if (is_string($primary) && $primary !== '') {
            return mb_substr($primary, 0, 1024);
        }

        $images = (array) ($detail['images'] ?? []);
        $first = $images[0] ?? null;
        if (is_array($first)) {
            $first = $first['file_name'] ?? $first[0] ?? null;
        }

        return is_string($first) && $first !== '' ? mb_substr($first, 0, 1024) : null;
    }

    /**
     * @return array<string, array{warehouse_id: int|null, warehouse_name: string, cluster_id: int|null, cluster_name: string|null}>
     */
    private function fetchClusterWarehouseMap(
        OzStockHistoryRequestGuard $guard,
        string $apiKey,
        string $clientId,
    ): array {
        try {
            $response = $guard->requestWithRetry(
                fn () => $this->ozonApiService->getClusterList($apiKey, $clientId, [
                    'cluster_type' => 'CLUSTER_TYPE_OZON',
                ]),
                'cluster/list',
            );
        } catch (Throwable) {
            return [];
        }

        $map = [];
        foreach ((array) Arr::get($response, 'data.clusters', []) as $cluster) {
            if (! is_array($cluster)) {
                continue;
            }
            $clusterId = isset($cluster['id']) ? (int) $cluster['id'] : null;
            $clusterName = isset($cluster['name']) ? (string) $cluster['name'] : null;

            foreach ((array) ($cluster['logistic_clusters'] ?? []) as $logistic) {
                if (! is_array($logistic)) {
                    continue;
                }
                foreach ((array) ($logistic['warehouses'] ?? []) as $warehouse) {
                    if (! is_array($warehouse)) {
                        continue;
                    }
                    $id = (int) ($warehouse['warehouse_id'] ?? $warehouse['id'] ?? 0);
                    $name = trim((string) ($warehouse['name'] ?? ''));
                    $entry = [
                        'warehouse_id' => $id > 0 ? $id : null,
                        'warehouse_name' => $name !== '' ? $name : ('Склад '.($id > 0 ? (string) $id : '')),
                        'cluster_id' => $clusterId,
                        'cluster_name' => $clusterName,
                    ];
                    if ($id > 0) {
                        $map['id:'.$id] = $entry;
                    }
                    if ($name !== '') {
                        $map['name:'.$this->normalizeWarehouseName($name)] = $entry;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * @param  list<int>  $skus
     * @return list<array<string, mixed>>
     */
    private function fetchAnalyticsStocks(
        OzStockHistoryRequestGuard $guard,
        string $apiKey,
        string $clientId,
        array $skus,
    ): array {
        $rows = [];

        foreach (array_chunk($skus, self::STOCKS_SKU_BATCH) as $batch) {
            $response = $guard->requestWithRetry(
                fn () => $this->ozonApiService->getAnalyticsStocks($apiKey, $clientId, [
                    'skus' => array_map('strval', $batch),
                ]),
                'analytics/stocks',
            );

            $items = (array) Arr::get($response, 'data.items', []);
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $sku = (int) ($item['sku'] ?? 0);
                if ($sku <= 0) {
                    continue;
                }
                $rows[] = [
                    'sku' => $sku,
                    'qty' => (int) ($item['available_stock_count'] ?? 0),
                    'warehouse_id' => isset($item['warehouse_id']) ? (int) $item['warehouse_id'] : null,
                    'warehouse_name' => isset($item['warehouse_name']) ? (string) $item['warehouse_name'] : '',
                    'cluster_id' => isset($item['cluster_id']) ? (int) $item['cluster_id'] : null,
                    'cluster_name' => isset($item['cluster_name']) ? (string) $item['cluster_name'] : null,
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<string, true>
     */
    private function knownPairs(int $cabinetId): array
    {
        $pairs = [];
        OzStockHistoryItem::query()
            ->where('cabinet_id', $cabinetId)
            ->select('sku', 'warehouse_key')
            ->distinct()
            ->orderBy('sku')
            ->chunk(2000, function ($chunk) use (&$pairs): void {
                foreach ($chunk as $row) {
                    $pairs[(int) $row->sku.':'.$row->warehouse_key] = true;
                }
            });

        return $pairs;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, array{warehouse_id: int|null, warehouse_name: string, cluster_id: int|null, cluster_name: string|null}>  $clusterMap
     * @return array{warehouse_key: string, warehouse_id: int|null, warehouse_name: string, cluster_id: int|null, cluster_name: string|null}
     */
    private function resolveWarehouse(array $row, array $clusterMap): array
    {
        $warehouseId = (int) ($row['warehouse_id'] ?? 0);
        $warehouseName = trim((string) ($row['warehouse_name'] ?? ''));
        $fromId = $warehouseId > 0 ? ($clusterMap['id:'.$warehouseId] ?? null) : null;
        $fromName = $warehouseName !== ''
            ? ($clusterMap['name:'.$this->normalizeWarehouseName($warehouseName)] ?? null)
            : null;
        $mapped = $fromId ?? $fromName;

        $resolvedId = $warehouseId > 0 ? $warehouseId : ($mapped['warehouse_id'] ?? null);
        $resolvedName = $warehouseName !== ''
            ? $warehouseName
            : (string) ($mapped['warehouse_name'] ?? 'Склад');

        $key = $resolvedId !== null && $resolvedId > 0
            ? (string) $resolvedId
            : 'name:'.$this->normalizeWarehouseName($resolvedName);

        return [
            'warehouse_key' => mb_substr($key, 0, 64),
            'warehouse_id' => $resolvedId !== null && $resolvedId > 0 ? $resolvedId : null,
            'warehouse_name' => $resolvedName !== '' ? $resolvedName : 'Склад',
            'cluster_id' => $mapped['cluster_id'] ?? ((int) ($row['cluster_id'] ?? 0) ?: null),
            'cluster_name' => $mapped['cluster_name']
                ?? (isset($row['cluster_name']) && $row['cluster_name'] !== '' ? (string) $row['cluster_name'] : null),
        ];
    }

    private function normalizeWarehouseName(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
