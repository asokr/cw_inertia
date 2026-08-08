<?php

namespace App\Services\Oz\AiCabinetAnalyzer;

use App\Models\Subscribers\Oz\AiCabinetAnalyzer\OzAiCabinetAnalyzerReport;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Оркестратор snapshot Ozon AI Cabinet Analyzer (этап 2).
 *
 * Источники:
 * - products (каталог)
 * - analytics (free: revenue, ordered_units)
 * - product_queries / search (free-поля)
 * - stocks + turnover
 * - advertising (Performance API, optional credentials)
 *
 * Premium funnel-метрики и отзывы не собираются.
 */
class OzAiCabinetAnalyzerService
{
    private ?OzAiCabinetAnalyzerReport $heartbeatReport = null;

    private float $lastHeartbeatAt = 0.0;

    public function __construct(
        private readonly OzAiCabinetAnalyzerProductsCollector $productsCollector,
        private readonly OzAiCabinetAnalyzerAnalyticsCollector $analyticsCollector,
        private readonly OzAiCabinetAnalyzerProductQueriesCollector $productQueriesCollector,
        private readonly OzAiCabinetAnalyzerStocksCollector $stocksCollector,
        private readonly OzAiCabinetAnalyzerAdsCollector $adsCollector,
    ) {}

    /**
     * @return array{
     *   meta: array<string, mixed>,
     *   campaigns: list<array<string, mixed>>,
     *   products: list<array<string, mixed>>
     * }
     */
    public function collectReport(
        string $apiKey,
        string $clientId,
        string $beginDate,
        string $endDate,
        bool $defaultsApplied = false,
        ?OzAiCabinetAnalyzerReport $heartbeatReport = null,
        ?string $performanceClientId = null,
        ?string $performanceClientSecret = null,
    ): array {
        $this->heartbeatReport = $heartbeatReport;
        $this->lastHeartbeatAt = 0.0;
        $warnings = [];
        $sourcesCollected = [];

        $onStage = function (string $stage): void {
            $this->touchHeartbeat($stage);
        };

        // 1. Каталог
        $productsResult = $this->productsCollector->collect($apiKey, $clientId, $onStage);
        $products = $productsResult['products'];
        $skuToProductId = $productsResult['sku_to_product_id'];
        $warnings = array_merge($warnings, $productsResult['warnings']);
        $sourcesCollected[] = 'products';

        $allSkus = array_keys($skuToProductId);

        // 2. Free analytics
        $this->touchHeartbeat('analytics');
        $analyticsResult = $this->analyticsCollector->collect(
            $apiKey,
            $clientId,
            $beginDate,
            $endDate,
            $onStage,
        );
        $warnings = array_merge($warnings, $analyticsResult['warnings']);
        $analyticsPeriod = $analyticsResult['period'];
        $periodClamped = (bool) $analyticsResult['period_clamped'];
        if ($analyticsResult['by_sku'] !== [] || empty(array_filter(
            $analyticsResult['warnings'],
            static fn (array $w): bool => ($w['type'] ?? '') === 'analytics_fetch_failed'
        ))) {
            $sourcesCollected[] = 'analytics';
        }

        // 3. Product queries (search demand)
        $this->touchHeartbeat('product_queries');
        $queriesResult = $this->productQueriesCollector->collect(
            $apiKey,
            $clientId,
            $analyticsPeriod['begin_date'],
            $analyticsPeriod['end_date'],
            $allSkus,
            $onStage,
        );
        $warnings = array_merge($warnings, $queriesResult['warnings']);
        if ($queriesResult['by_sku'] !== [] || empty(array_filter(
            $queriesResult['warnings'],
            static fn (array $w): bool => str_contains((string) ($w['type'] ?? ''), 'failed')
        ))) {
            $sourcesCollected[] = 'product_queries';
        }

        // 4. Stocks + turnover
        $this->touchHeartbeat('stocks');
        $stocksResult = $this->stocksCollector->collect($apiKey, $clientId, $allSkus, $onStage);
        $warnings = array_merge($warnings, $stocksResult['warnings']);
        if ($stocksResult['stocks_by_sku'] !== [] || $stocksResult['turnover_by_sku'] !== []) {
            $sourcesCollected[] = 'stocks';
        }

        // 5. Advertising (optional Performance keys)
        $this->touchHeartbeat('advertising');
        $adsResult = $this->adsCollector->collect(
            $performanceClientId,
            $performanceClientSecret,
            $beginDate,
            $endDate,
            $onStage,
        );
        $warnings = array_merge($warnings, $adsResult['warnings']);
        if ($adsResult['status'] === 'collected') {
            $sourcesCollected[] = 'advertising';
        }

        // 6. Merge into products[]
        $this->touchHeartbeat('merge');
        $productsById = [];
        foreach ($products as $product) {
            $productId = (int) ($product['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $productsById[$productId] = $product;
        }

        $orphanAnalyticsSkus = [];
        $orphanAdsSkus = [];

        foreach ($productsById as $productId => $product) {
            $skus = array_values(array_unique(array_filter(array_map(
                'intval',
                array_merge(
                    [(int) ($product['sku'] ?? 0)],
                    (array) data_get($product, 'skus.all', []),
                ),
            ))));

            // Analytics: sum across all SKUs of the product
            $revenue = 0.0;
            $orderedUnits = 0.0;
            foreach ($skus as $sku) {
                if (isset($analyticsResult['by_sku'][$sku])) {
                    $revenue += (float) $analyticsResult['by_sku'][$sku]['revenue'];
                    $orderedUnits += (float) $analyticsResult['by_sku'][$sku]['ordered_units'];
                }
            }
            $product['analytics'] = [
                'revenue' => round($revenue, 2),
                'ordered_units' => round($orderedUnits, 4),
                'period' => $analyticsPeriod,
            ];

            // Search: take best (max unique_search_users) among product SKUs
            $search = OzAiCabinetAnalyzerProductQueriesCollector::emptySearchBlock();
            $bestSearchUsers = -1;
            foreach ($skus as $sku) {
                if (! isset($queriesResult['by_sku'][$sku])) {
                    continue;
                }
                $candidate = $queriesResult['by_sku'][$sku];
                $users = (int) ($candidate['unique_search_users'] ?? 0);
                if ($users > $bestSearchUsers) {
                    $bestSearchUsers = $users;
                    $search = $candidate;
                }
            }
            $product['search'] = $search;

            // Stocks / turnover: sum free_to_sell; first non-empty turnover
            $stocks = OzAiCabinetAnalyzerStocksCollector::emptyStocksBlock();
            $turnover = OzAiCabinetAnalyzerStocksCollector::emptyTurnoverBlock();
            foreach ($skus as $sku) {
                if (isset($stocksResult['stocks_by_sku'][$sku])) {
                    $row = $stocksResult['stocks_by_sku'][$sku];
                    $stocks['free_to_sell'] += (int) ($row['free_to_sell'] ?? 0);
                    $stocks['reserved'] += (int) ($row['reserved'] ?? 0);
                    $stocks['promised'] += (int) ($row['promised'] ?? 0);
                    $stocks['by_warehouse'] = array_merge(
                        $stocks['by_warehouse'],
                        (array) ($row['by_warehouse'] ?? []),
                    );
                    if ($stocks['item_code'] === null && ! empty($row['item_code'])) {
                        $stocks['item_code'] = $row['item_code'];
                    }
                    if ($stocks['item_name'] === null && ! empty($row['item_name'])) {
                        $stocks['item_name'] = $row['item_name'];
                    }
                }
                if (isset($stocksResult['turnover_by_sku'][$sku]) && ($turnover['current_stock'] ?? 0) === 0) {
                    $turnover = $stocksResult['turnover_by_sku'][$sku];
                }
            }
            $product['stocks'] = $stocks;
            $product['turnover'] = $turnover;

            // Advertising
            $ads = OzAiCabinetAnalyzerAdsCollector::emptyAdvertisingBlock();
            foreach ($skus as $sku) {
                if (! isset($adsResult['by_sku'][$sku])) {
                    continue;
                }
                $row = $adsResult['by_sku'][$sku];
                $ads['views'] += (int) ($row['views'] ?? 0);
                $ads['clicks'] += (int) ($row['clicks'] ?? 0);
                $ads['to_cart'] += (int) ($row['to_cart'] ?? 0);
                $ads['spend'] += (float) ($row['spend'] ?? 0);
                $ads['orders'] += (int) ($row['orders'] ?? 0);
                $ads['orders_money'] += (float) ($row['orders_money'] ?? 0);
                $ads['campaign_ids'] = array_merge($ads['campaign_ids'], (array) ($row['campaign_ids'] ?? []));
            }
            $ads['campaign_ids'] = array_values(array_unique(array_map('intval', $ads['campaign_ids'])));
            sort($ads['campaign_ids']);
            $ads['campaigns_count'] = count($ads['campaign_ids']);
            $ads['spend'] = round($ads['spend'], 2);
            $ads['orders_money'] = round($ads['orders_money'], 2);
            $ads['ctr'] = $ads['views'] > 0 ? round(($ads['clicks'] / $ads['views']) * 100, 4) : 0.0;
            $ads['cpc'] = $ads['clicks'] > 0 ? round($ads['spend'] / $ads['clicks'], 4) : 0.0;
            $ads['cr'] = $ads['clicks'] > 0 ? round(($ads['orders'] / $ads['clicks']) * 100, 4) : 0.0;
            $product['advertising'] = $ads;

            $funnelOrders = (int) round((float) $product['analytics']['ordered_units']);
            $adsOrders = (int) $ads['orders'];
            $product['ads_vs_analytics'] = [
                'orders_gap' => $adsOrders - $funnelOrders,
                'orders_ratio_ads_to_analytics' => $funnelOrders > 0
                    ? round($adsOrders / $funnelOrders, 4)
                    : null,
            ];

            $productsById[$productId] = $product;
        }

        // Orphans: analytics/ads SKUs without catalog product
        foreach ($analyticsResult['by_sku'] as $sku => $_) {
            if (! isset($skuToProductId[(int) $sku])) {
                $orphanAnalyticsSkus[] = (int) $sku;
            }
        }
        foreach ($adsResult['by_sku'] as $sku => $_) {
            if (! isset($skuToProductId[(int) $sku])) {
                $orphanAdsSkus[] = (int) $sku;
            }
        }

        if ($orphanAnalyticsSkus !== []) {
            $warnings[] = [
                'type' => 'analytics_orphan_skus',
                'message' => 'Часть SKU из analytics не сопоставлена с каталогом.',
                'count' => count($orphanAnalyticsSkus),
                'sample' => array_slice($orphanAnalyticsSkus, 0, 20),
            ];
        }
        if ($orphanAdsSkus !== []) {
            $warnings[] = [
                'type' => 'advertising_orphan_skus',
                'message' => 'Часть SKU из рекламы не сопоставлена с каталогом.',
                'count' => count($orphanAdsSkus),
                'sample' => array_slice($orphanAdsSkus, 0, 20),
            ];
        }

        $productsList = array_values($productsById);
        usort($productsList, static function (array $a, array $b): int {
            return ((int) ($a['product_id'] ?? 0)) <=> ((int) ($b['product_id'] ?? 0));
        });

        $requestCount = $this->productsCollector->requestCount()
            + $this->analyticsCollector->requestCount()
            + $this->productQueriesCollector->requestCount()
            + $this->stocksCollector->requestCount()
            + $this->adsCollector->requestCount();

        $retryCount = $this->productsCollector->retryCount()
            + $this->analyticsCollector->retryCount()
            + $this->productQueriesCollector->retryCount()
            + $this->stocksCollector->retryCount()
            + $this->adsCollector->retryCount();

        return [
            'meta' => [
                'generated_at' => now()->toDateTimeString(),
                'period' => [
                    'begin_date' => $beginDate,
                    'end_date' => $endDate,
                ],
                'analytics_period' => $analyticsPeriod,
                'period_clamped' => $periodClamped,
                'defaults_applied' => $defaultsApplied,
                'sources_collected' => array_values(array_unique($sourcesCollected)),
                'analytics_tier' => 'free',
                // Premium funnel metrics excluded by design (see AnalyticsCollector).
                'premium_metrics_excluded' => true,
                'products_count' => count($productsList),
                'analytics_skus_with_data' => (int) $analyticsResult['skus_with_data'],
                'product_queries_skus_with_data' => count($queriesResult['by_sku']),
                'advertising_status' => (string) $adsResult['status'],
                'campaigns_count' => count($adsResult['campaigns']),
                'warnings' => $warnings,
                'api' => [
                    'request_count' => $requestCount,
                    'retry_count' => $retryCount,
                    'rate_limit_profile' => [
                        'seller' => 'min_interval ~350ms, 429 backoff',
                        'analytics' => 'min_interval 1.2s, 429 backoff 60s; free metrics only',
                        'performance' => 'api-performance.ozon.ru Bearer; min_interval ~400ms; 429 backoff 60s',
                    ],
                    'endpoints' => [
                        'list' => 'POST /v3/product/list',
                        'info' => 'POST /v3/product/info/list',
                        'attributes' => 'POST /v4/product/info/attributes',
                        'analytics' => 'POST /v1/analytics/data',
                        'product_queries' => 'POST /v1/analytics/product-queries',
                        'stocks' => 'POST /v2/analytics/stock_on_warehouses',
                        'turnover' => 'POST /v1/analytics/turnover/stocks',
                        'performance_token' => 'POST https://api-performance.ozon.ru/api/client/token',
                        'performance_campaigns' => 'GET https://api-performance.ozon.ru/api/client/campaign',
                        'performance_product_stats' => 'GET https://api-performance.ozon.ru/api/client/statistics/campaign/product',
                    ],
                    'free_analytics_metrics' => ['revenue', 'ordered_units'],
                ],
            ],
            'campaigns' => $adsResult['campaigns'],
            'products' => $productsList,
        ];
    }

    private function touchHeartbeat(string $stage): void
    {
        $now = microtime(true);
        if ($this->heartbeatReport === null) {
            return;
        }

        if ($this->lastHeartbeatAt > 0 && ($now - $this->lastHeartbeatAt) < 15) {
            return;
        }

        $this->lastHeartbeatAt = $now;

        try {
            $payload = is_array($this->heartbeatReport->result_json)
                ? $this->heartbeatReport->result_json
                : [];
            data_set($payload, 'meta.heartbeat_stage', $stage);
            data_set($payload, 'meta.heartbeat_at', now()->toDateTimeString());
            $this->heartbeatReport->result_json = $payload;
            $this->heartbeatReport->touch();
            $this->heartbeatReport->save();
        } catch (Throwable $e) {
            Log::debug('[OzAiCabinetAnalyzer] heartbeat failed', [
                'stage' => $stage,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
