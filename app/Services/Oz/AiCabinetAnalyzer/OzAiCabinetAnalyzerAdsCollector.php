<?php

namespace App\Services\Oz\AiCabinetAnalyzer;

use App\Services\Ozon\OzonPerformanceApiService;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Реклама Ozon Performance API (https://docs.ozon.ru/api/performance/).
 *
 * Base: https://api-performance.ozon.ru
 * Auth: Bearer via POST /api/client/token (client_credentials).
 * Credentials: Performance Client ID + Secret (НЕ Seller Api-Key).
 *
 * API бесплатен; отдельная платная подписка Ozon не требуется.
 * Без Performance-ключей коллектор пропускается (не валит весь snapshot).
 */
class OzAiCabinetAnalyzerAdsCollector
{
    private const MAX_CAMPAIGN_IDS_PER_STATS = 10;

    private int $requestCount = 0;

    private int $retryCount = 0;

    public function __construct(
        private readonly OzonPerformanceApiService $performanceApi,
    ) {}

    public function requestCount(): int
    {
        return $this->requestCount;
    }

    public function retryCount(): int
    {
        return $this->retryCount;
    }

    /**
     * @return array{
     *   status: string,
     *   campaigns: list<array<string, mixed>>,
     *   by_sku: array<int, array<string, mixed>>,
     *   warnings: list<array<string, mixed>>
     * }
     */
    public function collect(
        ?string $performanceClientId,
        ?string $performanceClientSecret,
        string $beginDate,
        string $endDate,
        ?callable $onStage = null,
    ): array {
        $clientId = trim((string) $performanceClientId);
        $clientSecret = trim((string) $performanceClientSecret);

        if ($clientId === '' || $clientSecret === '') {
            return [
                'status' => 'skipped_no_credentials',
                'campaigns' => [],
                'by_sku' => [],
                'warnings' => [[
                    'type' => 'advertising_skipped_no_credentials',
                    'message' => 'Реклама не собрана: не указаны ключи Performance API (Настройки Ozon → Performance API). Seller API-ключ рекламу не открывает.',
                ]],
            ];
        }

        $warnings = [];

        try {
            $onStage && $onStage('advertising_token');
            $tokenResponse = $this->requestWithRetry(
                fn () => $this->performanceApi->getAccessToken($clientId, $clientSecret),
                'performance/token',
            );

            $accessToken = (string) Arr::get($tokenResponse, 'data.access_token', '');
            if ($accessToken === '') {
                throw new \RuntimeException('Performance API: пустой access_token');
            }

            $onStage && $onStage('advertising_campaigns');
            $campaigns = $this->fetchAllCampaigns($accessToken);

            $campaignIds = array_values(array_filter(array_map(
                static fn (array $c): int => (int) ($c['id'] ?? 0),
                $campaigns,
            )));

            $onStage && $onStage('advertising_product_stats');
            $bySku = $this->fetchProductStatsBySku($accessToken, $campaignIds, $beginDate, $endDate);

            // Дополнить campaign_ids в by_sku из rows (уже есть).
            return [
                'status' => 'collected',
                'campaigns' => $campaigns,
                'by_sku' => $bySku,
                'warnings' => $warnings,
            ];
        } catch (Throwable $e) {
            $warnings[] = [
                'type' => 'advertising_fetch_failed',
                'message' => $e->getMessage(),
            ];

            return [
                'status' => 'failed',
                'campaigns' => [],
                'by_sku' => [],
                'warnings' => $warnings,
            ];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAllCampaigns(string $accessToken): array
    {
        $campaigns = [];
        $page = 1;
        $pageSize = 100;

        while ($page <= 50) {
            $response = $this->requestWithRetry(
                fn () => $this->performanceApi->listCampaigns($accessToken, [
                    'page' => $page,
                    'pageSize' => $pageSize,
                ]),
                'performance/campaign',
            );

            $list = Arr::get($response, 'data.list', []);
            if (! is_array($list) || $list === []) {
                break;
            }

            // list can be object (single) or array
            if (Arr::isAssoc($list) && isset($list['id'])) {
                $list = [$list];
            }

            foreach ($list as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $id = (int) ($item['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $campaigns[] = [
                    'id' => $id,
                    'title' => isset($item['title']) ? (string) $item['title'] : null,
                    'state' => isset($item['state']) ? (string) $item['state'] : null,
                    'adv_object_type' => isset($item['advObjectType']) ? (string) $item['advObjectType'] : null,
                    'payment_type' => isset($item['paymentType']) ? (string) $item['paymentType'] : null,
                    'from_date' => isset($item['fromDate']) ? (string) $item['fromDate'] : null,
                    'to_date' => isset($item['toDate']) ? (string) $item['toDate'] : null,
                    'budget' => $item['budget'] ?? null,
                    'daily_budget' => $item['dailyBudget'] ?? null,
                    'weekly_budget' => $item['weeklyBudget'] ?? null,
                    'placement' => $item['placement'] ?? null,
                    'product_campaign_mode' => $item['productCampaignMode'] ?? null,
                ];
            }

            if (count($list) < $pageSize) {
                break;
            }
            $page++;
        }

        return $campaigns;
    }

    /**
     * @param  list<int>  $campaignIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchProductStatsBySku(
        string $accessToken,
        array $campaignIds,
        string $beginDate,
        string $endDate,
    ): array {
        $bySku = [];

        if ($campaignIds === []) {
            // Без filter по campaignIds — один запрос на весь кабинет (если API позволяет).
            $chunks = [[]];
        } else {
            $chunks = array_chunk($campaignIds, self::MAX_CAMPAIGN_IDS_PER_STATS);
        }

        foreach ($chunks as $chunk) {
            $query = [
                'dateFrom' => $beginDate,
                'dateTo' => $endDate,
            ];
            if ($chunk !== []) {
                // Guzzle array query: campaignIds=a&campaignIds=b
                $query['campaignIds'] = $chunk;
            }

            $response = $this->requestWithRetry(
                fn () => $this->performanceApi->getCampaignProductStatistics($accessToken, $query),
                'performance/statistics/campaign/product',
            );

            $rows = (array) Arr::get($response, 'data.rows', []);
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $sku = (int) ($row['sku'] ?? 0);
                if ($sku <= 0) {
                    continue;
                }

                if (! isset($bySku[$sku])) {
                    $bySku[$sku] = self::emptyAdvertisingBlock();
                }

                $views = $this->toFloat($row['views'] ?? 0);
                $clicks = $this->toFloat($row['clicks'] ?? 0);
                $spend = $this->toFloat($row['expense'] ?? $row['moneySpent'] ?? 0);
                $orders = $this->toFloat($row['orders'] ?? 0);
                $ordersMoney = $this->toFloat($row['sales'] ?? $row['ordersMoney'] ?? 0);
                $toCart = $this->toFloat($row['toCart'] ?? 0);
                $campaignId = (int) ($row['campaignId'] ?? 0);

                $bySku[$sku]['views'] += $views;
                $bySku[$sku]['clicks'] += $clicks;
                $bySku[$sku]['spend'] += $spend;
                $bySku[$sku]['orders'] += $orders;
                $bySku[$sku]['orders_money'] += $ordersMoney;
                $bySku[$sku]['to_cart'] += $toCart;

                if ($campaignId > 0) {
                    $bySku[$sku]['campaign_ids'][] = $campaignId;
                }
            }
        }

        foreach ($bySku as $sku => $row) {
            $bySku[$sku]['campaign_ids'] = array_values(array_unique(array_map('intval', $row['campaign_ids'])));
            sort($bySku[$sku]['campaign_ids']);
            $bySku[$sku]['campaigns_count'] = count($bySku[$sku]['campaign_ids']);
            $bySku[$sku]['views'] = (int) round($row['views']);
            $bySku[$sku]['clicks'] = (int) round($row['clicks']);
            $bySku[$sku]['orders'] = (int) round($row['orders']);
            $bySku[$sku]['to_cart'] = (int) round($row['to_cart']);
            $bySku[$sku]['spend'] = round((float) $row['spend'], 2);
            $bySku[$sku]['orders_money'] = round((float) $row['orders_money'], 2);
            $bySku[$sku]['ctr'] = $bySku[$sku]['views'] > 0
                ? round(($bySku[$sku]['clicks'] / $bySku[$sku]['views']) * 100, 4)
                : 0.0;
            $bySku[$sku]['cpc'] = $bySku[$sku]['clicks'] > 0
                ? round($bySku[$sku]['spend'] / $bySku[$sku]['clicks'], 4)
                : 0.0;
            $bySku[$sku]['cr'] = $bySku[$sku]['clicks'] > 0
                ? round(($bySku[$sku]['orders'] / $bySku[$sku]['clicks']) * 100, 4)
                : 0.0;
        }

        return $bySku;
    }

    private function toFloat(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (is_string($value)) {
            $normalized = str_replace([' ', ','], ['', '.'], $value);
            if (is_numeric($normalized)) {
                return (float) $normalized;
            }
        }

        return 0.0;
    }

    /**
     * @param  callable(): array{success?: bool, status?: int, data?: mixed}  $callback
     * @return array{success?: bool, status?: int, data?: mixed}
     */
    private function requestWithRetry(callable $callback, string $label): array
    {
        $attempt = 0;
        $lastError = null;
        $maxAttempts = 4;

        while ($attempt < $maxAttempts) {
            $attempt++;
            $this->requestCount++;

            try {
                $response = $callback();
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                $this->retryCount++;
                usleep(min(2_000_000 * $attempt, 8_000_000));
                continue;
            }

            $status = (int) ($response['status'] ?? 0);
            $success = (bool) ($response['success'] ?? false);

            if ($success) {
                return $response;
            }

            $retryable = $status === 429 || $status >= 500 || $status === 0;
            if (! $retryable || $attempt >= $maxAttempts) {
                $message = (string) Arr::get(
                    $response,
                    'data.message',
                    Arr::get($response, 'data.error', "Performance API error on {$label} (HTTP {$status})")
                );
                throw new \RuntimeException(is_string($message) ? $message : "Performance API error: {$label}");
            }

            $this->retryCount++;
            $sleepMs = $status === 429 ? 60_000 : 2000 * $attempt;
            usleep($sleepMs * 1000);
            $lastError = "HTTP {$status} on {$label}";
        }

        throw new \RuntimeException($lastError ?: "Performance API failed: {$label}");
    }

    /**
     * @return array<string, mixed>
     */
    public static function emptyAdvertisingBlock(): array
    {
        return [
            'campaign_ids' => [],
            'campaigns_count' => 0,
            'views' => 0,
            'clicks' => 0,
            'to_cart' => 0,
            'spend' => 0.0,
            'orders' => 0,
            'orders_money' => 0.0,
            'ctr' => 0.0,
            'cpc' => 0.0,
            'cr' => 0.0,
        ];
    }
}
