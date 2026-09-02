<?php

namespace App\Services\Oz\AiCabinetAnalyzer;

use App\Services\Ozon\OzonPerformanceApiService;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Реклама Ozon Performance API (https://docs.ozon.ru/api/performance/).
 *
 * Base: https://api-performance.ozon.ru
 * Auth: Bearer via POST /api/client/token (client_credentials).
 * Credentials: Performance Client ID + Secret (НЕ Seller Api-Key).
 *
 * SKU-метрики за период snapshot:
 * - CPC («Оплата за клик», advObjectType=SKU): async POST /api/client/statistics/json
 *   → poll UUID → GET /api/client/statistics/report.
 *   GET /api/client/statistics/campaign/product — итог кампании без SKU, не используем.
 * - CPO («Оплата за заказ», SEARCH_PROMO / all sku promo):
 *   POST /api/client/statistic/products/generate/json, fallback GET all_sku_promo/products/generate/json.
 *
 * Sync POST /api/client/statistics/products/sku даёт SKU, но dateFrom не раньше вчера —
 * для периода snapshot не подходит (им пользуется A/B-тик).
 *
 * API бесплатен. Без Performance-ключей коллектор пропускается (не валит snapshot).
 */
class OzAiCabinetAnalyzerAdsCollector
{
    private const MAX_CAMPAIGN_IDS_PER_STATS = 10;

    /** Максимальный период async-отчёта CPC по документации — 62 дня. */
    private const MAX_CPC_PERIOD_DAYS = 62;

    private const REPORT_WAIT_SECONDS = 300;

    private const REPORT_POLL_START_US = 2_000_000;

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
            $campaigns = [];
            try {
                $campaigns = $this->fetchAllCampaigns($accessToken);
            } catch (Throwable $e) {
                $warnings[] = [
                    'type' => 'advertising_campaigns_failed',
                    'message' => $e->getMessage(),
                ];
            }

            $cpcIds = $this->campaignIdsByType($campaigns, ['SKU']);
            $cpoIds = $this->campaignIdsByType($campaigns, ['SEARCH_PROMO']);
            [$statsBegin, $statsEnd, $clamped] = $this->clampCpcPeriod($beginDate, $endDate);
            if ($clamped) {
                $warnings[] = [
                    'type' => 'advertising_period_clamped',
                    'message' => 'Период рекламной статистики ограничен 62 днями (ограничение Performance API).',
                    'requested' => ['begin_date' => $beginDate, 'end_date' => $endDate],
                    'effective' => ['begin_date' => $statsBegin, 'end_date' => $statsEnd],
                ];
            }

            $bySku = [];

            $onStage && $onStage('advertising_cpc_stats');
            try {
                $this->mergeSkuStats(
                    $bySku,
                    $this->fetchCpcProductStats($accessToken, $cpcIds, $statsBegin, $statsEnd, $onStage),
                );
            } catch (Throwable $e) {
                $warnings[] = [
                    'type' => 'advertising_cpc_failed',
                    'message' => $e->getMessage(),
                ];
            }

            $onStage && $onStage('advertising_cpo_stats');
            $cpoRows = [];
            $cpoFailed = false;
            try {
                $cpoRows = $this->fetchCpoProductStats($accessToken, $statsBegin, $statsEnd, $onStage);
                $this->mergeSkuStats($bySku, $cpoRows);
            } catch (Throwable $e) {
                $cpoFailed = true;
                $warnings[] = [
                    'type' => 'advertising_cpo_failed',
                    'message' => $e->getMessage(),
                ];
            }

            // Единая кампания «все товары» часто не приходит в list как SEARCH_PROMO.
            if ($cpoFailed || ($cpoRows === [] && $cpoIds === [])) {
                try {
                    $fallbackRows = $this->fetchAllSkuPromoProductStats($accessToken, $statsBegin, $statsEnd, $onStage);
                    $this->mergeSkuStats($bySku, $fallbackRows);
                } catch (Throwable $e) {
                    $warnings[] = [
                        'type' => 'advertising_all_sku_promo_failed',
                        'message' => $e->getMessage(),
                    ];
                }
            }

            if ($bySku === [] && ($campaigns !== [] || $cpcIds !== [] || $cpoIds !== [])) {
                $warnings[] = [
                    'type' => 'advertising_stats_empty',
                    'message' => 'Кампании найдены, но статистика по товарам за период пустая. Проверьте тип продвижения (оплата за клик / за заказ) и даты в рекламном кабинете.',
                    'campaigns_count' => count($campaigns),
                    'cpc_campaigns' => count($cpcIds),
                    'cpo_campaigns' => count($cpoIds),
                ];
            }

            return [
                'status' => 'collected',
                'campaigns' => $campaigns,
                'by_sku' => $this->finalizeBySku($bySku),
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
     * @param  list<array<string, mixed>>  $campaigns
     * @param  list<string>  $types
     * @return list<int>
     */
    private function campaignIdsByType(array $campaigns, array $types): array
    {
        $wanted = array_fill_keys($types, true);
        $ids = [];
        foreach ($campaigns as $campaign) {
            $type = strtoupper((string) ($campaign['adv_object_type'] ?? ''));
            $id = (int) ($campaign['id'] ?? 0);
            if ($id > 0 && isset($wanted[$type])) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array{0: string, 1: string, 2: bool}
     */
    private function clampCpcPeriod(string $beginDate, string $endDate): array
    {
        try {
            $begin = Carbon::parse($beginDate)->startOfDay();
            $end = Carbon::parse($endDate)->startOfDay();
        } catch (Throwable) {
            return [$beginDate, $endDate, false];
        }

        if ($end->lt($begin)) {
            return [$endDate, $endDate, true];
        }

        $maxBegin = $end->copy()->subDays(self::MAX_CPC_PERIOD_DAYS - 1);
        if ($begin->lt($maxBegin)) {
            return [$maxBegin->toDateString(), $end->toDateString(), true];
        }

        return [$begin->toDateString(), $end->toDateString(), false];
    }

    /**
     * @param  list<int>  $campaignIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchCpcProductStats(
        string $accessToken,
        array $campaignIds,
        string $beginDate,
        string $endDate,
        ?callable $onStage,
    ): array {
        if ($campaignIds === []) {
            return [];
        }

        $bySku = [];
        foreach (array_chunk($campaignIds, self::MAX_CAMPAIGN_IDS_PER_STATS) as $index => $chunk) {
            $onStage && $onStage('advertising_cpc_stats_'.$index);
            $submit = $this->requestWithRetry(
                fn () => $this->performanceApi->requestStatisticsJson($accessToken, [
                    'campaigns' => array_map(static fn (int $id): string => (string) $id, $chunk),
                    'dateFrom' => $beginDate,
                    'dateTo' => $endDate,
                    'groupBy' => 'NO_GROUP_BY',
                ]),
                'performance/statistics/json',
            );

            $uuid = $this->extractUuid($submit['data'] ?? null);
            if ($uuid === '') {
                throw new \RuntimeException('Performance API: пустой UUID отчёта CPC');
            }

            $report = $this->waitAndDownloadReport($accessToken, $uuid, 'performance/statistics/json');
            $this->mergeSkuStats($bySku, $this->rowsToBySku($this->extractRows($report)));
        }

        return $bySku;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchCpoProductStats(
        string $accessToken,
        string $beginDate,
        string $endDate,
        ?callable $onStage,
    ): array {
        $onStage && $onStage('advertising_cpo_generate');
        $submit = $this->requestWithRetry(
            fn () => $this->performanceApi->generateSearchPromoProductsReportJson($accessToken, [
                'from' => $beginDate.'T00:00:00+03:00',
                'to' => $endDate.'T23:59:59+03:00',
            ]),
            'performance/statistic/products/generate',
        );

        $uuid = $this->extractUuid($submit['data'] ?? null);
        if ($uuid === '') {
            $directRows = $this->extractRows($submit);
            if ($directRows !== []) {
                return $this->rowsToBySku($directRows);
            }

            throw new \RuntimeException('Performance API: пустой UUID отчёта оплаты за заказ');
        }

        $report = $this->waitAndDownloadReport($accessToken, $uuid, 'performance/statistic/products/generate');

        return $this->rowsToBySku($this->extractRows($report));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllSkuPromoProductStats(
        string $accessToken,
        string $beginDate,
        string $endDate,
        ?callable $onStage,
    ): array {
        $onStage && $onStage('advertising_all_sku_promo');
        $submit = $this->requestWithRetry(
            fn () => $this->performanceApi->generateAllSkuPromoProductsReportJson($accessToken, [
                'dateFrom' => $beginDate,
                'dateTo' => $endDate,
            ]),
            'performance/all_sku_promo/products/generate',
        );

        $uuid = $this->extractUuid($submit['data'] ?? null);
        if ($uuid === '') {
            // Иногда GET сразу отдаёт строки, без UUID.
            $directRows = $this->extractRows($submit);
            if ($directRows !== []) {
                return $this->rowsToBySku($directRows);
            }

            throw new \RuntimeException('Performance API: пустой UUID отчёта all sku promo');
        }

        $report = $this->waitAndDownloadReport($accessToken, $uuid, 'performance/all_sku_promo/products/generate');

        return $this->rowsToBySku($this->extractRows($report));
    }

    /**
     * @return array{success?: bool, status?: int, data?: mixed}
     */
    private function waitAndDownloadReport(string $accessToken, string $uuid, string $label): array
    {
        $deadline = microtime(true) + self::REPORT_WAIT_SECONDS;
        $delayUs = self::REPORT_POLL_START_US;
        $lastError = null;

        while (microtime(true) < $deadline) {
            $statusResponse = $this->requestWithRetry(
                fn () => $this->performanceApi->getStatisticsStatus($accessToken, $uuid),
                $label.'/status',
            );

            $state = strtolower((string) Arr::get(
                $statusResponse,
                'data.state',
                Arr::get($statusResponse, 'data.status', Arr::get($statusResponse, 'data.meta.state', '')),
            ));

            if (in_array($state, ['ok', 'success', 'done', 'ready', 'completed'], true)) {
                return $this->requestWithRetry(
                    fn () => $this->performanceApi->downloadStatisticsReport($accessToken, $uuid),
                    $label.'/report',
                );
            }

            if (in_array($state, ['error', 'failed'], true)) {
                $error = Arr::get($statusResponse, 'data.error', Arr::get($statusResponse, 'data.message', 'отчёт не сформирован'));
                throw new \RuntimeException(is_string($error) ? $error : 'Performance API: отчёт не сформирован');
            }

            $lastError = $state !== '' ? $state : 'pending';
            usleep($delayUs);
            $delayUs = min($delayUs + 1_000_000, 8_000_000);
        }

        throw new \RuntimeException('Performance API: таймаут ожидания отчёта ('.$label.', '.$lastError.')');
    }

    private function extractUuid(mixed $data): string
    {
        if (! is_array($data)) {
            return '';
        }

        foreach (['UUID', 'uuid', 'id'] as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return trim((string) Arr::get($data, 'meta.UUID', ''));
    }

    /**
     * @param  array{success?: bool, status?: int, data?: mixed}  $response
     * @return list<array<string, mixed>>
     */
    private function extractRows(array $response): array
    {
        $data = $response['data'] ?? null;
        if (! is_array($data)) {
            return [];
        }

        foreach (['rows', 'report.rows', 'result.rows', 'data.rows'] as $path) {
            $rows = Arr::get($data, $path);
            $normalized = $this->normalizeRowList($rows);
            if ($normalized !== []) {
                return $normalized;
            }
        }

        foreach (['campaigns', 'report.campaigns'] as $path) {
            $campaigns = Arr::get($data, $path);
            if (! is_array($campaigns) || $campaigns === []) {
                continue;
            }
            $collected = [];
            foreach ($campaigns as $campaignId => $block) {
                $blockRows = is_array($block) ? Arr::get($block, 'rows', $block) : null;
                foreach ($this->normalizeRowList($blockRows) as $row) {
                    if (! isset($row['campaignId']) && ! isset($row['campaign_id']) && is_numeric($campaignId)) {
                        $row['campaignId'] = (int) $campaignId;
                    }
                    $collected[] = $row;
                }
            }
            if ($collected !== []) {
                return $collected;
            }
        }

        $raw = $data['raw'] ?? null;
        if (is_string($raw) && trim($raw) !== '') {
            return $this->parseCsvRows($raw);
        }

        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeRowList(mixed $rows): array
    {
        if (! is_array($rows) || $rows === []) {
            return [];
        }
        if (Arr::isAssoc($rows) && $this->rowSku($rows) > 0) {
            return [$rows];
        }
        if (Arr::isAssoc($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function rowsToBySku(array $rows): array
    {
        $bySku = [];
        $this->mergeSkuStats($bySku, $this->accumulateRows($rows));

        return $bySku;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function accumulateRows(array $rows): array
    {
        $bySku = [];
        foreach ($rows as $row) {
            $sku = $this->rowSku($row);
            if ($sku <= 0) {
                continue;
            }
            if (! isset($bySku[$sku])) {
                $bySku[$sku] = self::emptyAdvertisingBlock();
            }

            $bySku[$sku]['views'] += $this->toFloat($this->rowValue($row, ['views', 'Views']));
            $bySku[$sku]['clicks'] += $this->toFloat($this->rowValue($row, ['clicks', 'Clicks']));
            $bySku[$sku]['spend'] += $this->toFloat($this->rowValue($row, [
                'expense',
                'moneySpent',
                'MoneySpent',
                'money_spent',
            ]));
            $bySku[$sku]['orders'] += $this->toFloat($this->rowValue($row, ['orders', 'Orders']));
            $bySku[$sku]['orders_money'] += $this->toFloat($this->rowValue($row, [
                'sales',
                'ordersMoney',
                'OrdersMoney',
                'orders_money',
            ]));
            $bySku[$sku]['to_cart'] += $this->toFloat($this->rowValue($row, ['toCart', 'ToCart', 'to_cart']));

            $campaignId = (int) $this->rowValue($row, ['campaignId', 'campaign_id', 'CampaignId']);
            if ($campaignId > 0) {
                $bySku[$sku]['campaign_ids'][] = $campaignId;
            }
        }

        return $bySku;
    }

    /**
     * @param  array<int, array<string, mixed>>  $target
     * @param  array<int, array<string, mixed>>  $source
     */
    private function mergeSkuStats(array &$target, array $source): void
    {
        foreach ($source as $sku => $row) {
            $sku = (int) $sku;
            if ($sku <= 0) {
                continue;
            }
            if (! isset($target[$sku])) {
                $target[$sku] = self::emptyAdvertisingBlock();
            }
            $target[$sku]['views'] += (float) ($row['views'] ?? 0);
            $target[$sku]['clicks'] += (float) ($row['clicks'] ?? 0);
            $target[$sku]['spend'] += (float) ($row['spend'] ?? 0);
            $target[$sku]['orders'] += (float) ($row['orders'] ?? 0);
            $target[$sku]['orders_money'] += (float) ($row['orders_money'] ?? 0);
            $target[$sku]['to_cart'] += (float) ($row['to_cart'] ?? 0);
            $target[$sku]['campaign_ids'] = array_merge(
                (array) $target[$sku]['campaign_ids'],
                (array) ($row['campaign_ids'] ?? []),
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $bySku
     * @return array<int, array<string, mixed>>
     */
    private function finalizeBySku(array $bySku): array
    {
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

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowSku(array $row): int
    {
        return (int) $this->rowValue($row, ['sku', 'SKU', 'skuId', 'offerSku']);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    private function rowValue(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return $row[$key];
            }
        }

        return 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseCsvRows(string $raw): array
    {
        $lines = preg_split('/\R/u', trim($raw)) ?: [];
        if (count($lines) < 2) {
            return [];
        }

        $headerIndex = 0;
        $delimiter = str_contains($lines[0], ';') ? ';' : ',';
        $headers = [];
        foreach ($lines as $index => $line) {
            $candidate = str_getcsv($line, $delimiter);
            $joined = strtolower(implode(' ', $candidate));
            if (str_contains($joined, 'sku') || str_contains($joined, 'campaign')) {
                $headers = $candidate;
                $headerIndex = $index;
                break;
            }
        }
        if ($headers === []) {
            return [];
        }

        $map = [];
        foreach ($headers as $i => $name) {
            $map[$i] = trim((string) $name);
        }

        $rows = [];
        for ($i = $headerIndex + 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }
            $cols = str_getcsv($line, $delimiter);
            $row = [];
            foreach ($map as $iCol => $name) {
                if ($name === '') {
                    continue;
                }
                $row[$name] = $cols[$iCol] ?? null;
            }
            if ($row !== []) {
                $rows[] = $row;
            }
        }

        return $rows;
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
