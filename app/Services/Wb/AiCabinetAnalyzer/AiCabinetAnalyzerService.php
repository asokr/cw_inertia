<?php

namespace App\Services\Wb\AiCabinetAnalyzer;

use App\Services\Wb\WbPriceCalculationService;
use App\Support\Wb\WbBasketHost;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class AiCabinetAnalyzerService
{
    private const BASE_URL = 'https://advert-api.wildberries.ru';
    private const MAX_BATCH_SIZE = 50;
    private const MAX_ATTEMPTS = 4;
    private const FULLSTATS_ALLOWED_STATUSES = [9, 11];
    private const PERSONAL_TOKEN_MAX_REQUESTS_PER_MINUTE = 3;
    private const PERSONAL_TOKEN_MIN_INTERVAL_SECONDS = 20;
    private const FUNNEL_TOKEN_MAX_REQUESTS_PER_MINUTE = 1;
    private const FUNNEL_TOKEN_MIN_INTERVAL_SECONDS = 60;
    private const FUNNEL_NMIDS_BATCH_SIZE = 100;
    private const FUNNEL_PAGE_LIMIT = 1000;
    private const FUNNEL_MAX_ATTEMPTS = 3;

    private int $requestCount = 0;
    private int $retryCount = 0;
    private int $funnelRequestCount = 0;
    private int $funnelRetryCount = 0;
    private array $requestTimelineByToken = [];
    private array $lastRequestAtByToken = [];
    private array $funnelRequestTimelineByToken = [];
    private array $funnelLastRequestAtByToken = [];

    public function __construct(
        private readonly WbPriceCalculationService $wbPriceCalculationService,
        private readonly ReviewProductStatisticAggregator $reviewProductStatisticAggregator,
        private readonly AiCabinetAnalyzerFeedbacksCollector $feedbacksCollector,
    ) {}

    public function collectReport(string $apiKey, string $beginDate, string $endDate): array
    {
        $warnings = [];

        $catalogMeta = $this->fetchCabinetCatalogMeta($apiKey, $warnings);
        $allCabinetNmids = $catalogMeta['nmids'];
        $vendorCodesByNmid = $catalogMeta['vendor_codes'];
        $salesFunnelMap = $this->fetchSalesFunnelMap($apiKey, $allCabinetNmids, $beginDate, $endDate, $warnings);

        $advertIds = $this->fetchAdvertIds($apiKey);
        if (empty($advertIds)) {
            throw new RuntimeException('Не удалось получить рекламные кампании или список кампаний пуст.');
        }

        $campaignNmids = $this->fetchCampaignNmids($apiKey, $advertIds, $warnings);
        $campaignStats = $this->fetchCampaignStats($apiKey, $advertIds, $beginDate, $endDate, $warnings);

        $campaigns = [];
        $itemsMap = [];

        foreach ($advertIds as $advertId) {
            $nmids = array_values(array_unique(array_map('intval', $campaignNmids[$advertId] ?? [])));
            $stats = $this->normalizeStats($campaignStats[$advertId] ?? []);

            $campaigns[] = [
                'advert_id' => (int) $advertId,
                'nmids' => $nmids,
                'stats' => $stats,
                'has_nmids' => !empty($nmids),
                'has_stats' => isset($campaignStats[$advertId]),
            ];

            foreach ($nmids as $nmid) {
                if (!isset($itemsMap[$nmid])) {
                    $itemsMap[$nmid] = [
                        'nmid' => $nmid,
                        'advert_ids' => [],
                        'campaigns_count' => 0,
                        'clicks' => 0,
                        'views' => 0,
                        'spend' => 0.0,
                        'orders' => 0,
                    ];
                }

                $itemsMap[$nmid]['advert_ids'][] = (int) $advertId;
                $itemsMap[$nmid]['campaigns_count']++;
                $itemsMap[$nmid]['clicks'] += (int) $stats['clicks'];
                $itemsMap[$nmid]['views'] += (int) $stats['views'];
                $itemsMap[$nmid]['spend'] += (float) $stats['spend'];
                $itemsMap[$nmid]['orders'] += (int) $stats['orders'];
            }
        }

        $itemsMap = $this->mergeAdsAndFunnel($itemsMap, $salesFunnelMap);

        $reviewsByNmid = $this->reviewProductStatisticAggregator->latestByNmids(
            array_map('intval', array_keys($itemsMap)),
        );
        $itemsMap = $this->mergeReviewsIntoItems($itemsMap, $reviewsByNmid);

        ksort($itemsMap);

        $feedbacksResult = $this->feedbacksCollector->collect(
            $apiKey,
            $beginDate,
            $endDate,
            array_map('intval', array_keys($itemsMap)),
        );

        if ($feedbacksResult['failed'] ?? false) {
            $warnings[] = [
                'type' => 'feedbacks_fetch_failed',
                'message' => 'Не удалось получить отзывы WB за период отчёта.',
            ];
        }

        $items = array_map(function (array $item) use ($vendorCodesByNmid): array {
            $nmid = (int) ($item['nmid'] ?? 0);

            $item['advert_ids'] = array_values(array_unique($item['advert_ids']));
            sort($item['advert_ids']);

            $item['vendorCode'] = $vendorCodesByNmid[$nmid] ?? null;

            $item['image'] = $this->buildFirstProductImageUrl($nmid);

            $item['ctr'] = $item['views'] > 0
                ? round(($item['clicks'] / $item['views']) * 100, 4)
                : 0.0;
            $item['cpc'] = $item['clicks'] > 0
                ? round($item['spend'] / $item['clicks'], 4)
                : 0.0;
            $item['cr'] = $item['clicks'] > 0
                ? round(($item['orders'] / $item['clicks']) * 100, 4)
                : 0.0;
            $item['spend'] = round($item['spend'], 2);

            $funnelOrders = (int) data_get($item, 'funnel.order_count', 0);
            $ordersGap = (int) $item['orders'] - $funnelOrders;

            $item['ads_vs_funnel'] = [
                'orders_gap' => $ordersGap,
                'orders_ratio_ads_to_funnel' => $funnelOrders > 0
                    ? round($item['orders'] / $funnelOrders, 4)
                    : null,
            ];

            return $item;
        }, array_values($itemsMap));

        return [
            'meta' => [
                'generated_at' => now()->toDateTimeString(),
                'period' => [
                    'begin_date' => $beginDate,
                    'end_date' => $endDate,
                ],
                'totals' => [
                    'campaigns_count' => count($campaigns),
                    'campaigns_with_nmids' => count(array_filter($campaigns, static fn(array $row): bool => $row['has_nmids'])),
                    'unique_nmids_count' => count($items),
                    'funnel_nmids_total' => count($allCabinetNmids),
                    'funnel_nmids_with_data' => count($salesFunnelMap),
                    'reviews_nmids_with_data' => count($reviewsByNmid),
                    'feedbacks_in_period_count' => (int) ($feedbacksResult['meta']['in_period_for_nmids'] ?? 0),
                    'feedbacks_fetched_count' => (int) ($feedbacksResult['meta']['merged_unique'] ?? 0),
                ],
                // Технические предупреждения оставляем в логах, но не показываем пользователю в UI.
                'warnings' => [],
                'api' => [
                    'request_count' => $this->requestCount,
                    'retry_count' => $this->retryCount,
                    'funnel_request_count' => $this->funnelRequestCount,
                    'funnel_retry_count' => $this->funnelRetryCount,
                    'max_batch_size' => self::MAX_BATCH_SIZE,
                    'funnel_batch_size' => self::FUNNEL_NMIDS_BATCH_SIZE,
                    'rate_limit_profile' => [
                        'ads' => 'personal_token: 3 requests/min, min interval 20s',
                        'funnel' => 'strict: 1 request/min',
                        'feedbacks' => '3 requests/sec, min interval 333ms',
                    ],
                    'feedbacks_request_count' => (int) ($feedbacksResult['meta']['request_count'] ?? 0),
                ],
            ],
            'campaigns' => $campaigns,
            'items' => $items,
            'feedbacks' => $feedbacksResult['items'],
        ];
    }

    private function fetchCabinetCatalogMeta(string $apiKey, array &$warnings): array
    {
        $params = [
            'settings' => [
                'cursor' => [
                    'limit' => 100,
                ],
                'filter' => [
                    'withPhoto' => -1,
                ],
            ],
        ];

        $cardsResponse = $this->wbPriceCalculationService->getAllCards($apiKey, $params);
        $cardsResult = $this->wbPriceCalculationService->parseApiResponse($cardsResponse, 'getAllCards');

        if (!$cardsResult['success']) {
            $error = is_string($cardsResult['data'])
                ? $cardsResult['data']
                : 'Не удалось получить номенклатуру кабинета';

            $warnings[] = [
                'type' => 'cabinet_nomenclature_failed',
                'message' => $error,
                'code' => $cardsResult['code'],
            ];

            throw new RuntimeException('Не удалось получить номенклатуру кабинета для воронки продаж: ' . $error);
        }

        $cards = data_get($cardsResult, 'data.cards', []);
        $nmids = [];
        $vendorCodesByNmid = [];

        foreach ((array) $cards as $card) {
            $nmid = (int) (Arr::get($card, 'nmID'));

            if ($nmid > 0) {
                $nmids[] = $nmid;

                $vendorCode = trim((string) Arr::get($card, 'vendorCode', ''));
                if ($vendorCode !== '' && !isset($vendorCodesByNmid[$nmid])) {
                    $vendorCodesByNmid[$nmid] = $vendorCode;
                }
            }
        }

        $nmids = array_values(array_unique($nmids));
        sort($nmids);

        return [
            'nmids' => $nmids,
            'vendor_codes' => $vendorCodesByNmid,
        ];
    }

    private function fetchSalesFunnelMap(
        string $apiKey,
        array $allCabinetNmids,
        string $beginDate,
        string $endDate,
        array &$warnings,
    ): array {
        if (empty($allCabinetNmids)) {
            return [];
        }

        $begin = Carbon::parse($beginDate);
        $end = Carbon::parse($endDate);

        $batches = array_chunk($allCabinetNmids, self::FUNNEL_NMIDS_BATCH_SIZE);
        $map = [];

        foreach ($batches as $batchIndex => $batchNmids) {
            $offset = 0;

            while (true) {
                try {
                    $payload = $this->requestSalesFunnelPage($apiKey, $begin, $end, $batchNmids, $offset);
                    $rows = $this->extractSalesFunnelRows($payload);

                    if (empty($rows)) {
                        break;
                    }

                    foreach ($rows as $row) {
                        $nmid = (int) (
                            Arr::get($row, 'product.nmId')
                            ?? Arr::get($row, 'product.nmID')
                            ?? Arr::get($row, 'product.nmid')
                            ?? Arr::get($row, 'nmId')
                            ?? Arr::get($row, 'nmID')
                            ?? Arr::get($row, 'nmid')
                            ?? 0
                        );

                        if ($nmid <= 0) {
                            continue;
                        }

                        $map[$nmid] = $this->normalizeFunnelRow($row);
                    }

                    if (count($rows) < self::FUNNEL_PAGE_LIMIT) {
                        break;
                    }

                    $offset += self::FUNNEL_PAGE_LIMIT;
                } catch (Throwable $e) {
                    $warnings[] = [
                        'type' => 'sales_funnel_batch_failed',
                        'batch_index' => $batchIndex + 1,
                        'batch_total' => count($batches),
                        'offset' => $offset,
                        'nmids_count' => count($batchNmids),
                        'message' => $e->getMessage(),
                    ];

                    Log::warning('[AiCabinetAnalyzer] Ошибка батча воронки продаж', [
                        'batch_index' => $batchIndex + 1,
                        'batch_total' => count($batches),
                        'offset' => $offset,
                        'nmids_count' => count($batchNmids),
                        'error' => $e->getMessage(),
                    ]);

                    break;
                }
            }
        }

        return $map;
    }

    private function requestSalesFunnelPage(
        string $apiKey,
        Carbon $begin,
        Carbon $end,
        array $nmids,
        int $offset,
    ): array {
        $tokenScope = hash('sha256', trim($apiKey));
        $lastError = 'Ошибка запроса к воронке продаж';

        for ($attempt = 1; $attempt <= self::FUNNEL_MAX_ATTEMPTS; $attempt++) {
            $this->throttleFunnelToken($tokenScope);
            $this->funnelRequestCount++;

            $response = $this->wbPriceCalculationService->getSalesFunnelProducts($apiKey, $begin, $end, [
                'nmIds' => $nmids,
                'skipDeletedNm' => true,
                'limit' => self::FUNNEL_PAGE_LIMIT,
                'offset' => $offset,
            ]);

            $parsed = $this->wbPriceCalculationService->parseApiResponse($response, 'getSalesFunnelProducts');

            if ($parsed['success']) {
                $payload = $parsed['data'];
                if (is_array($payload)) {
                    return $payload;
                }

                return [];
            }

            $lastError = is_string($parsed['data'])
                ? $parsed['data']
                : ('Код ' . (int) $parsed['code'] . ' при запросе воронки продаж');

            if ((int) $parsed['code'] !== 429 || $attempt === self::FUNNEL_MAX_ATTEMPTS) {
                throw new RuntimeException($lastError);
            }

            $this->funnelRetryCount++;
        }

        throw new RuntimeException($lastError);
    }

    private function extractSalesFunnelRows(array $payload): array
    {
        $candidates = [
            Arr::get($payload, 'data.products'),
            Arr::get($payload, 'products'),
            Arr::get($payload, 'cards'),
            Arr::get($payload, 'items'),
            Arr::get($payload, 'data.cards'),
            Arr::get($payload, 'data.items'),
            Arr::get($payload, 'data'),
            $payload,
        ];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate) || empty($candidate)) {
                continue;
            }

            if (array_is_list($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    private function normalizeFunnelRow(array $row): array
    {
        $selected = Arr::get($row, 'statistic.selected', []);
        $conversions = Arr::get($selected, 'conversions', []);
        $wbClub = Arr::get($selected, 'wbClub', []);
        $timeToReady = Arr::get($selected, 'timeToReady', []);

        $openCount = $this->firstNumericValue($selected, ['openCount', 'open_count']);
        $cartCount = $this->firstNumericValue($selected, ['cartCount', 'cart_count']);
        $orderCount = $this->firstNumericValue($selected, ['orderCount', 'orders', 'ordersCount']);

        return [
            'period' => [
                'selected' => [
                    'start' => (string) Arr::get($selected, 'period.start', ''),
                    'end' => (string) Arr::get($selected, 'period.end', ''),
                ],
            ],
            'open_count' => (int) round($openCount),
            'cart_count' => (int) round($cartCount),
            'order_count' => (int) round($orderCount),
            'order_sum' => (int) round($this->firstNumericValue($selected, ['orderSum'])),
            'buyout_count' => (int) round($this->firstNumericValue($selected, ['buyoutCount'])),
            'buyout_sum' => (int) round($this->firstNumericValue($selected, ['buyoutSum'])),
            'cancel_count' => (int) round($this->firstNumericValue($selected, ['cancelCount'])),
            'cancel_sum' => (int) round($this->firstNumericValue($selected, ['cancelSum'])),
            'avg_price' => (int) round($this->firstNumericValue($selected, ['avgPrice'])),
            'avg_orders_count_per_day' => round($this->firstNumericValue($selected, ['avgOrdersCountPerDay']), 4),
            'share_order_percent' => round($this->firstNumericValue($selected, ['shareOrderPercent']), 4),
            'add_to_wishlist' => (int) round($this->firstNumericValue($selected, ['addToWishlist'])),
            'localization_percent' => (int) round($this->firstNumericValue($selected, ['localizationPercent'])),
            'time_to_ready' => [
                'days' => (int) round($this->firstNumericValue($timeToReady, ['days'])),
                'hours' => (int) round($this->firstNumericValue($timeToReady, ['hours'])),
                'mins' => (int) round($this->firstNumericValue($timeToReady, ['mins'])),
            ],
            'wb_club' => [
                'order_count' => (int) round($this->firstNumericValue($wbClub, ['orderCount'])),
                'order_sum' => (int) round($this->firstNumericValue($wbClub, ['orderSum'])),
                'buyout_count' => (int) round($this->firstNumericValue($wbClub, ['buyoutCount'])),
                'buyout_sum' => (int) round($this->firstNumericValue($wbClub, ['buyoutSum'])),
                'cancel_count' => (int) round($this->firstNumericValue($wbClub, ['cancelCount'])),
                'cancel_sum' => (int) round($this->firstNumericValue($wbClub, ['cancelSum'])),
                'avg_price' => (int) round($this->firstNumericValue($wbClub, ['avgPrice'])),
                'buyout_percent' => (int) round($this->firstNumericValue($wbClub, ['buyoutPercent'])),
                'avg_order_count_per_day' => round($this->firstNumericValue($wbClub, ['avgOrderCountPerDay']), 4),
            ],
            'conversions' => [
                'add_to_cart_percent' => (int) round($this->firstNumericValue($conversions, ['addToCartPercent'])),
                'cart_to_order_percent' => (int) round($this->firstNumericValue($conversions, ['cartToOrderPercent'])),
                'buyout_percent' => (int) round($this->firstNumericValue($conversions, ['buyoutPercent'])),
            ],
            'comparison' => Arr::get($row, 'statistic.comparison', []),
            'past' => Arr::get($row, 'statistic.past', []),
            'currency' => (string) Arr::get($row, 'currency', ''),
            'raw_funnel_payload' => $row,
        ];
    }

    private function firstNumericValue(array $row, array $keys): float
    {
        foreach ($keys as $key) {
            $value = Arr::get($row, $key);
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return 0.0;
    }

    private function mergeAdsAndFunnel(array $itemsMap, array $salesFunnelMap): array
    {
        foreach ($salesFunnelMap as $nmid => $funnelData) {
            if (!isset($itemsMap[$nmid])) {
                $itemsMap[$nmid] = [
                    'nmid' => (int) $nmid,
                    'advert_ids' => [],
                    'campaigns_count' => 0,
                    'clicks' => 0,
                    'views' => 0,
                    'spend' => 0.0,
                    'orders' => 0,
                ];
            }

            $itemsMap[$nmid]['funnel'] = $funnelData;
        }

        foreach ($itemsMap as $nmid => $item) {
            if (!isset($itemsMap[$nmid]['funnel'])) {
                $itemsMap[$nmid]['funnel'] = [
                    'period' => [
                        'selected' => [
                            'start' => '',
                            'end' => '',
                        ],
                    ],
                    'open_count' => 0,
                    'cart_count' => 0,
                    'order_count' => 0,
                    'order_sum' => 0,
                    'buyout_count' => 0,
                    'buyout_sum' => 0,
                    'cancel_count' => 0,
                    'cancel_sum' => 0,
                    'avg_price' => 0,
                    'avg_orders_count_per_day' => 0.0,
                    'share_order_percent' => 0.0,
                    'add_to_wishlist' => 0,
                    'localization_percent' => 0,
                    'time_to_ready' => [
                        'days' => 0,
                        'hours' => 0,
                        'mins' => 0,
                    ],
                    'wb_club' => [
                        'order_count' => 0,
                        'order_sum' => 0,
                        'buyout_count' => 0,
                        'buyout_sum' => 0,
                        'cancel_count' => 0,
                        'cancel_sum' => 0,
                        'avg_price' => 0,
                        'buyout_percent' => 0,
                        'avg_order_count_per_day' => 0.0,
                    ],
                    'conversions' => [
                        'add_to_cart_percent' => 0,
                        'cart_to_order_percent' => 0,
                        'buyout_percent' => 0,
                    ],
                    'comparison' => [],
                    'past' => [],
                    'currency' => '',
                    'raw_funnel_payload' => [],
                ];
            }
        }

        return $itemsMap;
    }

    private function mergeReviewsIntoItems(array $itemsMap, array $reviewsByNmid): array
    {
        $emptyReviews = $this->reviewProductStatisticAggregator->emptyReviewsBlock();

        foreach ($itemsMap as $nmid => $item) {
            $itemsMap[$nmid]['reviews'] = $reviewsByNmid[(int) $nmid] ?? $emptyReviews;
        }

        return $itemsMap;
    }

    private function fetchAdvertIds(string $apiKey): array
    {
        $payload = $this->get('/adv/v1/promotion/count', $apiKey, []);

        $adverts = Arr::get($payload, 'adverts', []);
        $groupsTotal = 0;
        $groupsAllowed = 0;
        $ids = [];

        foreach ($adverts as $group) {
            $groupsTotal++;
            $status = (int) Arr::get($group, 'status', 0);

            // Для /adv/v3/fullstats WB документирует работу только по кампаниям в статусах 7/9/11.
            if (!in_array($status, self::FULLSTATS_ALLOWED_STATUSES, true)) {
                continue;
            }

            $groupsAllowed++;

            foreach ((array) Arr::get($group, 'advert_list', []) as $advert) {
                $advertId = (int) Arr::get($advert, 'advertId', 0);
                if ($advertId > 0) {
                    $ids[] = $advertId;
                }
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        Log::info('[AiCabinetAnalyzer] Список advert_id из promotion/count', [
            'allowed_statuses' => self::FULLSTATS_ALLOWED_STATUSES,
            'groups_total' => $groupsTotal,
            'groups_allowed' => $groupsAllowed,
            'count' => count($ids),
            'first_ids' => array_slice($ids, 0, 10),
            'last_ids' => array_slice($ids, -10),
        ]);

        return $ids;
    }

    private function fetchCampaignNmids(string $apiKey, array $advertIds, array &$warnings): array
    {
        $map = [];

        $batches = array_chunk($advertIds, self::MAX_BATCH_SIZE);

        foreach ($batches as $batchIndex => $batch) {
            try {
                $payload = $this->get('/api/advert/v2/adverts', $apiKey, [
                    'ids' => implode(',', $batch),
                ]);

                $adverts = Arr::get($payload, 'adverts', $payload);
                foreach ((array) $adverts as $advert) {
                    $advertId = (int) Arr::get($advert, 'id', 0);
                    if ($advertId <= 0) {
                        continue;
                    }

                    $nmids = [];
                    foreach ((array) Arr::get($advert, 'nm_settings', []) as $nmData) {
                        $nmid = (int) Arr::get($nmData, 'nm_id', 0);
                        if ($nmid > 0) {
                            $nmids[] = $nmid;
                        }
                    }

                    $map[$advertId] = array_values(array_unique($nmids));
                }
            } catch (Throwable $e) {
                $warnings[] = [
                    'type' => 'campaign_compose_batch_failed',
                    'batch_index' => $batchIndex + 1,
                    'batch_total' => count($batches),
                    'advert_ids' => $batch,
                    'message' => $e->getMessage(),
                ];

                Log::warning('[AiCabinetAnalyzer] Ошибка батча состава кампаний', [
                    'batch_index' => $batchIndex + 1,
                    'batch_total' => count($batches),
                    'advert_ids' => $batch,
                    'error' => $e->getMessage(),
                ]);
            }

            usleep(200000);
        }

        return $map;
    }

    private function fetchCampaignStats(string $apiKey, array $advertIds, string $beginDate, string $endDate, array &$warnings): array
    {
        $map = [];

        $batches = array_chunk($advertIds, self::MAX_BATCH_SIZE);

        foreach ($batches as $batchIndex => $batch) {
            try {
                $payload = $this->get('/adv/v3/fullstats', $apiKey, [
                    'ids' => implode(',', $batch),
                    'beginDate' => $beginDate,
                    'endDate' => $endDate,
                ]);

                $rows = Arr::get($payload, 'adverts', $payload);
                foreach ((array) $rows as $statRow) {
                    $advertId = (int) Arr::get($statRow, 'advertId', 0);
                    if ($advertId <= 0) {
                        continue;
                    }

                    $map[$advertId] = $this->normalizeStats($statRow);
                }
            } catch (Throwable $e) {
                $warnings[] = [
                    'type' => 'campaign_stats_batch_failed',
                    'batch_index' => $batchIndex + 1,
                    'batch_total' => count($batches),
                    'advert_ids' => $batch,
                    'message' => $e->getMessage(),
                ];

                Log::warning('[AiCabinetAnalyzer] Ошибка батча статистики кампаний', [
                    'batch_index' => $batchIndex + 1,
                    'batch_total' => count($batches),
                    'advert_ids' => $batch,
                    'error' => $e->getMessage(),
                ]);
            }

            usleep(200000);
        }

        return $map;
    }

    private function normalizeStats(array $row): array
    {
        return [
            'clicks' => (int) Arr::get($row, 'clicks', 0),
            'views' => (int) Arr::get($row, 'views', 0),
            'ctr' => (float) Arr::get($row, 'ctr', 0),
            'cpc' => (float) Arr::get($row, 'cpc', 0),
            'spend' => (float) Arr::get($row, 'sum', Arr::get($row, 'spend', 0)),
            'orders' => (int) Arr::get($row, 'orders', 0),
            'cr' => (float) Arr::get($row, 'cr', 0),
        ];
    }

    private function get(string $uri, string $apiKey, array $query): array
    {
        $url = self::BASE_URL . $uri;
        $lastError = 'Ошибка запроса к WB API';
        $normalizedApiKey = trim($apiKey);
        $tokenScope = hash('sha256', $normalizedApiKey);

        if ($normalizedApiKey === '') {
            throw new RuntimeException('Пустой API-ключ WB. Проверьте поле apikey у кабинета AiCabinet Analyzer.');
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $this->throttlePersonalToken($tokenScope);
            $this->requestCount++;
            $responseStatus = null;

            try {
                $response = Http::timeout(45)
                    ->acceptJson()
                    ->withHeaders([
                        'Authorization' => $normalizedApiKey,
                    ])
                    ->get($url, $query);

                if ($response->successful()) {
                    $rawBody = (string) $response->body();

                    try {
                        $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
                    } catch (JsonException $jsonException) {
                        Log::warning('[AiCabinetAnalyzer] WB API вернул невалидный JSON', [
                            'uri' => $uri,
                            'query' => $query,
                            'status' => $response->status(),
                            'content_type' => $response->header('Content-Type'),
                            'headers' => $response->headers(),
                            'body_length' => strlen($rawBody),
                            'body_preview' => mb_substr($rawBody, 0, 5000),
                            'wb_raw_response' => $rawBody,
                            'json_error' => $jsonException->getMessage(),
                            'attempt' => $attempt,
                            'token_scope' => substr($tokenScope, 0, 12),
                        ]);

                        throw new UnexpectedValueException('WB API вернул невалидный JSON ответ.', 0, $jsonException);
                    }

                    if (!is_array($payload)) {
                        $isJsonNullLiteral = trim($rawBody) === 'null' && $payload === null;

                        Log::warning('[AiCabinetAnalyzer] WB API вернул JSON неожиданного типа', [
                            'uri' => $uri,
                            'query' => $query,
                            'status' => $response->status(),
                            'content_type' => $response->header('Content-Type'),
                            'headers' => $response->headers(),
                            'payload_type' => gettype($payload),
                            'is_json_null_literal' => $isJsonNullLiteral,
                            'body_length' => strlen($rawBody),
                            'body_preview' => mb_substr($rawBody, 0, 5000),
                            'wb_raw_response' => $rawBody,
                            'attempt' => $attempt,
                            'token_scope' => substr($tokenScope, 0, 12),
                        ]);

                        if ($isJsonNullLiteral) {
                            throw new UnexpectedValueException('WB API вернул JSON null (валидный JSON, но не объект/массив).');
                        }

                        throw new UnexpectedValueException('WB API вернул JSON неожиданного типа.');
                    }

                    return $payload;
                }

                $responseStatus = $response->status();
                $body = mb_substr((string) $response->body(), 0, 600);
                $lastError = sprintf('WB API %s вернул статус %d: %s', $uri, $responseStatus, $body);

                if (!$this->isRetryableStatus($responseStatus) || $attempt === self::MAX_ATTEMPTS) {
                    throw new RuntimeException($lastError);
                }
            } catch (Throwable $e) {
                $lastError = $e->getMessage();

                // Невалидный JSON не исправится ретраем мгновенно: отдаем ошибку выше,
                // где она конвертируется в warning конкретного батча.
                if ($e instanceof UnexpectedValueException) {
                    throw new RuntimeException($lastError, 0, $e);
                }

                if ($attempt === self::MAX_ATTEMPTS) {
                    throw new RuntimeException($lastError, 0, $e);
                }
            }

            $this->retryCount++;
            $sleepMs = $this->calculateRetryDelayMs($attempt, $responseStatus);
            usleep($sleepMs * 1000);
        }

        throw new RuntimeException($lastError);
    }

    private function isRetryableStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    private function buildFirstProductImageUrl(int $nmid): ?string
    {
        if ($nmid <= 0) {
            return null;
        }

        $vol = WbBasketHost::vol($nmid);
        $part = WbBasketHost::part($nmid);
        $basket = WbBasketHost::number($vol);

        return sprintf(
            (string) config('wbConstants.URLS.IMAGES.SMALL'),
            $basket,
            $vol,
            $part,
            (string) $nmid,
            1
        );
    }

    private function throttlePersonalToken(string $tokenScope): void
    {
        $now = microtime(true);

        if (!isset($this->requestTimelineByToken[$tokenScope])) {
            $this->requestTimelineByToken[$tokenScope] = [];
        }

        // Очищаем только окно последней минуты.
        $windowStart = $now - 60;
        $this->requestTimelineByToken[$tokenScope] = array_values(array_filter(
            $this->requestTimelineByToken[$tokenScope],
            static fn(float $ts): bool => $ts >= $windowStart
        ));

        if (count($this->requestTimelineByToken[$tokenScope]) >= self::PERSONAL_TOKEN_MAX_REQUESTS_PER_MINUTE) {
            $oldestInWindow = $this->requestTimelineByToken[$tokenScope][0];
            $waitSec = max(0.0, 60 - ($now - $oldestInWindow));
            if ($waitSec > 0) {
                usleep((int) ceil($waitSec * 1_000_000));
                $now = microtime(true);
            }
        }

        $lastRequestAt = $this->lastRequestAtByToken[$tokenScope] ?? null;
        if (is_float($lastRequestAt)) {
            $elapsed = $now - $lastRequestAt;
            $minInterval = (float) self::PERSONAL_TOKEN_MIN_INTERVAL_SECONDS;
            if ($elapsed < $minInterval) {
                $waitSec = $minInterval - $elapsed;
                usleep((int) ceil($waitSec * 1_000_000));
                $now = microtime(true);
            }
        }

        $this->requestTimelineByToken[$tokenScope][] = $now;
        $this->lastRequestAtByToken[$tokenScope] = $now;
    }

    private function throttleFunnelToken(string $tokenScope): void
    {
        $now = microtime(true);

        if (!isset($this->funnelRequestTimelineByToken[$tokenScope])) {
            $this->funnelRequestTimelineByToken[$tokenScope] = [];
        }

        $windowStart = $now - 60;
        $this->funnelRequestTimelineByToken[$tokenScope] = array_values(array_filter(
            $this->funnelRequestTimelineByToken[$tokenScope],
            static fn(float $ts): bool => $ts >= $windowStart
        ));

        if (count($this->funnelRequestTimelineByToken[$tokenScope]) >= self::FUNNEL_TOKEN_MAX_REQUESTS_PER_MINUTE) {
            $oldestInWindow = $this->funnelRequestTimelineByToken[$tokenScope][0];
            $waitSec = max(0.0, 60 - ($now - $oldestInWindow));
            if ($waitSec > 0) {
                usleep((int) ceil($waitSec * 1_000_000));
                $now = microtime(true);
            }
        }

        $lastRequestAt = $this->funnelLastRequestAtByToken[$tokenScope] ?? null;
        if (is_float($lastRequestAt)) {
            $elapsed = $now - $lastRequestAt;
            $minInterval = (float) self::FUNNEL_TOKEN_MIN_INTERVAL_SECONDS;
            if ($elapsed < $minInterval) {
                $waitSec = $minInterval - $elapsed;
                usleep((int) ceil($waitSec * 1_000_000));
                $now = microtime(true);
            }
        }

        $this->funnelRequestTimelineByToken[$tokenScope][] = $now;
        $this->funnelLastRequestAtByToken[$tokenScope] = $now;
    }

    private function calculateRetryDelayMs(int $attempt, ?int $status): int
    {
        $baseMs = (int) (500 * (2 ** ($attempt - 1)) + random_int(50, 250));

        if ($status === 429) {
            return max($baseMs, self::PERSONAL_TOKEN_MIN_INTERVAL_SECONDS * 1000);
        }

        return $baseMs;
    }
}
