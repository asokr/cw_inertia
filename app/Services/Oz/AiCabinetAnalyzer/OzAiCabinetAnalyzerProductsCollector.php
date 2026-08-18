<?php

namespace App\Services\Oz\AiCabinetAnalyzer;

use App\Services\Oz\AiCabinetAnalyzer\Support\OzAiCabinetAnalyzerRequestGuard;
use App\Services\Ozon\OzonApiService;
use Illuminate\Support\Arr;
use RuntimeException;
use Throwable;

/**
 * Каталог товаров Ozon (этап 1): list + info + attributes(brand).
 */
class OzAiCabinetAnalyzerProductsCollector
{
    private const LIST_LIMIT = 1000;

    private const INFO_BATCH_SIZE = 999;

    private OzAiCabinetAnalyzerRequestGuard $guard;

    public function __construct(
        private readonly OzonApiService $ozonApiService,
    ) {
        $this->guard = new OzAiCabinetAnalyzerRequestGuard(
            maxAttempts: 4,
            minIntervalMs: 350,
            rateLimitBackoffMs: 5000,
        );
    }

    public function requestCount(): int
    {
        return $this->guard->requestCount();
    }

    public function retryCount(): int
    {
        return $this->guard->retryCount();
    }

    /**
     * @param  callable(string): void|null  $onStage
     * @return array{
     *   products: list<array<string, mixed>>,
     *   warnings: list<array<string, mixed>>,
     *   sku_to_product_id: array<int, int>
     * }
     */
    public function collect(string $apiKey, string $clientId, ?callable $onStage = null): array
    {
        $warnings = [];

        $onStage && $onStage('products_list');
        $listResult = $this->fetchAllProductIds($apiKey, $clientId, $onStage);
        $productRefs = $listResult['items'];

        if ($productRefs === []) {
            $warnings[] = [
                'type' => 'empty_catalog',
                'message' => 'В кабинете Ozon не найдено товаров.',
            ];
        }

        $onStage && $onStage('products_info');
        $productIds = array_values(array_filter(array_map(
            static fn (array $row): ?int => isset($row['product_id']) ? (int) $row['product_id'] : null,
            $productRefs,
        )));

        $detailsById = [];
        if ($productIds !== []) {
            $detailsResult = $this->fetchProductsInfo($apiKey, $clientId, $productIds, $onStage);
            $detailsById = $detailsResult['by_id'];
            if (! empty($detailsResult['warnings'])) {
                $warnings = array_merge($warnings, $detailsResult['warnings']);
            }
        }

        $onStage && $onStage('products_attributes');
        $brandById = [];
        if ($productIds !== []) {
            $brandResult = $this->fetchBrandsBestEffort($apiKey, $clientId, $productIds, $onStage);
            $brandById = $brandResult['brands'];
            if (! empty($brandResult['warnings'])) {
                $warnings = array_merge($warnings, $brandResult['warnings']);
            }
        }

        $products = [];
        $skuToProductId = [];

        foreach ($productRefs as $ref) {
            $productId = (int) ($ref['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $detail = $detailsById[$productId] ?? null;
            $product = $this->normalizeProduct(
                listItem: $ref,
                detail: is_array($detail) ? $detail : [],
                brand: $brandById[$productId] ?? null,
            );
            $products[] = $product;

            foreach ((array) data_get($product, 'skus.all', []) as $sku) {
                $skuInt = (int) $sku;
                if ($skuInt > 0 && ! isset($skuToProductId[$skuInt])) {
                    $skuToProductId[$skuInt] = $productId;
                }
            }
            $primarySku = (int) ($product['sku'] ?? 0);
            if ($primarySku > 0 && ! isset($skuToProductId[$primarySku])) {
                $skuToProductId[$primarySku] = $productId;
            }
        }

        usort($products, static function (array $a, array $b): int {
            return ((int) ($a['product_id'] ?? 0)) <=> ((int) ($b['product_id'] ?? 0));
        });

        return [
            'products' => $products,
            'warnings' => $warnings,
            'sku_to_product_id' => $skuToProductId,
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    private function fetchAllProductIds(string $apiKey, string $clientId, ?callable $onStage): array
    {
        $items = [];
        $lastId = '';

        do {
            $payload = [
                'filter' => [
                    'visibility' => 'ALL',
                ],
                'last_id' => $lastId,
                'limit' => self::LIST_LIMIT,
            ];

            $response = $this->guard->requestWithRetry(
                fn () => $this->ozonApiService->getProductsList($apiKey, $clientId, $payload),
                'product/list',
            );

            if (! ($response['success'] ?? false)) {
                $message = (string) Arr::get($response, 'data.message', 'Не удалось получить список товаров Ozon');
                throw new RuntimeException($message);
            }

            $batch = (array) Arr::get($response, 'data.result.items', []);
            foreach ($batch as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $items[] = [
                    'product_id' => isset($item['product_id']) ? (int) $item['product_id'] : null,
                    'offer_id' => isset($item['offer_id']) ? (string) $item['offer_id'] : null,
                    'archived' => array_key_exists('archived', $item) ? (bool) $item['archived'] : null,
                    'has_fbo_stocks' => array_key_exists('has_fbo_stocks', $item) ? (bool) $item['has_fbo_stocks'] : null,
                    'has_fbs_stocks' => array_key_exists('has_fbs_stocks', $item) ? (bool) $item['has_fbs_stocks'] : null,
                    'is_discounted' => array_key_exists('is_discounted', $item) ? (bool) $item['is_discounted'] : null,
                ];
            }

            $lastId = (string) Arr::get($response, 'data.result.last_id', '');
            $onStage && $onStage('products_list');
        } while ($batch !== [] && $lastId !== '');

        return ['items' => $items];
    }

    /**
     * @param  list<int>  $productIds
     * @return array{by_id: array<int, array<string, mixed>>, warnings: list<array<string, mixed>>}
     */
    private function fetchProductsInfo(string $apiKey, string $clientId, array $productIds, ?callable $onStage): array
    {
        $byId = [];
        $warnings = [];

        foreach (array_chunk($productIds, self::INFO_BATCH_SIZE) as $chunkIndex => $chunk) {
            $onStage && $onStage(sprintf('products_info_batch_%d', $chunkIndex + 1));

            try {
                $response = $this->guard->requestWithRetry(
                    fn () => $this->ozonApiService->getProductsInfo($apiKey, $clientId, $chunk),
                    'product/info/list',
                );
            } catch (Throwable $e) {
                $warnings[] = [
                    'type' => 'products_info_batch_failed',
                    'message' => $e->getMessage(),
                    'batch' => $chunkIndex + 1,
                ];
                continue;
            }

            if (! ($response['success'] ?? false)) {
                $warnings[] = [
                    'type' => 'products_info_batch_failed',
                    'message' => (string) Arr::get($response, 'data.message', 'Ошибка product/info/list'),
                    'batch' => $chunkIndex + 1,
                ];
                continue;
            }

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
            throw new RuntimeException('Не удалось получить детальную информацию о товарах Ozon.');
        }

        return ['by_id' => $byId, 'warnings' => $warnings];
    }

    /**
     * @param  list<int>  $productIds
     * @return array{brands: array<int, string|null>, warnings: list<array<string, mixed>>}
     */
    private function fetchBrandsBestEffort(string $apiKey, string $clientId, array $productIds, ?callable $onStage): array
    {
        $brands = [];
        $warnings = [];

        foreach (array_chunk($productIds, self::INFO_BATCH_SIZE) as $chunkIndex => $chunk) {
            $onStage && $onStage(sprintf('products_attributes_batch_%d', $chunkIndex + 1));

            $payload = [
                'filter' => [
                    'product_id' => array_map('strval', $chunk),
                ],
                'limit' => self::LIST_LIMIT,
            ];

            try {
                $response = $this->guard->requestWithRetry(
                    fn () => $this->ozonApiService->getProductAttributes($apiKey, $clientId, $payload),
                    'product/info/attributes',
                );
            } catch (Throwable $e) {
                $warnings[] = [
                    'type' => 'attributes_batch_failed',
                    'message' => $e->getMessage(),
                    'batch' => $chunkIndex + 1,
                ];
                continue;
            }

            if (! ($response['success'] ?? false)) {
                $warnings[] = [
                    'type' => 'attributes_batch_failed',
                    'message' => (string) Arr::get($response, 'data.message', 'Ошибка product/info/attributes'),
                    'batch' => $chunkIndex + 1,
                ];
                continue;
            }

            $items = (array) Arr::get($response, 'data.result', Arr::get($response, 'data.items', []));
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $id = (int) ($item['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $brands[$id] = $this->extractBrandFromAttributes($item);
            }
        }

        return ['brands' => $brands, 'warnings' => $warnings];
    }

    /**
     * @param  array<string, mixed>  $listItem
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    public function normalizeProduct(array $listItem, array $detail, ?string $brand): array
    {
        $productId = (int) ($detail['id'] ?? $listItem['product_id'] ?? 0);
        $offerId = (string) ($detail['offer_id'] ?? $listItem['offer_id'] ?? '');

        $sources = [];
        foreach ((array) ($detail['sources'] ?? []) as $source) {
            if (! is_array($source)) {
                continue;
            }
            $sources[] = [
                'sku' => isset($source['sku']) ? (int) $source['sku'] : null,
                'source' => isset($source['source']) ? (string) $source['source'] : null,
                'shipment_type' => isset($source['shipment_type']) ? (string) $source['shipment_type'] : null,
                'created_at' => isset($source['created_at']) ? (string) $source['created_at'] : null,
            ];
        }

        $sku = isset($detail['sku']) ? (int) $detail['sku'] : null;
        $fboSku = isset($detail['fbo_sku']) ? (int) $detail['fbo_sku'] : null;
        $fbsSku = isset($detail['fbs_sku']) ? (int) $detail['fbs_sku'] : null;

        $allSkus = array_values(array_unique(array_filter(array_merge(
            $sku ? [$sku] : [],
            $fboSku ? [$fboSku] : [],
            $fbsSku ? [$fbsSku] : [],
            array_map(static fn (array $s) => $s['sku'], $sources),
        ))));

        $primaryImage = $detail['primary_image'] ?? null;
        if (is_array($primaryImage)) {
            $primaryImage = $primaryImage[0] ?? null;
        }

        $images = (array) ($detail['images'] ?? []);
        $images = array_values(array_filter(array_map('strval', $images)));

        $barcodes = (array) ($detail['barcodes'] ?? []);
        $barcodes = array_values(array_filter(array_map('strval', $barcodes)));

        $statuses = $detail['statuses'] ?? $detail['status'] ?? null;
        if (! is_array($statuses)) {
            $statuses = $statuses !== null ? ['status' => $statuses] : [];
        }

        return [
            'product_id' => $productId,
            'offer_id' => $offerId !== '' ? $offerId : null,
            'sku' => $sku,
            'skus' => [
                'fbo' => $fboSku,
                'fbs' => $fbsSku,
                'all' => $allSkus,
            ],
            'sources' => $sources,
            'name' => isset($detail['name']) ? (string) $detail['name'] : null,
            'barcodes' => $barcodes,
            'description_category_id' => isset($detail['description_category_id'])
                ? (int) $detail['description_category_id']
                : null,
            'type_id' => isset($detail['type_id']) ? (int) $detail['type_id'] : null,
            'brand' => $brand,
            'images' => $images,
            'primary_image' => $primaryImage !== null ? (string) $primaryImage : ($images[0] ?? null),
            'images360' => array_values(array_filter(array_map(
                static fn ($v) => is_string($v) ? $v : (is_array($v) ? ($v['file_name'] ?? null) : null),
                (array) ($detail['images360'] ?? []),
            ))),
            'visible' => array_key_exists('visible', $detail) ? (bool) $detail['visible'] : null,
            'is_archived' => array_key_exists('is_archived', $detail)
                ? (bool) $detail['is_archived']
                : (array_key_exists('archived', $listItem) ? (bool) $listItem['archived'] : null),
            'is_autoarchived' => array_key_exists('is_autoarchived', $detail)
                ? (bool) $detail['is_autoarchived']
                : null,
            'statuses' => $statuses,
            'price' => isset($detail['price']) ? (string) $detail['price'] : null,
            'old_price' => isset($detail['old_price']) ? (string) $detail['old_price'] : null,
            'marketing_price' => isset($detail['marketing_price']) ? (string) $detail['marketing_price'] : null,
            'min_price' => isset($detail['min_price']) ? (string) $detail['min_price'] : null,
            'price_indexes' => $this->normalizePriceIndexes($detail['price_indexes'] ?? null),
            'commissions' => $this->normalizeCommissions($detail['commissions'] ?? null),
            'promotions' => $this->normalizePromotions($detail['promotions'] ?? null),
            'errors' => $this->normalizeCardErrors($detail['errors'] ?? null),
            'availability' => $this->normalizeAvailability($detail),
            'currency_code' => isset($detail['currency_code']) ? (string) $detail['currency_code'] : null,
            'created_at' => isset($detail['created_at']) ? (string) $detail['created_at'] : null,
            'updated_at' => isset($detail['updated_at']) ? (string) $detail['updated_at'] : null,
            'volume_weight' => isset($detail['volume_weight']) ? (float) $detail['volume_weight'] : null,
            'model_info' => is_array($detail['model_info'] ?? null) ? $detail['model_info'] : null,
            'is_discounted' => array_key_exists('is_discounted', $detail)
                ? (bool) $detail['is_discounted']
                : (array_key_exists('is_discounted', $listItem) ? (bool) $listItem['is_discounted'] : null),
            'is_kgt' => array_key_exists('is_kgt', $detail) ? (bool) $detail['is_kgt'] : null,
            'is_super' => array_key_exists('is_super', $detail) ? (bool) $detail['is_super'] : null,
            'vat' => isset($detail['vat']) ? (string) $detail['vat'] : null,
            'visibility_details' => is_array($detail['visibility_details'] ?? null)
                ? $detail['visibility_details']
                : null,
            'list_flags' => [
                'has_fbo_stocks' => $listItem['has_fbo_stocks'] ?? null,
                'has_fbs_stocks' => $listItem['has_fbs_stocks'] ?? null,
            ],
            'raw' => $detail !== [] ? $detail : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $attrItem
     */
    private function extractBrandFromAttributes(array $attrItem): ?string
    {
        $brandAttributeIds = [85, 31];

        foreach ((array) ($attrItem['attributes'] ?? []) as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            $attrId = (int) ($attribute['id'] ?? $attribute['attribute_id'] ?? 0);
            $values = (array) ($attribute['values'] ?? []);
            $valueText = null;
            foreach ($values as $value) {
                if (is_array($value) && isset($value['value']) && trim((string) $value['value']) !== '') {
                    $valueText = trim((string) $value['value']);
                    break;
                }
                if (is_string($value) && trim($value) !== '') {
                    $valueText = trim($value);
                    break;
                }
            }

            if ($valueText === null) {
                continue;
            }

            if (in_array($attrId, $brandAttributeIds, true)) {
                return $valueText;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizePriceIndexes(mixed $indexes): ?array
    {
        if (! is_array($indexes) || $indexes === []) {
            return null;
        }

        $compactIndex = static function (mixed $block): ?array {
            if (! is_array($block)) {
                return null;
            }
            $minPrice = $block['minimal_price'] ?? $block['min_price'] ?? null;
            $currency = $block['minimal_price_currency'] ?? $block['min_price_currency'] ?? null;
            $value = $block['price_index_value'] ?? $block['price_index'] ?? null;

            if ($minPrice === null && $value === null) {
                return null;
            }

            return [
                'min_price' => $minPrice !== null ? (string) $minPrice : null,
                'currency' => $currency !== null ? (string) $currency : null,
                'value' => is_numeric($value) ? (float) $value : null,
            ];
        };

        return [
            'color_index' => isset($indexes['color_index']) ? (string) $indexes['color_index'] : null,
            'ozon' => $compactIndex($indexes['ozon_index_data'] ?? null),
            'external' => $compactIndex($indexes['external_index_data'] ?? null),
            'self_marketplaces' => $compactIndex($indexes['self_marketplaces_index_data'] ?? null),
        ];
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function normalizeCommissions(mixed $commissions): ?array
    {
        if (! is_array($commissions) || $commissions === []) {
            return null;
        }

        $rows = [];
        foreach ($commissions as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rows[] = [
                'sale_schema' => isset($row['sale_schema']) ? (string) $row['sale_schema'] : null,
                'percent' => is_numeric($row['percent'] ?? null) ? (float) $row['percent'] : null,
                'value' => is_numeric($row['value'] ?? null) ? (float) $row['value'] : null,
                'delivery_amount' => is_numeric($row['delivery_amount'] ?? null) ? (float) $row['delivery_amount'] : null,
                'return_amount' => is_numeric($row['return_amount'] ?? null) ? (float) $row['return_amount'] : null,
            ];
        }

        return $rows !== [] ? $rows : null;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function normalizePromotions(mixed $promotions): ?array
    {
        if (! is_array($promotions) || $promotions === []) {
            return null;
        }

        $rows = [];
        foreach ($promotions as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rows[] = [
                'type' => isset($row['type']) ? (string) $row['type'] : null,
                'is_enabled' => array_key_exists('is_enabled', $row) ? (bool) $row['is_enabled'] : null,
            ];
        }

        return $rows !== [] ? $rows : null;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function normalizeCardErrors(mixed $errors): ?array
    {
        if (! is_array($errors) || $errors === []) {
            return null;
        }

        $rows = [];
        foreach ($errors as $row) {
            if (! is_array($row)) {
                continue;
            }
            $texts = is_array($row['texts'] ?? null) ? $row['texts'] : [];
            $rows[] = [
                'code' => isset($row['code']) ? (string) $row['code'] : null,
                'field' => isset($row['field']) ? (string) $row['field'] : null,
                'level' => isset($row['level']) ? (string) $row['level'] : null,
                'message' => isset($texts['message'])
                    ? (string) $texts['message']
                    : (isset($texts['short_description']) ? (string) $texts['short_description'] : null),
            ];
        }

        return $rows !== [] ? $rows : null;
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    private function normalizeAvailability(array $detail): ?array
    {
        $stocks = is_array($detail['stocks'] ?? null) ? $detail['stocks'] : [];
        $hasStock = array_key_exists('has_stock', $stocks) ? (bool) $stocks['has_stock'] : null;

        $reasons = [];
        foreach ((array) ($detail['availabilities'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach ((array) ($item['reasons'] ?? []) as $reason) {
                if (! is_array($reason)) {
                    continue;
                }
                $text = data_get($reason, 'human_text.text', $reason['human_text'] ?? null);
                if (is_string($text) && trim($text) !== '') {
                    $reasons[] = trim($text);
                }
            }
        }
        $reasons = array_values(array_unique($reasons));

        if ($hasStock === null && $reasons === []) {
            return null;
        }

        return [
            'has_stock' => $hasStock,
            'reasons' => $reasons,
        ];
    }
}
