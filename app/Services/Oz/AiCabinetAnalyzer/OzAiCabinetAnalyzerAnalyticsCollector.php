<?php

namespace App\Services\Oz\AiCabinetAnalyzer;

use App\Services\Oz\AiCabinetAnalyzer\Support\OzAiCabinetAnalyzerRequestGuard;
use App\Services\Ozon\OzonApiService;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Free-аналитика Ozon: POST /v1/analytics/data
 *
 * Без Premium доступны только:
 * - metrics: revenue, ordered_units
 * - dimensions: sku, spu, day, week, month
 * - глубина: последние 3 месяца
 *
 * Premium-метрики (hits_view*, hits_tocart*, session_*, conv_*, returns,
 * cancellations, delivered_units, position_category) НЕ запрашиваем —
 * недоступны большинству продавцов без отдельной подписки.
 */
class OzAiCabinetAnalyzerAnalyticsCollector
{
    private const PAGE_LIMIT = 1000;

    /** Free-метрики (официальная документация Seller API). */
    private const FREE_METRICS = ['revenue', 'ordered_units'];

    private OzAiCabinetAnalyzerRequestGuard $guard;

    public function __construct(
        private readonly OzonApiService $ozonApiService,
    ) {
        // Analytics rate limits: строже, чем product API.
        $this->guard = new OzAiCabinetAnalyzerRequestGuard(
            maxAttempts: 4,
            minIntervalMs: 1200,
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
     * @return array{
     *   by_sku: array<int, array{revenue: float, ordered_units: float}>,
     *   warnings: list<array<string, mixed>>,
     *   period: array{begin_date: string, end_date: string},
     *   period_clamped: bool,
     *   skus_with_data: int
     * }
     */
    public function collect(
        string $apiKey,
        string $clientId,
        string $beginDate,
        string $endDate,
        ?callable $onStage = null,
    ): array {
        $warnings = [];
        [$effectiveBegin, $effectiveEnd, $clamped] = $this->clampPeriodToFreeWindow($beginDate, $endDate);

        if ($clamped) {
            $warnings[] = [
                'type' => 'analytics_period_clamped',
                'message' => 'Период аналитики ограничен последними 3 месяцами (ограничение Ozon для продавцов без Premium).',
                'requested' => ['begin_date' => $beginDate, 'end_date' => $endDate],
                'effective' => ['begin_date' => $effectiveBegin, 'end_date' => $effectiveEnd],
            ];
        }

        $bySku = [];
        $offset = 0;
        $page = 0;

        try {
            while (true) {
                $page++;
                $onStage && $onStage(sprintf('analytics_page_%d', $page));

                $payload = [
                    'date_from' => $effectiveBegin,
                    'date_to' => $effectiveEnd,
                    'metrics' => self::FREE_METRICS,
                    // sku — free dimension; агрегат за весь период (без day) → меньше строк.
                    'dimension' => ['sku'],
                    'filters' => [],
                    'sort' => [],
                    'limit' => self::PAGE_LIMIT,
                    'offset' => $offset,
                ];

                $response = $this->guard->requestWithRetry(
                    fn () => $this->ozonApiService->getAnalyticsData($apiKey, $clientId, $payload),
                    'analytics/data',
                );

                $rows = (array) Arr::get($response, 'data.result.data', Arr::get($response, 'data.data', []));
                if ($rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $sku = $this->extractSkuFromDimensions((array) ($row['dimensions'] ?? []));
                    if ($sku <= 0) {
                        continue;
                    }

                    $metrics = array_values((array) ($row['metrics'] ?? []));
                    // Порядок metrics совпадает с запросом: [revenue, ordered_units]
                    $revenue = isset($metrics[0]) && is_numeric($metrics[0]) ? (float) $metrics[0] : 0.0;
                    $orderedUnits = isset($metrics[1]) && is_numeric($metrics[1]) ? (float) $metrics[1] : 0.0;

                    if (! isset($bySku[$sku])) {
                        $bySku[$sku] = [
                            'revenue' => 0.0,
                            'ordered_units' => 0.0,
                        ];
                    }

                    $bySku[$sku]['revenue'] += $revenue;
                    $bySku[$sku]['ordered_units'] += $orderedUnits;
                }

                if (count($rows) < self::PAGE_LIMIT) {
                    break;
                }

                $offset += self::PAGE_LIMIT;

                // Защита от бесконечного цикла
                if ($page > 500) {
                    $warnings[] = [
                        'type' => 'analytics_page_cap',
                        'message' => 'Достигнут лимит страниц analytics/data (500).',
                    ];
                    break;
                }
            }
        } catch (Throwable $e) {
            $warnings[] = [
                'type' => 'analytics_fetch_failed',
                'message' => $e->getMessage(),
            ];
        }

        foreach ($bySku as $sku => $row) {
            $bySku[$sku]['revenue'] = round((float) $row['revenue'], 2);
            $bySku[$sku]['ordered_units'] = round((float) $row['ordered_units'], 4);
        }

        return [
            'by_sku' => $bySku,
            'warnings' => $warnings,
            'period' => [
                'begin_date' => $effectiveBegin,
                'end_date' => $effectiveEnd,
            ],
            'period_clamped' => $clamped,
            'skus_with_data' => count($bySku),
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: bool}
     */
    private function clampPeriodToFreeWindow(string $beginDate, string $endDate): array
    {
        $begin = Carbon::parse($beginDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();
        $minBegin = now()->subMonths(3)->startOfDay();

        $clamped = false;
        if ($begin->lt($minBegin)) {
            $begin = $minBegin->copy();
            $clamped = true;
        }

        if ($end->lt($begin)) {
            $end = $begin->copy();
            $clamped = true;
        }

        return [$begin->toDateString(), $end->toDateString(), $clamped];
    }

    /**
     * @param  list<array<string, mixed>>  $dimensions
     */
    private function extractSkuFromDimensions(array $dimensions): int
    {
        foreach ($dimensions as $dim) {
            if (! is_array($dim)) {
                continue;
            }
            $id = (int) ($dim['id'] ?? 0);
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    /**
     * @return array{revenue: float, ordered_units: float, period: array{begin_date: string, end_date: string}}
     */
    public static function emptyAnalyticsBlock(string $beginDate, string $endDate): array
    {
        return [
            'revenue' => 0.0,
            'ordered_units' => 0.0,
            'period' => [
                'begin_date' => $beginDate,
                'end_date' => $endDate,
            ],
        ];
    }
}
