<?php

namespace App\Services\Oz\AiCabinetAnalyzer;

use App\Services\Oz\AiCabinetAnalyzer\Support\OzAiCabinetAnalyzerRequestGuard;
use App\Services\Ozon\OzonApiService;
use Illuminate\Support\Arr;
use Throwable;

/**
 * POST /v1/analytics/product-queries
 *
 * Free (частично): unique_search_users, gmv, name, offer_id, sku, category.
 * Premium-only (position, unique_view_users, view_conversion) — не опираемся на них.
 */
class OzAiCabinetAnalyzerProductQueriesCollector
{
    private const SKU_BATCH = 1000;

    private const PAGE_SIZE = 100;

    private OzAiCabinetAnalyzerRequestGuard $guard;

    public function __construct(
        private readonly OzonApiService $ozonApiService,
    ) {
        $this->guard = new OzAiCabinetAnalyzerRequestGuard(
            maxAttempts: 3,
            minIntervalMs: 1000,
            rateLimitBackoffMs: 60_000,
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
     * @param  list<int>  $skus
     * @return array{by_sku: array<int, array<string, mixed>>, warnings: list<array<string, mixed>>}
     */
    public function collect(
        string $apiKey,
        string $clientId,
        string $beginDate,
        string $endDate,
        array $skus,
        ?callable $onStage = null,
    ): array {
        $skus = array_values(array_unique(array_filter(array_map('intval', $skus))));
        $bySku = [];
        $warnings = [];

        if ($skus === []) {
            return ['by_sku' => [], 'warnings' => []];
        }

        foreach (array_chunk($skus, self::SKU_BATCH) as $batchIndex => $batch) {
            $onStage && $onStage(sprintf('product_queries_batch_%d', $batchIndex + 1));

            $page = 1;
            $pageCount = 1;

            try {
                while ($page <= $pageCount && $page <= 50) {
                    $payload = [
                        'date_from' => $beginDate,
                        'date_to' => $endDate,
                        'page' => $page,
                        'page_size' => self::PAGE_SIZE,
                        'skus' => array_map('strval', $batch),
                        'sort_by' => 'unique_search_users',
                        'sort_dir' => 'DESC',
                    ];

                    $response = $this->guard->requestWithRetry(
                        fn () => $this->ozonApiService->getProductQueries($apiKey, $clientId, $payload),
                        'analytics/product-queries',
                    );

                    $items = (array) Arr::get($response, 'data.items', []);
                    $pageCount = max(1, (int) Arr::get($response, 'data.page_count', 1));

                    foreach ($items as $item) {
                        if (! is_array($item)) {
                            continue;
                        }
                        $sku = (int) ($item['sku'] ?? 0);
                        if ($sku <= 0) {
                            continue;
                        }

                        $bySku[$sku] = [
                            'unique_search_users' => (int) ($item['unique_search_users'] ?? 0),
                            'gmv' => is_numeric($item['gmv'] ?? null) ? (float) $item['gmv'] : 0.0,
                            'category' => isset($item['category']) ? (string) $item['category'] : null,
                            'name' => isset($item['name']) ? (string) $item['name'] : null,
                            'offer_id' => isset($item['offer_id']) ? (string) $item['offer_id'] : null,
                            // Premium fields may be empty/zero — keep only for completeness, no hard dependency.
                            'position' => is_numeric($item['position'] ?? null) ? (float) $item['position'] : null,
                            'unique_view_users' => isset($item['unique_view_users'])
                                ? (int) $item['unique_view_users']
                                : null,
                            'view_conversion' => is_numeric($item['view_conversion'] ?? null)
                                ? (float) $item['view_conversion']
                                : null,
                        ];
                    }

                    $page++;
                }
            } catch (Throwable $e) {
                $warnings[] = [
                    'type' => 'product_queries_batch_failed',
                    'message' => $e->getMessage(),
                    'batch' => $batchIndex + 1,
                ];
            }
        }

        return ['by_sku' => $bySku, 'warnings' => $warnings];
    }

    /**
     * @return array<string, mixed>
     */
    public static function emptySearchBlock(): array
    {
        return [
            'unique_search_users' => 0,
            'gmv' => 0.0,
            'category' => null,
            'name' => null,
            'offer_id' => null,
            'position' => null,
            'unique_view_users' => null,
            'view_conversion' => null,
        ];
    }
}
