<?php

namespace App\Services\Oz\AiCabinetAnalyzer;

use App\Services\Oz\AiCabinetAnalyzer\Support\OzAiCabinetAnalyzerRequestGuard;
use App\Services\Ozon\OzonApiService;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Остатки и оборачиваемость (free Seller API, без Premium-оговорок в доке).
 * - POST /v2/analytics/stock_on_warehouses
 * - POST /v1/analytics/turnover/stocks
 */
class OzAiCabinetAnalyzerStocksCollector
{
    private const PAGE_LIMIT = 1000;

    private const TURNOVER_SKU_BATCH = 100;

    private OzAiCabinetAnalyzerRequestGuard $guard;

    public function __construct(
        private readonly OzonApiService $ozonApiService,
    ) {
        $this->guard = new OzAiCabinetAnalyzerRequestGuard(
            maxAttempts: 3,
            minIntervalMs: 500,
            rateLimitBackoffMs: 30_000,
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
     * @return array{
     *   stocks_by_sku: array<int, array<string, mixed>>,
     *   turnover_by_sku: array<int, array<string, mixed>>,
     *   warnings: list<array<string, mixed>>
     * }
     */
    public function collect(
        string $apiKey,
        string $clientId,
        array $skus,
        ?callable $onStage = null,
    ): array {
        $warnings = [];
        $stocksBySku = [];
        $turnoverBySku = [];

        $onStage && $onStage('stocks_on_warehouses');
        try {
            $stocksBySku = $this->fetchStocksOnWarehouses($apiKey, $clientId, $onStage);
        } catch (Throwable $e) {
            $warnings[] = [
                'type' => 'stocks_fetch_failed',
                'message' => $e->getMessage(),
            ];
        }

        $skus = array_values(array_unique(array_filter(array_map('intval', $skus))));
        if ($skus !== []) {
            $onStage && $onStage('turnover');
            try {
                $turnoverBySku = $this->fetchTurnover($apiKey, $clientId, $skus, $onStage);
            } catch (Throwable $e) {
                $warnings[] = [
                    'type' => 'turnover_fetch_failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'stocks_by_sku' => $stocksBySku,
            'turnover_by_sku' => $turnoverBySku,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchStocksOnWarehouses(string $apiKey, string $clientId, ?callable $onStage): array
    {
        $bySku = [];
        $offset = 0;
        $page = 0;

        while (true) {
            $page++;
            $onStage && $onStage(sprintf('stocks_page_%d', $page));

            $payload = [
                'limit' => self::PAGE_LIMIT,
                'offset' => $offset,
                'warehouse_type' => 'ALL',
            ];

            $response = $this->guard->requestWithRetry(
                fn () => $this->ozonApiService->getStocksOnWarehouses($apiKey, $clientId, $payload),
                'analytics/stock_on_warehouses',
            );

            $rows = (array) Arr::get($response, 'data.result.rows', Arr::get($response, 'data.rows', []));
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $sku = (int) ($row['sku'] ?? 0);
                if ($sku <= 0) {
                    continue;
                }

                if (! isset($bySku[$sku])) {
                    $bySku[$sku] = [
                        'free_to_sell' => 0,
                        'reserved' => 0,
                        'promised' => 0,
                        'item_code' => isset($row['item_code']) ? (string) $row['item_code'] : null,
                        'item_name' => isset($row['item_name']) ? (string) $row['item_name'] : null,
                        'by_warehouse' => [],
                    ];
                }

                $free = (int) ($row['free_to_sell_amount'] ?? 0);
                $reserved = (int) ($row['reserved_amount'] ?? 0);
                $promised = (int) ($row['promised_amount'] ?? 0);

                $bySku[$sku]['free_to_sell'] += $free;
                $bySku[$sku]['reserved'] += $reserved;
                $bySku[$sku]['promised'] += $promised;
                $bySku[$sku]['by_warehouse'][] = [
                    'warehouse_name' => isset($row['warehouse_name']) ? (string) $row['warehouse_name'] : null,
                    'free_to_sell' => $free,
                    'reserved' => $reserved,
                    'promised' => $promised,
                ];
            }

            if (count($rows) < self::PAGE_LIMIT) {
                break;
            }
            $offset += self::PAGE_LIMIT;
            if ($page > 200) {
                break;
            }
        }

        return $bySku;
    }

    /**
     * @param  list<int>  $skus
     * @return array<int, array<string, mixed>>
     */
    private function fetchTurnover(string $apiKey, string $clientId, array $skus, ?callable $onStage): array
    {
        $bySku = [];

        foreach (array_chunk($skus, self::TURNOVER_SKU_BATCH) as $batchIndex => $batch) {
            $onStage && $onStage(sprintf('turnover_batch_%d', $batchIndex + 1));

            $payload = [
                'sku' => array_map('strval', $batch),
            ];

            $response = $this->guard->requestWithRetry(
                fn () => $this->ozonApiService->getProductTurnover($apiKey, $clientId, $payload),
                'analytics/turnover/stocks',
            );

            $items = (array) Arr::get($response, 'data.items', []);
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                // Response may not include sku in older clients — try common keys.
                $sku = (int) ($item['sku'] ?? $item['SKU'] ?? 0);
                // Some responses are ordered by request; if no sku, skip.
                if ($sku <= 0) {
                    continue;
                }

                $bySku[$sku] = [
                    'ads' => is_numeric($item['ads'] ?? null) ? (float) $item['ads'] : 0.0,
                    'current_stock' => (int) ($item['current_stock'] ?? 0),
                    'idc' => is_numeric($item['idc'] ?? null) ? (float) $item['idc'] : 0.0,
                    'idc_grade' => isset($item['idc_grade']) ? (string) $item['idc_grade'] : null,
                ];
            }
        }

        return $bySku;
    }

    /**
     * @return array<string, mixed>
     */
    public static function emptyStocksBlock(): array
    {
        return [
            'free_to_sell' => 0,
            'reserved' => 0,
            'promised' => 0,
            'item_code' => null,
            'item_name' => null,
            'by_warehouse' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function emptyTurnoverBlock(): array
    {
        return [
            'ads' => 0.0,
            'current_stock' => 0,
            'idc' => 0.0,
            'idc_grade' => null,
        ];
    }
}
