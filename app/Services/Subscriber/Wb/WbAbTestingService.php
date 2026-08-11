<?php

namespace App\Services\Subscriber\Wb;

use App\Enums\WbAbTestStatus;
use App\Http\Traits\WBadvTrait;
use App\Jobs\Wb\AbTesting\EnrichAbProductRatingsJob;
use App\Models\Subscribers\Wb\AbTesting\AbCampaign;
use App\Models\Subscribers\Wb\AbTesting\AbExperiment;
use App\Models\Subscribers\Wb\AbTesting\AbExperimentCycle;
use App\Models\Subscribers\Wb\AbTesting\AbExperimentEvent;
use App\Models\Subscribers\Wb\AbTesting\AbExperimentPhoto;
use App\Models\Subscribers\Wb\AbTesting\AbProduct;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Services\Subscriber\Wb\AbTesting\WbAbExperimentEngine;
use App\Services\Subscriber\Wb\AbTesting\WbAbExperimentJournal;
use App\Services\Wb\WbAdvertApiClient;
use App\Services\Wb\WbPriceCalculationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WbAbTestingService
{
    use WBadvTrait;

    /** WB item-rating: ~3 req/min, interval ~20s — keep a safe gap between calls. */
    private const ITEM_RATING_REQUEST_INTERVAL_SECONDS = 22;

    /** Pagination page size for item-rating report (API max 1000). */
    private const ITEM_RATING_PAGE_LIMIT = 1000;

    /** Prices API page size. */
    private const PRICES_PAGE_LIMIT = 1000;

    /** Wizard progress after campaign is bound (steps 1–3 of 5). */
    private const PROGRESS_AFTER_CAMPAIGN = 30;

    /** Wizard progress after at least 2 photos uploaded (step 4). */
    private const PROGRESS_AFTER_PHOTOS = 50;

    /** Wizard progress after settings saved with ≥2 photos. */
    private const PROGRESS_AFTER_SETTINGS = 70;

    /** Maximum photo variants per experiment. */
    public const MAX_PHOTOS = 6;

    /** Minimum photos required to continue past step 4. */
    public const MIN_PHOTOS_TO_CONTINUE = 2;

    public const DEFAULT_IMPRESSIONS_PER_PHOTO = 100_000;

    public const DEFAULT_IMPRESSIONS_PER_ROUND = 10_000;

    public const DEFAULT_ROUND_MINUTES = 60;

    public const DEFAULT_CPM = 350;

    private const PHOTO_DISK = 'private';

    private const PHOTO_MAX_BYTES = 10 * 1024 * 1024;

    /** @var list<string> */
    private const PHOTO_ALLOWED_MIMES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
    ];

    /** Minimum campaign budget deposit per WB (rubles). */
    public const MIN_BUDGET_DEPOSIT = 1000;

    public function __construct(
        private readonly WbPriceCalculationService $wbPriceCalculationService,
        private readonly WbAdvertApiClient $advertApi,
        private readonly WbAbExperimentEngine $experimentEngine,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listProducts(int $cabinetId, Request $request): array
    {
        $perPage = max(1, min(100, $request->integer('per_page', 25)));

        $productsTable = (new AbProduct)->getTable();
        $experimentsTable = (new AbExperiment)->getTable();

        $query = AbProduct::query()
            ->where("{$productsTable}.cabinet_id", $cabinetId)
            ->with('latestExperiment');

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search, $productsTable) {
                $builder->where("{$productsTable}.vendor_code", 'like', "%{$search}%")
                    ->orWhere("{$productsTable}.nm_id", 'like', "%{$search}%");
            });
        }

        // Active work first (running → draft → stopped → error), then completed, then no experiment.
        // Priority is based on the latest experiment (same source as test_status in the UI).
        $statusPrioritySql = <<<SQL
CASE (
    SELECT e.status
    FROM {$experimentsTable} AS e
    WHERE e.ab_product_id = {$productsTable}.id
    ORDER BY e.created_at DESC, e.id DESC
    LIMIT 1
)
    WHEN 'running' THEN 0
    WHEN 'draft' THEN 1
    WHEN 'stopped' THEN 2
    WHEN 'error' THEN 3
    WHEN 'completed' THEN 4
    ELSE 5
END
SQL;

        $query
            ->orderByRaw($statusPrioritySql)
            ->orderBy("{$productsTable}.nm_id");

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage)->withQueryString();

        $items = collect($paginator->items())
            ->map(fn (AbProduct $product) => $this->mapProductRow($product))
            ->values()
            ->all();

        return [
            'items' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @return array{success: bool, messages: list<string>, synced?: int, prices_updated?: int}
     */
    public function syncProducts(WbCabinet $cabinet): array
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

        $cardsResponse = $this->wbPriceCalculationService->getAllCards($cabinet->apikey, $params);
        $cardsResult = $this->wbPriceCalculationService->parseApiResponse($cardsResponse, 'getAllCards');

        if (! ($cardsResult['success'] ?? false)) {
            $message = ($cardsResult['code'] ?? null) === 401
                ? 'Неверный ключ API'
                : (is_string($cardsResult['data'] ?? null)
                    ? $cardsResult['data']
                    : 'Не удалось получить карточки из API Wildberries');

            return [
                'success' => false,
                'messages' => [$message],
            ];
        }

        $cards = data_get($cardsResult['data'], 'cards', []);
        if (! is_array($cards)) {
            $cards = [];
        }

        $syncedNmIds = [];
        $now = now();

        DB::transaction(function () use ($cabinet, $cards, &$syncedNmIds, $now) {
            foreach ($cards as $card) {
                if (! is_array($card)) {
                    continue;
                }

                $nmId = (int) ($card['nmID'] ?? 0);
                if ($nmId <= 0) {
                    continue;
                }

                $syncedNmIds[] = $nmId;

                // Do not touch price/rating here — Content API has neither; preserve existing metrics.
                AbProduct::query()->updateOrCreate(
                    [
                        'cabinet_id' => $cabinet->id,
                        'nm_id' => $nmId,
                    ],
                    [
                        'vendor_code' => $this->nullableString($card['vendorCode'] ?? null),
                        'title' => $this->nullableString($card['title'] ?? null),
                        'brand' => $this->nullableString($card['brand'] ?? null),
                        'subject_name' => $this->nullableString($card['subjectName'] ?? null),
                        'photo_url' => $this->extractPhotoUrl($card['photos'] ?? null),
                        'updated_at' => $now,
                    ]
                );
            }

            $deleteQuery = AbProduct::query()->where('cabinet_id', $cabinet->id);

            if ($syncedNmIds !== []) {
                $deleteQuery->whereNotIn('nm_id', array_unique($syncedNmIds));
            }

            $deleteQuery->delete();
        });

        $count = count(array_unique($syncedNmIds));

        $pricesResult = $this->enrichPricesFromDiscountsApi($cabinet);
        $pricesUpdated = (int) ($pricesResult['updated'] ?? 0);

        if ($count > 0 && ! app()->runningUnitTests()) {
            // Immediate dispatch: afterResponse() often never runs under OpenServer/php-cgi,
            // so the job never lands in the database queue.
            EnrichAbProductRatingsJob::dispatch($cabinet->id);
            Log::info('WB A/B testing: ratings job dispatched', [
                'cabinet_id' => $cabinet->id,
            ]);
        }

        $messages = [];
        if ($count > 0) {
            $messages[] = "Список товаров обновлён. Загружено позиций: {$count}.";
        } else {
            $messages[] = 'Список товаров обновлён. Товары не найдены — проверьте API-ключ кабинета.';
        }

        if ($pricesResult['success'] ?? false) {
            $messages[] = "Цены обновлены: {$pricesUpdated}.";
        } else {
            $priceError = implode(' ', $pricesResult['messages'] ?? []);
            $messages[] = $priceError !== ''
                ? "Цены не обновлены: {$priceError}"
                : 'Цены не обновлены.';
        }

        if ($count > 0) {
            $messages[] = 'Рейтинги обновляются в фоне.';
        }

        return [
            'success' => true,
            'messages' => $messages,
            'synced' => $count,
            'prices_updated' => $pricesUpdated,
        ];
    }

    /**
     * Bulk-load prices from discounts-prices API into wb_ab_products.
     *
     * @return array{success: bool, updated: int, messages: list<string>}
     */
    public function enrichPricesFromDiscountsApi(WbCabinet $cabinet): array
    {
        if (empty($cabinet->apikey)) {
            return [
                'success' => false,
                'updated' => 0,
                'messages' => ['Нет API-ключа кабинета'],
            ];
        }

        if (app()->runningUnitTests() && ! app()->bound('wb.ab.testing.force_price_enrich')) {
            return [
                'success' => true,
                'updated' => 0,
                'messages' => [],
            ];
        }

        $priceByNm = [];
        $offset = 0;
        $page = 0;

        try {
            while (true) {
                $page++;
                $response = $this->parseApiResponse($this->apiGetPrices($cabinet->apikey, [
                    'limit' => self::PRICES_PAGE_LIMIT,
                    'offset' => $offset,
                ]));

                if (! ($response['success'] ?? false)) {
                    $code = (int) ($response['code'] ?? 0);
                    $message = $code === 401
                        ? 'Неверный ключ API (цены)'
                        : 'Не удалось получить цены из API Wildberries';

                    return [
                        'success' => false,
                        'updated' => 0,
                        'messages' => [$message],
                    ];
                }

                $listGoods = data_get($response, 'data.data.listGoods', []);
                if (! is_array($listGoods)) {
                    $listGoods = [];
                }

                foreach ($listGoods as $goods) {
                    if (! is_array($goods)) {
                        continue;
                    }

                    $nmId = (int) ($goods['nmID'] ?? $goods['nmId'] ?? 0);
                    if ($nmId <= 0) {
                        continue;
                    }

                    $price = $this->extractGoodsPrice($goods);
                    if ($price === null) {
                        continue;
                    }

                    $priceByNm[$nmId] = $price;
                }

                if (count($listGoods) < self::PRICES_PAGE_LIMIT) {
                    break;
                }

                $offset += self::PRICES_PAGE_LIMIT;

                // Soft rate-limit between pages (prices API allows more; stay polite).
                if ($page > 1 && ! app()->runningUnitTests()) {
                    usleep(600_000);
                }

                // Safety cap: avoid infinite loops on buggy API.
                if ($page >= 100) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('WB A/B testing: bulk price enrich failed', [
                'cabinet_id' => $cabinet->id,
                'message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'updated' => 0,
                'messages' => ['Ошибка при обновлении цен'],
            ];
        }

        if ($priceByNm === []) {
            return [
                'success' => true,
                'updated' => 0,
                'messages' => [],
            ];
        }

        $updated = 0;
        $now = now();

        foreach (array_chunk($priceByNm, 200, true) as $chunk) {
            foreach ($chunk as $nmId => $price) {
                $affected = AbProduct::query()
                    ->where('cabinet_id', $cabinet->id)
                    ->where('nm_id', $nmId)
                    ->update([
                        'price' => $price,
                        'updated_at' => $now,
                    ]);
                $updated += $affected;
            }
        }

        return [
            'success' => true,
            'updated' => $updated,
            'messages' => [],
        ];
    }

    /**
     * Pull feedback ratings via Analytics item-rating v2 (respect rate limits).
     *
     * @return array{success: bool, updated: int, messages: list<string>}
     */
    public function enrichRatingsFromItemRatingApi(WbCabinet $cabinet): array
    {
        if (empty($cabinet->apikey)) {
            return [
                'success' => false,
                'updated' => 0,
                'messages' => ['Нет API-ключа кабинета'],
            ];
        }

        $updated = 0;
        $offset = 0;
        $page = 0;
        $firstRequest = true;

        try {
            while (true) {
                if (! $firstRequest && ! app()->runningUnitTests()) {
                    sleep(self::ITEM_RATING_REQUEST_INTERVAL_SECONDS);
                }
                $firstRequest = false;
                $page++;

                $response = $this->parseApiResponse($this->apiPostItemRating(
                    $cabinet->apikey,
                    $this->buildItemRatingRequestBody($offset),
                ));

                if (! ($response['success'] ?? false)) {
                    $code = (int) ($response['code'] ?? 0);
                    $detail = is_array($response['data'] ?? null)
                        ? (string) ($response['data']['detail'] ?? $response['data']['title'] ?? $response['data']['message'] ?? '')
                        : (is_string($response['data'] ?? null) ? (string) $response['data'] : '');

                    Log::warning('WB A/B testing: item-rating API error', [
                        'cabinet_id' => $cabinet->id,
                        'code' => $code,
                        'detail' => $detail,
                        'offset' => $offset,
                    ]);

                    if ($code === 429) {
                        return [
                            'success' => false,
                            'updated' => $updated,
                            'messages' => ['Превышен лимит запросов API рейтинга (429)'],
                        ];
                    }

                    if ($code === 401 || $code === 403) {
                        return [
                            'success' => false,
                            'updated' => $updated,
                            'messages' => ['Нет доступа к Analytics API (рейтинг). Проверьте категории токена.'],
                        ];
                    }

                    return [
                        'success' => false,
                        'updated' => $updated,
                        'messages' => [
                            $detail !== ''
                                ? "Не удалось получить рейтинги: {$detail}"
                                : 'Не удалось получить рейтинги из API Wildberries',
                        ],
                    ];
                }

                $items = $this->extractItemRatingRows($response['data'] ?? null);
                if ($items === []) {
                    break;
                }

                $now = now();
                foreach ($items as $item) {
                    $nmId = (int) ($item['nmId'] ?? $item['nmID'] ?? $item['nm_id'] ?? 0);
                    if ($nmId <= 0) {
                        continue;
                    }

                    $rating = $this->extractFeedbackRatingValue($item);
                    if ($rating === null) {
                        continue;
                    }

                    $affected = AbProduct::query()
                        ->where('cabinet_id', $cabinet->id)
                        ->where('nm_id', $nmId)
                        ->update([
                            'rating' => $rating,
                            'rating_updated_at' => $now,
                            'updated_at' => $now,
                        ]);
                    $updated += $affected;
                }

                if (count($items) < self::ITEM_RATING_PAGE_LIMIT) {
                    break;
                }

                $offset += self::ITEM_RATING_PAGE_LIMIT;

                if ($page >= 100) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('WB A/B testing: ratings enrich failed', [
                'cabinet_id' => $cabinet->id,
                'message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'updated' => $updated,
                'messages' => ['Ошибка при обновлении рейтингов'],
            ];
        }

        Log::info('WB A/B testing: ratings enrichment done', [
            'cabinet_id' => $cabinet->id,
            'updated' => $updated,
        ]);

        return [
            'success' => true,
            'updated' => $updated,
            'messages' => [],
        ];
    }

    /**
     * Request body for POST /api/analytics/v2/item-rating.
     * API requires currentPeriod + orderBy; end date cannot be "today".
     *
     * @return array<string, mixed>
     */
    public function buildItemRatingRequestBody(int $offset = 0): array
    {
        $tz = 'Europe/Moscow';
        $end = now($tz)->subDay()->toDateString();
        $start = now($tz)->subDays(30)->toDateString();

        return [
            'currentPeriod' => [
                'start' => $start,
                'end' => $end,
            ],
            'orderBy' => [
                'field' => 'feedbackRating',
                'mode' => 'desc',
            ],
            'limit' => self::ITEM_RATING_PAGE_LIMIT,
            'offset' => max(0, $offset),
        ];
    }

    public function getProductForCabinet(int $cabinetId, int $productId): ?AbProduct
    {
        return AbProduct::query()
            ->where('cabinet_id', $cabinetId)
            ->where('id', $productId)
            ->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listExperiments(int $cabinetId, int $productId): array
    {
        return AbExperiment::query()
            ->where('cabinet_id', $cabinetId)
            ->where('ab_product_id', $productId)
            ->withCount('photos')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AbExperiment $experiment) => $this->mapExperiment($experiment))
            ->values()
            ->all();
    }

    public function createDraftExperiment(WbCabinet $cabinet, AbProduct $product, ?string $name = null): AbExperiment
    {
        $resolvedName = $this->nullableString($name)
            ?? ('Эксперимент от '.now()->timezone(config('app.timezone'))->format('d.m.Y H:i'));

        return AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => $resolvedName,
            'status' => WbAbTestStatus::Draft,
            'progress' => 0,
            'finished_at' => null,
        ]);
    }

    /**
     * At most one experiment may be running for a product (multiple drafts are allowed).
     */
    public function findRunningExperimentForProduct(
        int $cabinetId,
        int $productId,
        ?int $exceptExperimentId = null,
    ): ?AbExperiment {
        $query = AbExperiment::query()
            ->where('cabinet_id', $cabinetId)
            ->where('ab_product_id', $productId)
            ->where('status', WbAbTestStatus::Running->value);

        if ($exceptExperimentId !== null && $exceptExperimentId > 0) {
            $query->where('id', '!=', $exceptExperimentId);
        }

        return $query->orderByDesc('id')->first();
    }

    /**
     * Guard for future start action: only one running experiment per product.
     *
     * @throws ValidationException
     */
    public function assertCanStartExperiment(AbExperiment $experiment): void
    {
        $running = $this->findRunningExperimentForProduct(
            (int) $experiment->cabinet_id,
            (int) $experiment->ab_product_id,
            (int) $experiment->id,
        );

        if ($running) {
            throw ValidationException::withMessages([
                'experiment' => 'По этому товару уже запущен эксперимент «'.$running->name
                    .'». Дождитесь завершения или остановите его, прежде чем запускать другой.',
            ]);
        }
    }

    public function getExperimentForCabinet(int $cabinetId, int $experimentId): ?AbExperiment
    {
        return AbExperiment::query()
            ->where('cabinet_id', $cabinetId)
            ->where('id', $experimentId)
            ->first();
    }

    public function renameExperiment(AbExperiment $experiment, string $name): AbExperiment
    {
        $resolved = $this->nullableString($name);
        if ($resolved === null) {
            throw ValidationException::withMessages([
                'name' => 'Укажите название эксперимента.',
            ]);
        }

        $experiment->name = $resolved;
        $experiment->save();

        return $experiment->refresh();
    }

    /**
     * Soft-enrich product price from WB discounts-prices API. Failures are ignored.
     */
    public function enrichProductPrice(WbCabinet $cabinet, AbProduct $product): AbProduct
    {
        if ($product->price !== null || app()->runningUnitTests()) {
            return $product;
        }

        try {
            $response = $this->parseApiResponse($this->apiGetPrices($cabinet->apikey, [
                'limit' => 1,
                'filterNmID' => (int) $product->nm_id,
            ]));

            if (! ($response['success'] ?? false)) {
                return $product;
            }

            $goods = data_get($response, 'data.data.listGoods.0');
            if (! is_array($goods)) {
                return $product;
            }

            $price = $this->extractGoodsPrice($goods);
            if ($price === null) {
                return $product;
            }

            $product->price = $price;
            $product->save();
        } catch (\Throwable $e) {
            Log::warning('WB A/B testing: failed to enrich product price', [
                'cabinet_id' => $cabinet->id,
                'product_id' => $product->id,
                'nm_id' => $product->nm_id,
                'message' => $e->getMessage(),
            ]);
        }

        return $product;
    }

    /**
     * @return array<string, mixed>
     */
    public function mapProductDetail(AbProduct $product): array
    {
        return $this->mapProductRow($product);
    }

    /**
     * @return array<string, mixed>
     */
    public function mapExperiment(AbExperiment $experiment): array
    {
        $status = $experiment->status instanceof WbAbTestStatus
            ? $experiment->status
            : WbAbTestStatus::tryFrom((string) $experiment->status) ?? WbAbTestStatus::Draft;

        if ($experiment->relationLoaded('photos')) {
            $photosCount = $experiment->photos->count();
        } elseif (isset($experiment->photos_count)) {
            $photosCount = (int) $experiment->photos_count;
        } else {
            $photosCount = (int) $experiment->photos()->count();
        }

        $settings = $this->resolveExperimentSettings($experiment);
        $campaignPaymentType = $this->resolveCampaignPaymentType($experiment);
        $settingsReady = $this->areSettingsPersisted($experiment)
            && $this->areSettingsValid($settings, $campaignPaymentType);
        $canContinueWorkspace = $photosCount >= self::MIN_PHOTOS_TO_CONTINUE && $settingsReady;
        $canEdit = $status->isEditable();
        $canStart = $status->isStartable()
            && $settingsReady
            && $photosCount >= self::MIN_PHOTOS_TO_CONTINUE
            && (bool) $experiment->wb_advert_id;
        $canStop = $status === WbAbTestStatus::Running;

        $photoAggregates = $this->experimentEngine->photoAggregates($experiment);
        $openCycle = $status === WbAbTestStatus::Running
            ? $experiment->resolveOpenCycle()
            : null;

        $progressMeta = $this->resolveProgressMeta(
            $experiment,
            $status,
            $settings['impressions_per_photo'],
            $openCycle,
        );

        $payload = [
            'id' => $experiment->id,
            'ab_product_id' => $experiment->ab_product_id,
            'name' => $experiment->name,
            'status' => $status->value,
            'status_label' => $status->label(),
            'progress' => $progressMeta['progress'],
            'progress_mode' => $progressMeta['mode'],
            'progress_label' => $progressMeta['label'],
            'impressions_progress' => $progressMeta['impressions_progress'],
            'wb_advert_id' => $experiment->wb_advert_id ? (int) $experiment->wb_advert_id : null,
            'wb_advert_name' => $experiment->wb_advert_name,
            'campaign_payment_type' => $campaignPaymentType,
            'campaign_bound_at' => optional($experiment->campaign_bound_at)?->toIso8601String(),
            'created_at' => optional($experiment->created_at)?->toIso8601String(),
            'started_at' => optional($experiment->started_at)?->toIso8601String(),
            'finished_at' => optional($experiment->finished_at)?->toIso8601String(),
            'error_message' => $experiment->error_message,
            'consecutive_failures' => (int) ($experiment->consecutive_failures ?? 0),
            'max_consecutive_failures' => WbAbExperimentEngine::MAX_CONSECUTIVE_FAILURES,
            'current_photo_id' => $openCycle ? (int) $openCycle->ab_experiment_photo_id : null,
            'current_cycle_id' => $openCycle ? (int) $openCycle->id : null,
            'winner_photo_id' => $experiment->winner_photo_id ? (int) $experiment->winner_photo_id : null,
            'last_processed_at' => optional($experiment->last_processed_at)?->toIso8601String(),
            'photos_count' => $photosCount,
            'can_continue_photos' => $photosCount >= self::MIN_PHOTOS_TO_CONTINUE,
            'settings' => $settings,
            'settings_summary' => $this->formatSettingsSummary($settings, $campaignPaymentType),
            'settings_ready' => $settingsReady,
            'can_continue_workspace' => $canContinueWorkspace,
            'can_edit' => $canEdit,
            /** Удаление вариантов: draft/stopped/error + running (не completed). */
            'can_delete_photos' => $canEdit || $status === WbAbTestStatus::Running,
            'can_start' => $canStart,
            'can_stop' => $canStop,
            'is_terminal' => $status->isTerminal(),
            'start_checks' => $this->buildStartChecksSummary($experiment, $settingsReady, $photosCount),
        ];

        if ($experiment->relationLoaded('photos')) {
            [$winnerId, $winnerCtr] = $this->resolveWinnerComparison($experiment, $status, $photoAggregates);

            $payload['photos'] = $experiment->photos
                ->map(fn (AbExperimentPhoto $photo) => $this->mapPhotoWithStats(
                    $photo,
                    $status,
                    $photoAggregates,
                    $winnerId,
                    $winnerCtr,
                ))
                ->values()
                ->all();
        }

        if ($experiment->relationLoaded('events')) {
            $payload['events'] = $experiment->events
                ->take(30)
                ->map(fn (AbExperimentEvent $event) => $this->mapEvent($event))
                ->values()
                ->all();
            $payload['last_api_error'] = $this->resolveLastApiErrorMessage($payload['events']);
        } else {
            $payload['events'] = [];
            $payload['last_api_error'] = null;
        }

        // Workspace detail: limited cycles for history table; aggregates use ALL cycles in engine.
        if ($experiment->relationLoaded('cycles')) {
            $totalRounds = $this->experimentEngine->totalRounds($experiment);
            $payload['total_rounds'] = $totalRounds;
            $payload['action_history'] = $this->mapActionHistory($experiment);
            $payload['action_history_meta'] = [
                'total_rounds' => $totalRounds,
                'shown' => count($payload['action_history']),
                'limit' => 100,
            ];
        }

        return $payload;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function resolveLastApiErrorMessage(array $events): ?string
    {
        foreach ($events as $event) {
            $type = (string) ($event['type'] ?? '');
            if (in_array($type, [
                WbAbExperimentJournal::TYPE_EXPERIMENT_ERROR,
                WbAbExperimentJournal::TYPE_API_RATE_LIMITED,
                WbAbExperimentJournal::TYPE_API_RETRY,
            ], true)) {
                $message = trim((string) ($event['message'] ?? ''));

                return $message !== '' ? $message : null;
            }
        }

        return null;
    }

    /**
     * Winner comparison base — only for completed experiments.
     *
     * @param  array<int, array{views:int,clicks:int,ctr:float|null}>  $photoAggregates
     * @return array{0: int|null, 1: float|null} [winnerId, winnerCtr]
     */
    private function resolveWinnerComparison(
        AbExperiment $experiment,
        WbAbTestStatus $status,
        array $photoAggregates,
    ): array {
        if ($status !== WbAbTestStatus::Completed) {
            return [null, null];
        }

        $winnerId = $experiment->winner_photo_id
            ? (int) $experiment->winner_photo_id
            : $this->experimentEngine->resolveWinnerPhotoId($experiment);

        if ($winnerId === null) {
            return [null, null];
        }

        $winnerRow = $photoAggregates[$winnerId] ?? null;
        if ($winnerRow === null || ($winnerRow['views'] ?? 0) <= 0 || ! isset($winnerRow['ctr'])) {
            return [$winnerId, null];
        }

        return [$winnerId, (float) $winnerRow['ctr']];
    }

    /**
     * Map photo + cycle aggregates. Efficiency % only after experiment is completed,
     * relative to the winner (max CTR), not the first photo.
     *
     * @param  array<int, array{views:int,clicks:int,ctr:float|null}>  $photoAggregates
     * @return array<string, mixed>
     */
    private function mapPhotoWithStats(
        AbExperimentPhoto $photo,
        WbAbTestStatus $status,
        array $photoAggregates,
        ?int $winnerId,
        ?float $winnerCtr,
    ): array {
        $mapped = $this->mapPhoto($photo);
        $photoId = (int) $photo->id;
        $agg = $photoAggregates[$photoId] ?? null;

        $showResultDelta = $status === WbAbTestStatus::Completed;
        $mapped['is_winner'] = $showResultDelta && $winnerId !== null && $photoId === $winnerId;

        if ($agg) {
            $views = (int) ($agg['views'] ?? 0);
            $clicks = (int) ($agg['clicks'] ?? 0);
            $ctr = $agg['ctr'] ?? null;
            $mapped['stats'] = [
                'impressions' => $views,
                'views' => $views,
                'clicks' => $clicks,
                'ctr' => $ctr,
                'result_delta_pct' => $showResultDelta
                    ? $this->computeResultDeltaPct(
                        $ctr !== null ? (float) $ctr : null,
                        $winnerCtr,
                    )
                    : null,
            ];
        }

        return $mapped;
    }

    /**
     * Delta vs winner CTR: (ctr / bestCtr - 1) * 100. Winner → 0; others ≤ 0.
     */
    private function computeResultDeltaPct(?float $ctr, ?float $winnerCtr): ?float
    {
        if ($ctr === null || $winnerCtr === null || $winnerCtr <= 0) {
            return null;
        }

        return round((($ctr / $winnerCtr) - 1) * 100, 0);
    }

    /**
     * Cycle-based action history for the workspace table.
     *
     * @return list<array<string, mixed>>
     */
    public function mapActionHistory(AbExperiment $experiment): array
    {
        // Always query last 100 by sequence desc. Do not reuse eager-loaded cycles:
        // relation default order is sequence ASC, so limit(100) would take the oldest.
        $cycles = $experiment->cycles()
            ->with('photo')
            ->reorder()
            ->orderByDesc('sequence')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $photoVariant = [];
        if ($experiment->relationLoaded('photos')) {
            foreach ($experiment->photos as $photo) {
                $photoVariant[(int) $photo->id] = (int) $photo->sort_order + 1;
            }
        }

        return $cycles
            ->map(function (AbExperimentCycle $cycle) use ($photoVariant) {
                $photo = $cycle->relationLoaded('photo') ? $cycle->photo : $cycle->photo;
                $photoId = (int) $cycle->ab_experiment_photo_id;
                $views = $cycle->views_end !== null || $cycle->ended_at !== null
                    ? $cycle->deltaViews()
                    : 0;
                $clicks = $cycle->views_end !== null || $cycle->ended_at !== null
                    ? $cycle->deltaClicks()
                    : 0;
                $ctr = $views > 0 ? round(($clicks / $views) * 100, 2) : 0.0;
                $inProgress = $cycle->ended_at === null;
                $durationMinutes = null;
                if (! $inProgress && $cycle->started_at && $cycle->ended_at) {
                    $durationMinutes = max(0, (int) $cycle->started_at->diffInMinutes($cycle->ended_at));
                }

                $previewUrl = null;
                if ($photo) {
                    $previewUrl = route('subscriber.wb.ab-testing.media.show', ['photo' => $photo->id]);
                    if ($photo->updated_at) {
                        $previewUrl .= (str_contains($previewUrl, '?') ? '&' : '?')
                            .'v='.$photo->updated_at->getTimestamp();
                    }
                }

                $variant = $photoVariant[$photoId]
                    ?? ($photo ? (int) $photo->sort_order + 1 : null);

                return [
                    'id' => (int) $cycle->id,
                    'installed_at' => optional($cycle->started_at)?->toIso8601String(),
                    'photo_id' => $photoId,
                    'preview_url' => $previewUrl,
                    'variant' => $variant,
                    'clicks' => $clicks,
                    'views' => $views,
                    'impressions' => $views,
                    'ctr' => $ctr,
                    'round' => (int) $cycle->sequence,
                    'duration_minutes' => $durationMinutes,
                    'in_progress' => $inProgress,
                    'duration_label' => $inProgress
                        ? 'В процессе'
                        : (string) ($durationMinutes ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     progress: int,
     *     mode: string,
     *     label: string,
     *     impressions_progress: array<string, mixed>|null
     * }
     */
    private function resolveProgressMeta(
        AbExperiment $experiment,
        WbAbTestStatus $status,
        int $impressionsPerPhoto,
        ?AbExperimentCycle $openCycle,
    ): array {
        if ($status === WbAbTestStatus::Completed) {
            return [
                'progress' => 100,
                'mode' => 'done',
                'label' => 'Завершён',
                'impressions_progress' => null,
            ];
        }

        if ($status === WbAbTestStatus::Draft) {
            $progress = max(0, min(100, (int) $experiment->progress));

            return [
                'progress' => $progress,
                'mode' => 'setup',
                'label' => 'Готовность настройки: '.$progress.'%',
                'impressions_progress' => null,
            ];
        }

        if ($status === WbAbTestStatus::Running) {
            $breakdown = $this->experimentEngine->impressionsProgressBreakdown(
                $experiment,
                $impressionsPerPhoto,
                $openCycle,
                null,
            );
            $progress = (int) $breakdown['progress'];
            $mode = (string) $breakdown['mode']; // pending | views

            if ($mode === 'pending') {
                $label = 'Ожидаем первые показы из статистики WB…';
            } else {
                $target = (int) $breakdown['target_per_photo'];
                $label = $progress.'% по показам (мин. по фото, цель '
                    .number_format($target, 0, ',', ' ').' на фото)';
            }

            return [
                'progress' => $progress,
                'mode' => $mode,
                'label' => $label,
                'impressions_progress' => $breakdown,
            ];
        }

        // stopped / error — last stored progress, optionally recompute from views
        $breakdown = $this->experimentEngine->impressionsProgressBreakdown(
            $experiment,
            $impressionsPerPhoto,
            null,
            null,
        );
        $fromViews = (int) $breakdown['progress'];
        $stored = max(0, min(100, (int) $experiment->progress));
        $progress = max($stored, $fromViews);
        if ($status->isTerminal() && $fromViews > 0) {
            $progress = $fromViews;
        }

        return [
            'progress' => max(0, min(99, $progress)),
            'mode' => $fromViews > 0 ? 'views' : 'setup',
            'label' => $status->label().': '.$progress.'%',
            'impressions_progress' => $fromViews > 0 ? $breakdown : null,
        ];
    }

    /**
     * @return list<array{key: string, ok: bool, label: string}>
     */
    private function buildStartChecksSummary(
        AbExperiment $experiment,
        bool $settingsReady,
        int $photosCount,
    ): array {
        $status = $experiment->status instanceof WbAbTestStatus
            ? $experiment->status
            : WbAbTestStatus::tryFrom((string) $experiment->status) ?? WbAbTestStatus::Draft;

        return [
            [
                'key' => 'status',
                'ok' => $status->isStartable(),
                'label' => 'Статус позволяет запуск (черновик или остановлен)',
            ],
            [
                'key' => 'settings',
                'ok' => $settingsReady,
                'label' => 'Настройки сохранены',
            ],
            [
                'key' => 'photos',
                'ok' => $photosCount >= self::MIN_PHOTOS_TO_CONTINUE,
                'label' => 'Минимум 2 фотографии',
            ],
            [
                'key' => 'campaign',
                'ok' => (bool) $experiment->wb_advert_id,
                'label' => 'Рекламная кампания выбрана',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mapEvent(AbExperimentEvent $event): array
    {
        return [
            'id' => $event->id,
            'type' => $event->type,
            'message' => $event->message,
            'meta' => $event->meta,
            'created_at' => optional($event->created_at)?->toIso8601String(),
        ];
    }

    /**
     * Relations for full experiment workspace payload (poll / start / stop).
     *
     * @return array<string, mixed>
     */
    public function experimentDetailRelations(): array
    {
        return [
            'photos',
            'product',
            'events' => fn ($q) => $q->orderByDesc('id')->limit(30),
            // reorder() clears relation default orderBy(sequence ASC) so limit takes newest.
            'cycles' => fn ($q) => $q->with('photo')
                ->reorder()
                ->orderByDesc('sequence')
                ->orderByDesc('id')
                ->limit(100),
        ];
    }

    /**
     * @return array{success: bool, experiment?: array<string, mixed>, messages: list<string>}
     */
    public function startExperiment(WbCabinet $cabinet, AbExperiment $experiment): array
    {
        if ((int) $experiment->cabinet_id !== (int) $cabinet->id) {
            return ['success' => false, 'messages' => ['Эксперимент не найден.']];
        }

        $this->assertCanStartExperiment($experiment);

        $result = $this->experimentEngine->start($cabinet, $experiment);
        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'messages' => $result['messages'] ?? ['Не удалось запустить эксперимент'],
            ];
        }

        $fresh = $result['experiment'] ?? $experiment->fresh(['photos', 'product']);
        if ($fresh) {
            $fresh->load($this->experimentDetailRelations());
        }

        return [
            'success' => true,
            'experiment' => $fresh ? $this->mapExperiment($fresh) : null,
            'messages' => $result['messages'] ?? ['Эксперимент запущен.'],
        ];
    }

    /**
     * @return array{success: bool, experiment?: array<string, mixed>, messages: list<string>}
     */
    public function stopExperiment(WbCabinet $cabinet, AbExperiment $experiment): array
    {
        if ((int) $experiment->cabinet_id !== (int) $cabinet->id) {
            return ['success' => false, 'messages' => ['Эксперимент не найден.']];
        }

        $result = $this->experimentEngine->stop($cabinet, $experiment);
        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'messages' => $result['messages'] ?? ['Не удалось остановить эксперимент'],
            ];
        }

        $fresh = $result['experiment'] ?? $experiment->fresh(['photos', 'product']);
        if ($fresh) {
            $fresh->load($this->experimentDetailRelations());
        }

        return [
            'success' => true,
            'experiment' => $fresh ? $this->mapExperiment($fresh) : null,
            'messages' => $result['messages'] ?? ['Эксперимент остановлен.'],
        ];
    }

    /**
     * @param  array{impressions_per_photo:int,impressions_per_round:int,round_minutes:int,cpm:int}  $data
     * @return array{success: bool, experiment?: array<string, mixed>, messages: list<string>}
     */
    public function updateExperimentSettings(WbCabinet $cabinet, AbExperiment $experiment, array $data): array
    {
        if ((int) $experiment->cabinet_id !== (int) $cabinet->id) {
            return ['success' => false, 'messages' => ['Эксперимент не найден.']];
        }

        $this->assertDraftExperimentForSettings($experiment);

        $settings = [
            'impressions_per_photo' => (int) ($data['impressions_per_photo'] ?? 0),
            'impressions_per_round' => (int) ($data['impressions_per_round'] ?? 0),
            'round_minutes' => (int) ($data['round_minutes'] ?? 0),
            'cpm' => (int) ($data['cpm'] ?? 0),
        ];

        $paymentType = $this->resolveCampaignPaymentType($experiment);
        $errors = $this->validateSettingsPayload($settings, $paymentType);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $experiment->impressions_per_photo = $settings['impressions_per_photo'];
        $experiment->impressions_per_round = $settings['impressions_per_round'];
        $experiment->round_minutes = $settings['round_minutes'];
        $experiment->cpm = $settings['cpm'];
        $experiment->save();

        $this->syncSettingsProgress($experiment->refresh());

        if ($experiment->relationLoaded('photos') === false) {
            $experiment->load('photos');
        }

        return [
            'success' => true,
            'experiment' => $this->mapExperiment($experiment),
            'messages' => ['Настройки сохранены'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPhotos(AbExperiment $experiment): array
    {
        if ($experiment->relationLoaded('photos') === false) {
            $experiment->load('photos');
        }

        $status = $experiment->status instanceof WbAbTestStatus
            ? $experiment->status
            : WbAbTestStatus::tryFrom((string) $experiment->status) ?? WbAbTestStatus::Draft;

        $photoAggregates = $this->experimentEngine->photoAggregates($experiment);
        [$winnerId, $winnerCtr] = $this->resolveWinnerComparison($experiment, $status, $photoAggregates);

        return $experiment->photos
            ->map(fn (AbExperimentPhoto $photo) => $this->mapPhotoWithStats(
                $photo,
                $status,
                $photoAggregates,
                $winnerId,
                $winnerCtr,
            ))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function mapPhoto(AbExperimentPhoto $photo): array
    {
        $previewUrl = route('subscriber.wb.ab-testing.media.show', ['photo' => $photo->id]);
        if ($photo->updated_at) {
            $previewUrl .= (str_contains($previewUrl, '?') ? '&' : '?')
                .'v='.$photo->updated_at->getTimestamp();
        }

        return [
            'id' => $photo->id,
            'sort_order' => (int) $photo->sort_order,
            'preview_url' => $previewUrl,
            'original_name' => $photo->original_name,
            'mime' => $photo->mime,
            'size' => $photo->size !== null ? (int) $photo->size : null,
            'is_winner' => false,
            'stats' => [
                'impressions' => null,
                'clicks' => null,
                'ctr' => null,
                'result_delta_pct' => null,
            ],
        ];
    }

    public function getPhotoForCabinet(int $cabinetId, int $photoId): ?AbExperimentPhoto
    {
        return AbExperimentPhoto::query()
            ->where('cabinet_id', $cabinetId)
            ->where('id', $photoId)
            ->first();
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return array{success: bool, photos?: list<array<string, mixed>>, experiment?: array<string, mixed>, messages: list<string>}
     */
    public function storePhotos(WbCabinet $cabinet, AbExperiment $experiment, array $files): array
    {
        $this->assertDraftExperimentForPhotos($experiment);

        $files = array_values(array_filter($files, fn ($file) => $file instanceof UploadedFile));
        if ($files === []) {
            return ['success' => false, 'messages' => ['Выберите хотя бы один файл изображения.']];
        }

        $currentCount = (int) $experiment->photos()->count();
        $remaining = self::MAX_PHOTOS - $currentCount;
        if ($remaining <= 0) {
            return [
                'success' => false,
                'messages' => ['Можно загрузить не более '.self::MAX_PHOTOS.' фотографий.'],
            ];
        }

        if (count($files) > $remaining) {
            return [
                'success' => false,
                'messages' => [
                    "Можно добавить ещё только {$remaining} "
                    .($remaining === 1 ? 'фотографию' : 'фотографий')
                    .' (максимум '.self::MAX_PHOTOS.').',
                ],
            ];
        }

        foreach ($files as $index => $file) {
            $error = $this->validatePhotoFile($file);
            if ($error !== null) {
                return ['success' => false, 'messages' => ["Файл #".($index + 1).": {$error}"]];
            }
        }

        $storedPaths = [];

        try {
            DB::transaction(function () use ($cabinet, $experiment, $files, $currentCount, &$storedPaths): void {
                $sortOrder = $currentCount;

                foreach ($files as $file) {
                    $path = $this->storePhotoFile($cabinet, $experiment, $file);
                    $storedPaths[] = $path;

                    AbExperimentPhoto::query()->create([
                        'ab_experiment_id' => $experiment->id,
                        'cabinet_id' => $cabinet->id,
                        'sort_order' => $sortOrder,
                        'disk' => self::PHOTO_DISK,
                        'path' => $path,
                        'original_name' => $this->nullableString($file->getClientOriginalName()),
                        'mime' => $file->getMimeType() ?: $file->getClientMimeType(),
                        'size' => $file->getSize() ?: null,
                    ]);

                    $sortOrder++;
                }

                $this->syncPhotosProgress($experiment->refresh());
            });
        } catch (\Throwable $e) {
            foreach ($storedPaths as $path) {
                Storage::disk(self::PHOTO_DISK)->delete($path);
            }

            Log::error('WB A/B testing: failed to store photos', [
                'cabinet_id' => $cabinet->id,
                'experiment_id' => $experiment->id,
                'message' => $e->getMessage(),
            ]);

            return ['success' => false, 'messages' => ['Не удалось сохранить фотографии.']];
        }

        $experiment->load('photos');

        return [
            'success' => true,
            'photos' => $this->listPhotos($experiment),
            'experiment' => $this->mapExperiment($experiment),
            'messages' => ['Фотографии загружены'],
        ];
    }

    /**
     * @return array{success: bool, photos?: list<array<string, mixed>>, experiment?: array<string, mixed>, messages: list<string>}
     */
    public function replacePhoto(
        WbCabinet $cabinet,
        AbExperiment $experiment,
        AbExperimentPhoto $photo,
        UploadedFile $file,
    ): array {
        $this->assertDraftExperimentForPhotos($experiment);
        $this->assertPhotoBelongsToExperiment($photo, $experiment, $cabinet);

        $error = $this->validatePhotoFile($file);
        if ($error !== null) {
            return ['success' => false, 'messages' => [$error]];
        }

        $oldDisk = (string) ($photo->disk ?: self::PHOTO_DISK);
        $oldPath = (string) $photo->path;
        $newPath = null;

        try {
            $newPath = $this->storePhotoFile($cabinet, $experiment, $file);

            $photo->disk = self::PHOTO_DISK;
            $photo->path = $newPath;
            $photo->original_name = $this->nullableString($file->getClientOriginalName());
            $photo->mime = $file->getMimeType() ?: $file->getClientMimeType();
            $photo->size = $file->getSize() ?: null;
            $photo->save();

            if ($oldPath !== '' && $oldPath !== $newPath) {
                Storage::disk($oldDisk)->delete($oldPath);
            }
        } catch (\Throwable $e) {
            if ($newPath !== null) {
                Storage::disk(self::PHOTO_DISK)->delete($newPath);
            }

            Log::error('WB A/B testing: failed to replace photo', [
                'cabinet_id' => $cabinet->id,
                'experiment_id' => $experiment->id,
                'photo_id' => $photo->id,
                'message' => $e->getMessage(),
            ]);

            return ['success' => false, 'messages' => ['Не удалось заменить фотографию.']];
        }

        $experiment->load('photos');

        return [
            'success' => true,
            'photos' => $this->listPhotos($experiment),
            'experiment' => $this->mapExperiment($experiment),
            'messages' => ['Фотография заменена'],
        ];
    }

    /**
     * Удаление варианта фото. Доступно для draft/stopped/error и running
     * (чтобы убрать слабые варианты и перераспределить трафик).
     *
     * @return array{success: bool, photos?: list<array<string, mixed>>, experiment?: array<string, mixed>, messages: list<string>}
     */
    public function deletePhoto(
        WbCabinet $cabinet,
        AbExperiment $experiment,
        AbExperimentPhoto $photo,
    ): array {
        $this->assertPhotoBelongsToExperiment($photo, $experiment, $cabinet);

        $status = $experiment->status instanceof WbAbTestStatus
            ? $experiment->status
            : WbAbTestStatus::tryFrom((string) $experiment->status);

        if ($status === null || (! $status->isEditable() && $status !== WbAbTestStatus::Running)) {
            throw ValidationException::withMessages([
                'experiment' => 'Удалять фотографии можно у черновика, остановленного, ошибочного или запущенного эксперимента.',
            ]);
        }

        if ($status === WbAbTestStatus::Completed) {
            throw ValidationException::withMessages([
                'experiment' => 'У завершённого эксперимента нельзя удалять фотографии.',
            ]);
        }

        $disk = (string) ($photo->disk ?: self::PHOTO_DISK);
        $path = (string) $photo->path;
        $messages = ['Фотография удалена'];

        try {
            if ($status === WbAbTestStatus::Running) {
                $messages = $this->deletePhotoWhileRunning($cabinet, $experiment, $photo);
            } else {
                DB::transaction(function () use ($experiment, $photo): void {
                    $photo->delete();
                    $this->compactPhotoSortOrder($experiment);
                    $this->syncPhotosProgress($experiment->refresh());
                });
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('WB A/B testing: failed to delete photo', [
                'cabinet_id' => $cabinet->id,
                'experiment_id' => $experiment->id,
                'photo_id' => $photo->id,
                'message' => $e->getMessage(),
            ]);

            return ['success' => false, 'messages' => ['Не удалось удалить фотографию.']];
        }

        if ($path !== '') {
            Storage::disk($disk)->delete($path);
        }

        $experiment->refresh()->load('photos');

        return [
            'success' => true,
            'photos' => $this->listPhotos($experiment),
            'experiment' => $this->mapExperiment($experiment),
            'messages' => $messages,
        ];
    }

    /**
     * Удаление фото у running: при необходимости переключаем карточку на следующий вариант.
     *
     * @return list<string>
     */
    private function deletePhotoWhileRunning(
        WbCabinet $cabinet,
        AbExperiment $experiment,
        AbExperimentPhoto $photo,
    ): array {
        $apiKey = (string) ($cabinet->apikey ?? '');
        if ($apiKey === '') {
            throw ValidationException::withMessages([
                'experiment' => 'У кабинета не указан API-ключ Wildberries.',
            ]);
        }

        $product = $this->requireExperimentProduct($experiment);
        $nmId = (int) $product->nm_id;
        $switched = false;

        DB::transaction(function () use ($experiment, $photo, $apiKey, $nmId, &$switched): void {
            /** @var AbExperiment $locked */
            $locked = AbExperiment::query()
                ->whereKey($experiment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $status = $locked->status instanceof WbAbTestStatus
                ? $locked->status
                : WbAbTestStatus::tryFrom((string) $locked->status);

            if ($status !== WbAbTestStatus::Running) {
                throw ValidationException::withMessages([
                    'experiment' => 'Эксперимент больше не запущен. Обновите страницу.',
                ]);
            }

            $locked->load('photos');
            $photoStillExists = $locked->photos->contains(
                fn (AbExperimentPhoto $p) => (int) $p->id === (int) $photo->id,
            );
            if (! $photoStillExists) {
                throw ValidationException::withMessages([
                    'photo' => 'Фотография уже удалена.',
                ]);
            }

            $remaining = $locked->photos
                ->filter(fn (AbExperimentPhoto $p) => (int) $p->id !== (int) $photo->id)
                ->values();

            if ($remaining->isEmpty()) {
                throw ValidationException::withMessages([
                    'photo' => 'Нельзя удалить последнюю фотографию у запущенного эксперимента. '
                        .'Остановите эксперимент, если хотите завершить досрочно.',
                ]);
            }

            $switchResult = $this->experimentEngine->switchAwayFromRemovedPhoto(
                $locked,
                $photo,
                $remaining,
                $apiKey,
                $nmId,
            );

            if (! ($switchResult['success'] ?? false)) {
                throw ValidationException::withMessages([
                    'photo' => $switchResult['message'] ?? 'Не удалось переключить фотографию перед удалением.',
                ]);
            }

            $switched = (bool) ($switchResult['switched'] ?? false);

            // Циклы удалённого фото каскадно удалятся вместе с ним.
            AbExperimentPhoto::query()->whereKey($photo->id)->delete();

            $this->compactPhotoSortOrder($locked);

            app(WbAbExperimentJournal::class)->log(
                $locked,
                WbAbExperimentJournal::TYPE_PHOTO_REMOVED,
                'Вариант фотографии удалён из эксперимента'
                    .($switched ? ' (трафик переведён на следующий вариант).' : '.'),
                [
                    'photo_id' => $photo->id,
                    'switched' => $switched,
                    'remaining_count' => $remaining->count(),
                ],
            );

            $settings = $this->resolveExperimentSettings($locked);
            $locked->refresh()->load('photos');
            $openCycle = $locked->resolveOpenCycle();
            $locked->progress = $this->experimentEngine->computeProgress(
                $locked,
                $settings['impressions_per_photo'],
                $openCycle,
            );
            $locked->last_processed_at = now();
            $locked->save();
        });

        return $switched
            ? ['Фотография удалена. На карточке установлен следующий вариант — трафик пойдёт на оставшиеся.']
            : ['Фотография удалена. Трафик будет распределяться между оставшимися вариантами.'];
    }

    /**
     * @param  list<int>  $orderedIds
     * @return array{success: bool, photos?: list<array<string, mixed>>, experiment?: array<string, mixed>, messages: list<string>}
     */
    public function reorderPhotos(WbCabinet $cabinet, AbExperiment $experiment, array $orderedIds): array
    {
        $this->assertDraftExperimentForPhotos($experiment);

        $orderedIds = array_values(array_map('intval', $orderedIds));
        $photos = $experiment->photos()->get();
        $existingIds = $photos->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $incomingSorted = collect($orderedIds)->sort()->values()->all();

        if ($existingIds !== $incomingSorted || count($orderedIds) !== $photos->count()) {
            return [
                'success' => false,
                'messages' => ['Некорректный порядок фотографий. Обновите страницу и попробуйте снова.'],
            ];
        }

        DB::transaction(function () use ($photos, $orderedIds): void {
            $byId = $photos->keyBy(fn (AbExperimentPhoto $photo) => (int) $photo->id);
            foreach ($orderedIds as $index => $id) {
                $photo = $byId->get($id);
                if (! $photo) {
                    continue;
                }
                if ((int) $photo->sort_order !== $index) {
                    $photo->sort_order = $index;
                    $photo->save();
                }
            }
        });

        $experiment->load('photos');

        return [
            'success' => true,
            'photos' => $this->listPhotos($experiment),
            'experiment' => $this->mapExperiment($experiment),
            'messages' => ['Порядок фотографий обновлён'],
        ];
    }

    public function readPhotoBinary(AbExperimentPhoto $photo): ?string
    {
        $disk = (string) ($photo->disk ?: self::PHOTO_DISK);
        $path = (string) $photo->path;
        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            return null;
        }

        $content = Storage::disk($disk)->get($path);

        return is_string($content) && $content !== '' ? $content : null;
    }

    /**
     * List campaigns created by our service for this cabinet (not the whole WB ad cabinet).
     *
     * @return array{success: bool, items: list<array<string, mixed>>, messages: list<string>}
     */
    public function listCampaignsForExperiment(WbCabinet $cabinet, AbExperiment $experiment): array
    {
        $product = $this->requireExperimentProduct($experiment);
        $apiKey = (string) ($cabinet->apikey ?? '');
        $nmId = (int) $product->nm_id;
        $selectedAdvertId = $experiment->wb_advert_id ? (int) $experiment->wb_advert_id : null;

        $registry = AbCampaign::query()
            ->where('cabinet_id', $cabinet->id)
            ->orderByDesc('id')
            ->get();

        if ($registry->isEmpty()) {
            return [
                'success' => true,
                'items' => [],
                'messages' => [],
            ];
        }

        $ids = $registry->pluck('wb_advert_id')->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->values()->all();
        $busyAdvertIds = $this->runningAdvertIdsForCabinet((int) $cabinet->id);
        $advertsById = [];

        if ($apiKey !== '' && $ids !== []) {
            $details = $this->advertApi->getAdvertsBatched($apiKey, $ids);
            if (! ($details['success'] ?? false)) {
                return [
                    'success' => false,
                    'items' => [],
                    'messages' => $details['messages'] !== []
                        ? $details['messages']
                        : ['Не удалось получить информацию о кампаниях'],
                ];
            }

            foreach ($details['adverts'] as $advert) {
                if (! is_array($advert)) {
                    continue;
                }
                $id = (int) Arr::get($advert, 'id', 0);
                if ($id > 0) {
                    $advertsById[$id] = $advert;
                }
            }
        } elseif ($apiKey === '') {
            return [
                'success' => false,
                'items' => [],
                'messages' => ['У кабинета не задан API-ключ Wildberries'],
            ];
        }

        $items = [];
        foreach ($registry as $row) {
            $advertId = (int) $row->wb_advert_id;
            $live = $advertsById[$advertId] ?? null;
            $items[] = $this->mapRegistryCampaignRow(
                $row,
                $live,
                $nmId,
                $selectedAdvertId,
                $busyAdvertIds,
            );
        }

        usort($items, static function (array $a, array $b): int {
            if (($a['is_selected'] ?? false) !== ($b['is_selected'] ?? false)) {
                return ($a['is_selected'] ?? false) ? -1 : 1;
            }
            if (($a['can_edit_nms'] ?? false) !== ($b['can_edit_nms'] ?? false)) {
                return ($a['can_edit_nms'] ?? false) ? -1 : 1;
            }
            if (($a['contains_product'] ?? false) !== ($b['contains_product'] ?? false)) {
                return ($a['contains_product'] ?? false) ? -1 : 1;
            }

            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return [
            'success' => true,
            'items' => $items,
            'messages' => [],
        ];
    }

    /**
     * Create a WB campaign, register it as ours, bind to experiment (do not start).
     *
     * @param  array{name?: string, bid_type?: string, payment_type?: string, placement_types?: list<string>, budget_deposit?: int|null}  $input
     * @return array{
     *     success: bool,
     *     experiment?: array<string, mixed>,
     *     campaign?: array<string, mixed>,
     *     messages: list<string>,
     *     budget_deposited?: bool|null,
     *     budget_error?: string|null
     * }
     */
    public function createAndBindCampaign(WbCabinet $cabinet, AbExperiment $experiment, array $input): array
    {
        $this->assertDraftExperiment($experiment);
        $product = $this->requireExperimentProduct($experiment);
        $apiKey = (string) ($cabinet->apikey ?? '');

        if ($apiKey === '') {
            return ['success' => false, 'messages' => ['У кабинета не задан API-ключ Wildberries']];
        }

        $name = $this->nullableString($input['name'] ?? null)
            ?? $this->defaultCampaignName($product);

        $bidType = (string) ($input['bid_type'] ?? 'unified');
        if (! in_array($bidType, ['manual', 'unified'], true)) {
            $bidType = 'unified';
        }

        $paymentType = (string) ($input['payment_type'] ?? 'cpm');
        if (! in_array($paymentType, ['cpm', 'cpc'], true)) {
            $paymentType = 'cpm';
        }

        // WB requires bid_type=manual for CPC campaigns.
        if ($paymentType === 'cpc') {
            $bidType = 'manual';
        }

        $depositSum = isset($input['budget_deposit']) ? (int) $input['budget_deposit'] : 0;
        if ($depositSum > 0) {
            if ($depositSum < self::MIN_BUDGET_DEPOSIT) {
                return [
                    'success' => false,
                    'messages' => ['Минимальная сумма пополнения бюджета — '.self::MIN_BUDGET_DEPOSIT.' ₽'],
                ];
            }
            if ($depositSum % 50 !== 0) {
                return [
                    'success' => false,
                    'messages' => ['Сумма пополнения бюджета должна быть кратна 50 ₽'],
                ];
            }
        }

        $payload = [
            'name' => $name,
            'nms' => [(int) $product->nm_id],
            'bid_type' => $bidType,
            'payment_type' => $paymentType,
        ];

        if ($bidType === 'manual') {
            $placements = $input['placement_types'] ?? ['search'];
            if (! is_array($placements) || $placements === []) {
                $placements = ['search'];
            }
            $placements = array_values(array_intersect($placements, ['search', 'recommendations']));
            if ($placements === []) {
                $placements = ['search'];
            }
            $payload['placement_types'] = $placements;
        }

        $created = $this->advertApi->createSeacatCampaign($apiKey, $payload);
        if (! ($created['success'] ?? false)) {
            return [
                'success' => false,
                'messages' => [$created['message'] ?? 'Не удалось создать рекламную кампанию'],
            ];
        }

        $advertId = (int) ($created['advert_id'] ?? 0);
        if ($advertId <= 0) {
            return ['success' => false, 'messages' => ['WB API не вернул ID кампании']];
        }

        // Always register + bind after successful WB create (never leave orphan on WB).
        $this->registerOurCampaign($cabinet, $advertId, $name, $bidType, $paymentType, $experiment);
        $this->bindAdvertToExperiment($experiment, $advertId, $name);

        $messages = ['Кампания создана и привязана к эксперименту'];
        $budgetDeposited = null;
        $budgetError = null;

        if ($depositSum > 0) {
            // WB: type 1 = balance (most common for promo), type 0 = account.
            $deposit = $this->advertApi->depositBudget(
                $apiKey,
                $advertId,
                $depositSum,
                WbAdvertApiClient::BUDGET_DEPOSIT_TYPE_BALANCE,
            );
            if ($deposit['success'] ?? false) {
                $budgetDeposited = true;
                $messages = [
                    'Кампания создана, привязана к эксперименту, бюджет пополнен на '.$depositSum.' ₽',
                ];
            } else {
                $budgetDeposited = false;
                $budgetError = (string) ($deposit['message'] ?? 'ошибка WB API');
                Log::warning('WB A/B testing: campaign budget deposit failed', [
                    'cabinet_id' => $cabinet->id,
                    'experiment_id' => $experiment->id,
                    'advert_id' => $advertId,
                    'sum' => $depositSum,
                    'code' => $deposit['code'] ?? null,
                    'message' => $budgetError,
                ]);
                $messages = [
                    'Кампания создана и привязана, но пополнить бюджет не удалось: '.$budgetError
                    .'. Пополните бюджет в кабинете WB (минимум '.self::MIN_BUDGET_DEPOSIT
                    .' ₽), иначе запуск эксперимента будет недоступен.',
                ];
            }
        }

        return [
            'success' => true,
            'experiment' => $this->mapExperiment($experiment->refresh()),
            'campaign' => [
                'id' => $advertId,
                'name' => $name,
                'contains_product' => true,
                'can_edit_nms' => true,
            ],
            'messages' => $messages,
            'budget_deposited' => $budgetDeposited,
            'budget_error' => $budgetError,
        ];
    }

    /**
     * Swap campaign nms to current product and bind experiment (reuse idle campaign).
     *
     * @return array{success: bool, experiment?: array<string, mixed>, campaign?: array<string, mixed>, messages: list<string>}
     */
    public function prepareCampaignForProduct(
        WbCabinet $cabinet,
        AbExperiment $experiment,
        int $advertId,
    ): array {
        $this->assertDraftExperiment($experiment);
        $product = $this->requireExperimentProduct($experiment);
        $registry = $this->requireOurCampaign($cabinet, $advertId);
        $apiKey = (string) ($cabinet->apikey ?? '');

        if ($apiKey === '') {
            return ['success' => false, 'messages' => ['У кабинета не задан API-ключ Wildberries']];
        }

        $details = $this->advertApi->getAdverts($apiKey, [$advertId]);
        if (! ($details['success'] ?? false)) {
            return [
                'success' => false,
                'messages' => [$details['message'] ?? 'Не удалось получить кампанию'],
            ];
        }

        $advert = $this->firstAdvertFromPayload($details['data'] ?? null, $advertId);
        if ($advert === null) {
            return ['success' => false, 'messages' => ['Кампания не найдена в Wildberries']];
        }

        $guard = $this->assertCanEditCampaignNms($cabinet, $advertId, $advert);
        if ($guard !== null) {
            return ['success' => false, 'messages' => [$guard]];
        }

        $nmId = (int) $product->nm_id;
        $nmIds = $this->extractAdvertNmIds($advert);
        $toDelete = array_values(array_filter($nmIds, static fn (int $id): bool => $id !== $nmId));
        $toAdd = in_array($nmId, $nmIds, true) ? [] : [$nmId];

        if ($toAdd !== [] || $toDelete !== []) {
            $patch = $this->advertApi->patchAuctionNms($apiKey, $advertId, $toAdd, $toDelete);
            if (! ($patch['success'] ?? false)) {
                return [
                    'success' => false,
                    'messages' => [$patch['message'] ?? 'Не удалось обновить товары в кампании'],
                ];
            }
        }

        $name = (string) Arr::get($advert, 'settings.name', $registry->name);
        $this->unbindOtherDraftsFromAdvert($cabinet, $experiment, $advertId);
        $this->bindAdvertToExperiment($experiment, $advertId, $name !== '' ? $name : $registry->name);

        if ($name !== '' && $name !== $registry->name) {
            $registry->name = $name;
            $registry->save();
        }

        $refreshed = $this->advertApi->getAdverts($apiKey, [$advertId]);
        $advertAfter = ($refreshed['success'] ?? false)
            ? $this->firstAdvertFromPayload($refreshed['data'] ?? null, $advertId)
            : $advert;

        $mapped = $this->mapRegistryCampaignRow(
            $registry->refresh(),
            $advertAfter,
            $nmId,
            $advertId,
            $this->runningAdvertIdsForCabinet((int) $cabinet->id),
        );
        $mapped['contains_product'] = true;

        return [
            'success' => true,
            'experiment' => $this->mapExperiment($experiment->refresh()),
            'campaign' => $mapped,
            'messages' => [
                $toAdd === [] && $toDelete === []
                    ? 'Кампания уже содержит текущий товар и привязана к эксперименту'
                    : 'Кампания подготовлена под текущий товар и привязана к эксперименту',
            ],
        ];
    }

    /**
     * Add experiment product to an existing *our* campaign and optionally bind.
     *
     * @return array{success: bool, experiment?: array<string, mixed>, campaign?: array<string, mixed>, messages: list<string>}
     */
    public function addProductToCampaign(
        WbCabinet $cabinet,
        AbExperiment $experiment,
        int $advertId,
        bool $bind = true,
    ): array {
        $this->assertDraftExperiment($experiment);
        $product = $this->requireExperimentProduct($experiment);
        $this->requireOurCampaign($cabinet, $advertId);
        $apiKey = (string) ($cabinet->apikey ?? '');

        if ($apiKey === '') {
            return ['success' => false, 'messages' => ['У кабинета не задан API-ключ Wildberries']];
        }

        $details = $this->advertApi->getAdverts($apiKey, [$advertId]);
        if (! ($details['success'] ?? false)) {
            return [
                'success' => false,
                'messages' => [$details['message'] ?? 'Не удалось получить кампанию'],
            ];
        }

        $advert = $this->firstAdvertFromPayload($details['data'] ?? null, $advertId);
        if ($advert === null) {
            return ['success' => false, 'messages' => ['Кампания не найдена в кабинете Wildberries']];
        }

        $nmId = (int) $product->nm_id;
        $nmIds = $this->extractAdvertNmIds($advert);
        $registry = $this->requireOurCampaign($cabinet, $advertId);

        if (in_array($nmId, $nmIds, true)) {
            if ($bind) {
                $this->unbindOtherDraftsFromAdvert($cabinet, $experiment, $advertId);
                $this->bindAdvertToExperiment(
                    $experiment,
                    $advertId,
                    (string) Arr::get($advert, 'settings.name', $registry->name),
                );
            }

            return [
                'success' => true,
                'experiment' => $this->mapExperiment($experiment->refresh()),
                'campaign' => $this->mapRegistryCampaignRow(
                    $registry,
                    $advert,
                    $nmId,
                    $bind ? $advertId : ($experiment->wb_advert_id ? (int) $experiment->wb_advert_id : null),
                    $this->runningAdvertIdsForCabinet((int) $cabinet->id),
                ),
                'messages' => ['Товар уже есть в кампании'],
            ];
        }

        $guard = $this->assertCanEditCampaignNms($cabinet, $advertId, $advert);
        if ($guard !== null) {
            return ['success' => false, 'messages' => [$guard]];
        }

        $patch = $this->advertApi->patchAuctionNms($apiKey, $advertId, [$nmId], []);
        if (! ($patch['success'] ?? false)) {
            return [
                'success' => false,
                'messages' => [$patch['message'] ?? 'Не удалось добавить товар в кампанию'],
            ];
        }

        $name = (string) Arr::get($advert, 'settings.name', $registry->name);
        if ($bind) {
            $this->unbindOtherDraftsFromAdvert($cabinet, $experiment, $advertId);
            $this->bindAdvertToExperiment($experiment, $advertId, $name);
        }

        $refreshed = $this->advertApi->getAdverts($apiKey, [$advertId]);
        $advertAfter = ($refreshed['success'] ?? false)
            ? $this->firstAdvertFromPayload($refreshed['data'] ?? null, $advertId)
            : $advert;

        $mapped = $this->mapRegistryCampaignRow(
            $registry,
            $advertAfter,
            $nmId,
            $bind ? $advertId : ($experiment->wb_advert_id ? (int) $experiment->wb_advert_id : null),
            $this->runningAdvertIdsForCabinet((int) $cabinet->id),
        );
        $mapped['contains_product'] = true;

        return [
            'success' => true,
            'experiment' => $this->mapExperiment($experiment->refresh()),
            'campaign' => $mapped,
            'messages' => ['Товар добавлен в кампанию'],
        ];
    }

    /**
     * Remove experiment product from our campaign (real WB mutation).
     *
     * @return array{success: bool, experiment?: array<string, mixed>, campaign?: array<string, mixed>, messages: list<string>}
     */
    public function removeProductFromCampaign(
        WbCabinet $cabinet,
        AbExperiment $experiment,
        int $advertId,
    ): array {
        $this->assertDraftExperiment($experiment);
        $product = $this->requireExperimentProduct($experiment);
        $this->requireOurCampaign($cabinet, $advertId);
        $apiKey = (string) ($cabinet->apikey ?? '');

        if ($apiKey === '') {
            return ['success' => false, 'messages' => ['У кабинета не задан API-ключ Wildberries']];
        }

        $details = $this->advertApi->getAdverts($apiKey, [$advertId]);
        if (! ($details['success'] ?? false)) {
            return [
                'success' => false,
                'messages' => [$details['message'] ?? 'Не удалось получить кампанию'],
            ];
        }

        $advert = $this->firstAdvertFromPayload($details['data'] ?? null, $advertId);
        if ($advert === null) {
            return ['success' => false, 'messages' => ['Кампания не найдена']];
        }

        $nmId = (int) $product->nm_id;
        $nmIds = $this->extractAdvertNmIds($advert);

        if (! in_array($nmId, $nmIds, true)) {
            if ((int) $experiment->wb_advert_id === $advertId) {
                $this->unbindAdvertFromExperiment($experiment);
            }

            return [
                'success' => true,
                'experiment' => $this->mapExperiment($experiment->refresh()),
                'messages' => ['Товара уже нет в кампании'],
            ];
        }

        $guard = $this->assertCanEditCampaignNms($cabinet, $advertId, $advert);
        if ($guard !== null) {
            return ['success' => false, 'messages' => [$guard]];
        }

        $patch = $this->advertApi->patchAuctionNms($apiKey, $advertId, [], [$nmId]);
        if (! ($patch['success'] ?? false)) {
            return [
                'success' => false,
                'messages' => [$patch['message'] ?? 'Не удалось удалить товар из кампании'],
            ];
        }

        if ((int) $experiment->wb_advert_id === $advertId) {
            $this->unbindAdvertFromExperiment($experiment);
        }

        return [
            'success' => true,
            'experiment' => $this->mapExperiment($experiment->refresh()),
            'messages' => ['Товар удалён из кампании'],
        ];
    }

    /**
     * Pause a registry campaign on WB (status 9 → 11).
     *
     * @return array{success: bool, campaign?: array<string, mixed>, messages: list<string>}
     */
    public function pauseCampaign(WbCabinet $cabinet, int $advertId, ?AbExperiment $contextExperiment = null): array
    {
        $registry = $this->requireOurCampaign($cabinet, $advertId);
        $apiKey = (string) ($cabinet->apikey ?? '');

        if ($apiKey === '') {
            return ['success' => false, 'messages' => ['У кабинета не задан API-ключ Wildberries']];
        }

        if ($this->isAdvertBusyByRunningAb((int) $cabinet->id, $advertId)) {
            return [
                'success' => false,
                'messages' => ['Кампания занята запущенным A/B-тестом — остановите эксперимент, прежде чем ставить на паузу вручную.'],
            ];
        }

        $details = $this->advertApi->getAdverts($apiKey, [$advertId]);
        if (! ($details['success'] ?? false)) {
            return [
                'success' => false,
                'messages' => [$details['message'] ?? 'Не удалось получить кампанию'],
            ];
        }

        $advert = $this->firstAdvertFromPayload($details['data'] ?? null, $advertId);
        if ($advert === null) {
            return ['success' => false, 'messages' => ['Кампания не найдена в Wildberries']];
        }

        $status = (int) Arr::get($advert, 'status', 0);
        if ($status !== 9) {
            return [
                'success' => false,
                'messages' => ['Поставить на паузу можно только активную кампанию (сейчас: '.$this->campaignStatusLabel($status).').'],
            ];
        }

        $paused = $this->advertApi->pauseAdvert($apiKey, $advertId);
        if (! ($paused['success'] ?? false)) {
            return [
                'success' => false,
                'messages' => [$paused['message'] ?? 'Не удалось поставить кампанию на паузу'],
            ];
        }

        $refreshed = $this->advertApi->getAdverts($apiKey, [$advertId]);
        $advertAfter = ($refreshed['success'] ?? false)
            ? $this->firstAdvertFromPayload($refreshed['data'] ?? null, $advertId)
            : null;

        $productNmId = 0;
        $selectedId = null;
        if ($contextExperiment) {
            $product = $contextExperiment->relationLoaded('product')
                ? $contextExperiment->product
                : $contextExperiment->product()->first();
            $productNmId = (int) ($product?->nm_id ?? 0);
            $selectedId = $contextExperiment->wb_advert_id
                ? (int) $contextExperiment->wb_advert_id
                : null;
        }

        return [
            'success' => true,
            'campaign' => $this->mapRegistryCampaignRow(
                $registry->refresh(),
                $advertAfter,
                $productNmId,
                $selectedId,
                $this->runningAdvertIdsForCabinet((int) $cabinet->id),
            ),
            'messages' => ['Кампания поставлена на паузу'],
        ];
    }

    /**
     * Delete campaign on WB and remove from our registry.
     *
     * @return array{success: bool, experiment?: array<string, mixed>|null, messages: list<string>}
     */
    public function deleteCampaign(WbCabinet $cabinet, int $advertId, ?AbExperiment $contextExperiment = null): array
    {
        $registry = $this->requireOurCampaign($cabinet, $advertId);
        $apiKey = (string) ($cabinet->apikey ?? '');

        if ($apiKey === '') {
            return ['success' => false, 'messages' => ['У кабинета не задан API-ключ Wildberries']];
        }

        if ($this->isAdvertBusyByRunningAb((int) $cabinet->id, $advertId)) {
            return [
                'success' => false,
                'messages' => ['Кампания занята запущенным A/B-тестом — сначала остановите эксперимент.'],
            ];
        }

        $details = $this->advertApi->getAdverts($apiKey, [$advertId]);
        $advert = null;
        if ($details['success'] ?? false) {
            $advert = $this->firstAdvertFromPayload($details['data'] ?? null, $advertId);
        }

        $status = $advert !== null ? (int) Arr::get($advert, 'status', 0) : null;

        // Active campaigns often must be paused before delete.
        if ($status === 9) {
            $paused = $this->advertApi->pauseAdvert($apiKey, $advertId);
            if (! ($paused['success'] ?? false)) {
                return [
                    'success' => false,
                    'messages' => [
                        'Не удалось приостановить активную кампанию перед удалением: '
                        .($paused['message'] ?? 'ошибка WB API'),
                    ],
                ];
            }
        }

        if ($advert !== null && $status !== -1) {
            $deleted = $this->advertApi->deleteAdvert($apiKey, $advertId);
            if (! ($deleted['success'] ?? false)) {
                return [
                    'success' => false,
                    'messages' => [$deleted['message'] ?? 'Не удалось удалить кампанию в Wildberries'],
                ];
            }
        }

        // Unbind draft experiments that used this advert.
        AbExperiment::query()
            ->where('cabinet_id', $cabinet->id)
            ->where('wb_advert_id', $advertId)
            ->where('status', WbAbTestStatus::Draft->value)
            ->get()
            ->each(function (AbExperiment $exp): void {
                $this->unbindAdvertFromExperiment($exp);
            });

        $registry->delete();

        $experimentPayload = null;
        if ($contextExperiment) {
            $experimentPayload = $this->mapExperiment($contextExperiment->refresh());
        }

        return [
            'success' => true,
            'experiment' => $experimentPayload,
            'messages' => ['Кампания удалена в Wildberries и убрана из списка A/B'],
        ];
    }

    /**
     * Read campaign budget from WB (total ₽).
     *
     * @return array{success: bool, messages: list<string>, budget_total?: float|null, budget?: mixed}
     */
    public function getCampaignBudget(WbCabinet $cabinet, int $advertId): array
    {
        $this->requireOurCampaign($cabinet, $advertId);
        $apiKey = (string) ($cabinet->apikey ?? '');

        if ($apiKey === '') {
            return ['success' => false, 'messages' => ['У кабинета не задан API-ключ Wildberries']];
        }

        $result = $this->advertApi->getBudget($apiKey, $advertId);
        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'messages' => [$result['message'] ?? 'Не удалось получить бюджет кампании'],
            ];
        }

        $data = $result['data'] ?? null;
        $total = $this->advertApi->extractBudgetTotal($data);

        return [
            'success' => true,
            'budget' => $data,
            'budget_total' => $total,
            'messages' => [],
        ];
    }

    /**
     * Top up campaign budget on WB.
     *
     * @return array{
     *     success: bool,
     *     messages: list<string>,
     *     budget?: mixed,
     *     budget_total?: float|null,
     *     deposited_sum?: int
     * }
     */
    public function depositCampaignBudget(WbCabinet $cabinet, int $advertId, int $sum): array
    {
        $this->requireOurCampaign($cabinet, $advertId);
        $apiKey = (string) ($cabinet->apikey ?? '');

        if ($apiKey === '') {
            return ['success' => false, 'messages' => ['У кабинета не задан API-ключ Wildberries']];
        }

        if ($sum < self::MIN_BUDGET_DEPOSIT) {
            return [
                'success' => false,
                'messages' => ['Минимальная сумма пополнения бюджета — '.self::MIN_BUDGET_DEPOSIT.' ₽'],
            ];
        }
        if ($sum % 50 !== 0) {
            return [
                'success' => false,
                'messages' => ['Сумма пополнения бюджета должна быть кратна 50 ₽'],
            ];
        }

        $details = $this->advertApi->getAdverts($apiKey, [$advertId]);
        if (! ($details['success'] ?? false)) {
            return [
                'success' => false,
                'messages' => [$details['message'] ?? 'Не удалось получить кампанию'],
            ];
        }

        $advert = $this->firstAdvertFromPayload($details['data'] ?? null, $advertId);
        if ($advert === null) {
            return ['success' => false, 'messages' => ['Кампания не найдена в Wildberries']];
        }

        $status = (int) Arr::get($advert, 'status', 0);
        if ($status === -1) {
            return ['success' => false, 'messages' => ['Нельзя пополнить бюджет удалённой кампании']];
        }

        $deposit = $this->advertApi->depositBudget(
            $apiKey,
            $advertId,
            $sum,
            WbAdvertApiClient::BUDGET_DEPOSIT_TYPE_BALANCE,
        );

        if (! ($deposit['success'] ?? false)) {
            Log::warning('WB A/B testing: deposit on existing campaign failed', [
                'cabinet_id' => $cabinet->id,
                'advert_id' => $advertId,
                'sum' => $sum,
                'code' => $deposit['code'] ?? null,
                'message' => $deposit['message'] ?? null,
            ]);

            return [
                'success' => false,
                'messages' => [
                    'Не удалось пополнить бюджет: '.($deposit['message'] ?? 'ошибка WB API'),
                ],
            ];
        }

        // Prefer live budget after deposit (return=true payload may already contain totals).
        $budgetData = $deposit['data'] ?? null;
        $budgetTotal = $this->advertApi->extractBudgetTotal($budgetData);

        if ($budgetTotal === null) {
            $fresh = $this->advertApi->getBudget($apiKey, $advertId);
            if ($fresh['success'] ?? false) {
                $budgetData = $fresh['data'] ?? $budgetData;
                $budgetTotal = $this->advertApi->extractBudgetTotal($budgetData);
            }
        }

        $totalLabel = $budgetTotal !== null
            ? number_format($budgetTotal, 0, ',', ' ').' ₽'
            : null;

        return [
            'success' => true,
            'budget' => $budgetData,
            'budget_total' => $budgetTotal,
            'deposited_sum' => $sum,
            'messages' => [
                $totalLabel !== null
                    ? 'Бюджет пополнен на '.number_format($sum, 0, ',', ' ').' ₽. Сейчас на кампании: '.$totalLabel
                    : 'Бюджет кампании пополнен на '.number_format($sum, 0, ',', ' ').' ₽',
            ],
        ];
    }

    /**
     * Bind experiment to an existing *our* campaign that already contains the product.
     *
     * @return array{success: bool, experiment?: array<string, mixed>, campaign?: array<string, mixed>, messages: list<string>}
     */
    public function bindCampaignToExperiment(
        WbCabinet $cabinet,
        AbExperiment $experiment,
        int $advertId,
        bool $addProductIfMissing = true,
    ): array {
        if ($addProductIfMissing) {
            return $this->prepareCampaignForProduct($cabinet, $experiment, $advertId);
        }

        $this->assertDraftExperiment($experiment);
        $product = $this->requireExperimentProduct($experiment);
        $registry = $this->requireOurCampaign($cabinet, $advertId);
        $apiKey = (string) ($cabinet->apikey ?? '');

        if ($apiKey === '') {
            return ['success' => false, 'messages' => ['У кабинета не задан API-ключ Wildberries']];
        }

        $details = $this->advertApi->getAdverts($apiKey, [$advertId]);
        if (! ($details['success'] ?? false)) {
            return [
                'success' => false,
                'messages' => [$details['message'] ?? 'Не удалось получить кампанию'],
            ];
        }

        $advert = $this->firstAdvertFromPayload($details['data'] ?? null, $advertId);
        if ($advert === null) {
            return ['success' => false, 'messages' => ['Кампания не найдена']];
        }

        $guard = $this->assertCanEditCampaignNms($cabinet, $advertId, $advert);
        if ($guard !== null) {
            return ['success' => false, 'messages' => [$guard]];
        }

        $nmIds = $this->extractAdvertNmIds($advert);
        if (! in_array((int) $product->nm_id, $nmIds, true)) {
            return [
                'success' => false,
                'messages' => ['Сначала добавьте выбранный товар в кампанию или нажмите «Использовать для этого товара»'],
            ];
        }

        $this->unbindOtherDraftsFromAdvert($cabinet, $experiment, $advertId);
        $this->bindAdvertToExperiment(
            $experiment,
            $advertId,
            (string) Arr::get($advert, 'settings.name', $registry->name),
        );

        return [
            'success' => true,
            'experiment' => $this->mapExperiment($experiment->refresh()),
            'messages' => ['Кампания привязана к эксперименту'],
        ];
    }

    public function defaultCampaignName(AbProduct $product): string
    {
        $code = $this->nullableString($product->vendor_code);
        if ($code !== null) {
            return 'A/B тест — '.$code;
        }

        return 'A/B тест — '.(int) $product->nm_id;
    }

    private function registerOurCampaign(
        WbCabinet $cabinet,
        int $advertId,
        string $name,
        string $bidType,
        string $paymentType,
        AbExperiment $experiment,
    ): AbCampaign {
        return AbCampaign::query()->updateOrCreate(
            [
                'cabinet_id' => $cabinet->id,
                'wb_advert_id' => $advertId,
            ],
            [
                'name' => $name,
                'bid_type' => $bidType,
                'payment_type' => $paymentType,
                'created_by_experiment_id' => $experiment->id,
            ],
        );
    }

    private function requireOurCampaign(WbCabinet $cabinet, int $advertId): AbCampaign
    {
        if ($advertId <= 0) {
            throw ValidationException::withMessages([
                'advert_id' => 'Некорректный ID кампании.',
            ]);
        }

        $campaign = AbCampaign::query()
            ->where('cabinet_id', $cabinet->id)
            ->where('wb_advert_id', $advertId)
            ->first();

        if (! $campaign) {
            throw ValidationException::withMessages([
                'advert_id' => 'Кампания не создана этим сервисом. Доступны только кампании A/B-тестирования.',
            ]);
        }

        return $campaign;
    }

    /**
     * @return list<int>
     */
    private function runningAdvertIdsForCabinet(int $cabinetId): array
    {
        return AbExperiment::query()
            ->where('cabinet_id', $cabinetId)
            ->where('status', WbAbTestStatus::Running->value)
            ->whereNotNull('wb_advert_id')
            ->pluck('wb_advert_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function isAdvertBusyByRunningAb(int $cabinetId, int $advertId): bool
    {
        return in_array($advertId, $this->runningAdvertIdsForCabinet($cabinetId), true);
    }

    /**
     * @param  array<string, mixed>  $advert
     */
    private function assertCanEditCampaignNms(WbCabinet $cabinet, int $advertId, array $advert): ?string
    {
        $status = (int) Arr::get($advert, 'status', 0);

        if ($status === 9) {
            return 'Нельзя менять товары: кампания активна на Wildberries. Остановите или приостановите её.';
        }

        if (! in_array($status, WbAdvertApiClient::SERVICE_NMS_EDITABLE_STATUSES, true)) {
            return 'В текущем статусе кампании нельзя изменить список товаров.';
        }

        if (in_array($advertId, $this->runningAdvertIdsForCabinet((int) $cabinet->id), true)) {
            return 'Кампания занята запущенным A/B-тестом. Дождитесь завершения или остановите тест.';
        }

        return null;
    }

    private function unbindOtherDraftsFromAdvert(
        WbCabinet $cabinet,
        AbExperiment $current,
        int $advertId,
    ): void {
        AbExperiment::query()
            ->where('cabinet_id', $cabinet->id)
            ->where('wb_advert_id', $advertId)
            ->where('status', WbAbTestStatus::Draft->value)
            ->where('id', '!=', $current->id)
            ->get()
            ->each(function (AbExperiment $other): void {
                $this->unbindAdvertFromExperiment($other);
            });
    }

    private function bindAdvertToExperiment(AbExperiment $experiment, int $advertId, ?string $name): void
    {
        $experiment->wb_advert_id = $advertId;
        $experiment->wb_advert_name = $this->nullableString($name);
        $experiment->campaign_bound_at = now();
        if ((int) $experiment->progress < self::PROGRESS_AFTER_CAMPAIGN) {
            $experiment->progress = self::PROGRESS_AFTER_CAMPAIGN;
        }
        $experiment->save();
    }

    private function unbindAdvertFromExperiment(AbExperiment $experiment): void
    {
        $experiment->wb_advert_id = null;
        $experiment->wb_advert_name = null;
        $experiment->campaign_bound_at = null;
        if ((int) $experiment->progress === self::PROGRESS_AFTER_CAMPAIGN) {
            $experiment->progress = 0;
        }
        $experiment->save();
    }

    private function assertDraftExperiment(AbExperiment $experiment): void
    {
        $this->assertEditableExperiment(
            $experiment,
            'Изменять рекламную кампанию можно у черновика, остановленного или ошибочного эксперимента.',
        );
    }

    private function assertDraftExperimentForPhotos(AbExperiment $experiment): void
    {
        $this->assertEditableExperiment(
            $experiment,
            'Изменять фотографии можно у черновика, остановленного или ошибочного эксперимента.',
        );
    }

    private function assertDraftExperimentForSettings(AbExperiment $experiment): void
    {
        $this->assertEditableExperiment(
            $experiment,
            'Изменять настройки можно у черновика, остановленного или ошибочного эксперимента.',
        );
    }

    private function assertEditableExperiment(AbExperiment $experiment, string $message): void
    {
        $status = $experiment->status instanceof WbAbTestStatus
            ? $experiment->status
            : WbAbTestStatus::tryFrom((string) $experiment->status);

        if ($status === null || ! $status->isEditable()) {
            throw ValidationException::withMessages([
                'experiment' => $message,
            ]);
        }
    }

    /**
     * @return array{impressions_per_photo:int,impressions_per_round:int,round_minutes:int,cpm:int}
     */
    private function resolveExperimentSettings(AbExperiment $experiment): array
    {
        return [
            'impressions_per_photo' => $experiment->impressions_per_photo !== null
                ? (int) $experiment->impressions_per_photo
                : self::DEFAULT_IMPRESSIONS_PER_PHOTO,
            'impressions_per_round' => $experiment->impressions_per_round !== null
                ? (int) $experiment->impressions_per_round
                : self::DEFAULT_IMPRESSIONS_PER_ROUND,
            'round_minutes' => $experiment->round_minutes !== null
                ? (int) $experiment->round_minutes
                : self::DEFAULT_ROUND_MINUTES,
            'cpm' => $experiment->cpm !== null
                ? (int) $experiment->cpm
                : self::DEFAULT_CPM,
        ];
    }

    private function areSettingsPersisted(AbExperiment $experiment): bool
    {
        return $experiment->impressions_per_photo !== null
            && $experiment->impressions_per_round !== null
            && $experiment->round_minutes !== null
            && $experiment->cpm !== null;
    }

    /**
     * Payment type of the bound WB campaign (registry). Defaults to cpm when unbound/unknown.
     */
    private function resolveCampaignPaymentType(AbExperiment $experiment): string
    {
        $advertId = (int) ($experiment->wb_advert_id ?? 0);
        if ($advertId <= 0) {
            return 'cpm';
        }

        $paymentType = AbCampaign::query()
            ->where('cabinet_id', (int) $experiment->cabinet_id)
            ->where('wb_advert_id', $advertId)
            ->value('payment_type');

        $paymentType = is_string($paymentType) ? strtolower(trim($paymentType)) : '';

        return $paymentType === 'cpc' ? 'cpc' : 'cpm';
    }

    /**
     * Bid field (stored as cpm): min depends on campaign payment type.
     * CPM ≥ 50 ₽ / 1000 impressions; CPC ≥ 1 ₽ per click.
     */
    private function minBidForPaymentType(string $paymentType): int
    {
        return $paymentType === 'cpc' ? 1 : 50;
    }

    /**
     * @param  array{impressions_per_photo:int,impressions_per_round:int,round_minutes:int,cpm:int}  $settings
     */
    private function areSettingsValid(array $settings, string $paymentType = 'cpm'): bool
    {
        return $this->validateSettingsPayload($settings, $paymentType) === [];
    }

    /**
     * @param  array{impressions_per_photo:int,impressions_per_round:int,round_minutes:int,cpm:int}  $settings
     * @return array<string, list<string>>
     */
    private function validateSettingsPayload(array $settings, string $paymentType = 'cpm'): array
    {
        $errors = [];

        $target = (int) ($settings['impressions_per_photo'] ?? 0);
        $perRound = (int) ($settings['impressions_per_round'] ?? 0);
        $minutes = (int) ($settings['round_minutes'] ?? 0);
        $bid = (int) ($settings['cpm'] ?? 0);
        $paymentType = $paymentType === 'cpc' ? 'cpc' : 'cpm';
        $minBid = $this->minBidForPaymentType($paymentType);

        if ($target < 1000 || $target > 50_000_000) {
            $errors['impressions_per_photo'] = ['Укажите от 1 000 до 50 000 000 показов на одно фото.'];
        }

        if ($perRound < 100) {
            $errors['impressions_per_round'] = ['Минимум 100 показов за круг.'];
        } elseif ($target >= 1000 && $perRound > $target) {
            $errors['impressions_per_round'] = ['Показов за круг не может быть больше, чем всего показов на одно фото.'];
        }

        if ($minutes < 5 || $minutes > 24 * 60) {
            $errors['round_minutes'] = ['Длительность круга: от 5 до 1440 минут.'];
        }

        if ($bid < $minBid || $bid > 50_000) {
            if ($paymentType === 'cpc') {
                $errors['cpm'] = ['CPC (цена за клик): от 1 до 50 000 ₽.'];
            } else {
                $errors['cpm'] = ['CPM: от 50 до 50 000 ₽.'];
            }
        }

        return $errors;
    }

    /**
     * @param  array{impressions_per_photo:int,impressions_per_round:int,round_minutes:int,cpm:int}  $settings
     */
    private function formatSettingsSummary(array $settings, string $paymentType = 'cpm'): string
    {
        $fmt = static fn (int $value): string => number_format($value, 0, ',', ' ');
        $bidLabel = $paymentType === 'cpc' ? 'CPC' : 'CPM';

        return sprintf(
            '%s на фото • %s за круг • %s мин • %s %s ₽',
            $fmt((int) $settings['impressions_per_photo']),
            $fmt((int) $settings['impressions_per_round']),
            $fmt((int) $settings['round_minutes']),
            $bidLabel,
            $fmt((int) $settings['cpm']),
        );
    }

    private function syncSettingsProgress(AbExperiment $experiment): void
    {
        $photosCount = (int) $experiment->photos()->count();
        $settings = $this->resolveExperimentSettings($experiment);
        $paymentType = $this->resolveCampaignPaymentType($experiment);
        $ready = $this->areSettingsPersisted($experiment) && $this->areSettingsValid($settings, $paymentType);
        $progress = (int) $experiment->progress;

        if ($ready && $photosCount >= self::MIN_PHOTOS_TO_CONTINUE) {
            if ($progress < self::PROGRESS_AFTER_SETTINGS) {
                $experiment->progress = self::PROGRESS_AFTER_SETTINGS;
                $experiment->save();
            }

            return;
        }

        // Step down from settings progress if no longer ready, keep photos/campaign floors.
        if ($progress === self::PROGRESS_AFTER_SETTINGS || (
            $progress > self::PROGRESS_AFTER_PHOTOS && $progress <= self::PROGRESS_AFTER_SETTINGS
        )) {
            $target = $photosCount >= self::MIN_PHOTOS_TO_CONTINUE
                ? self::PROGRESS_AFTER_PHOTOS
                : ($experiment->wb_advert_id ? self::PROGRESS_AFTER_CAMPAIGN : 0);

            if ($progress !== $target) {
                $experiment->progress = $target;
                $experiment->save();
            }
        }
    }

    private function assertPhotoBelongsToExperiment(
        AbExperimentPhoto $photo,
        AbExperiment $experiment,
        WbCabinet $cabinet,
    ): void {
        if ((int) $photo->ab_experiment_id !== (int) $experiment->id
            || (int) $photo->cabinet_id !== (int) $cabinet->id
        ) {
            throw ValidationException::withMessages([
                'photo' => 'Фотография не найдена в этом эксперименте.',
            ]);
        }
    }

    private function validatePhotoFile(UploadedFile $file): ?string
    {
        if (! $file->isValid()) {
            return 'Некорректный файл изображения.';
        }

        $mime = strtolower((string) ($file->getMimeType() ?: $file->getClientMimeType()));
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];

        $mimeOk = in_array($mime, self::PHOTO_ALLOWED_MIMES, true)
            || ($mime === 'image/jpg');
        $extOk = in_array($extension, $allowedExtensions, true);

        if (! $mimeOk && ! $extOk) {
            return 'Допустимы только JPEG, PNG и WEBP.';
        }

        $size = (int) ($file->getSize() ?: 0);
        if ($size <= 0 || $size > self::PHOTO_MAX_BYTES) {
            return 'Размер файла не должен превышать 10 МБ.';
        }

        return null;
    }

    private function storePhotoFile(WbCabinet $cabinet, AbExperiment $experiment, UploadedFile $file): string
    {
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: 'jpg'));
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $extension = match (strtolower((string) $file->getMimeType())) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            };
        }

        $directory = sprintf(
            'wb/ab-testing/%d/%d',
            (int) $cabinet->id,
            (int) $experiment->id,
        );
        $fileName = Str::uuid()->toString().'.'.$extension;

        $stored = Storage::disk(self::PHOTO_DISK)->putFileAs($directory, $file, $fileName);
        if (! is_string($stored) || $stored === '') {
            throw new \RuntimeException('Storage putFileAs failed');
        }

        return $stored;
    }

    private function compactPhotoSortOrder(AbExperiment $experiment): void
    {
        $photos = AbExperimentPhoto::query()
            ->where('ab_experiment_id', $experiment->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($photos as $index => $photo) {
            if ((int) $photo->sort_order !== $index) {
                $photo->sort_order = $index;
                $photo->save();
            }
        }
    }

    private function syncPhotosProgress(AbExperiment $experiment): void
    {
        $count = (int) $experiment->photos()->count();
        $progress = (int) $experiment->progress;

        if ($count >= self::MIN_PHOTOS_TO_CONTINUE) {
            $target = self::PROGRESS_AFTER_PHOTOS;
            $paymentType = $this->resolveCampaignPaymentType($experiment);
            if ($this->areSettingsPersisted($experiment)
                && $this->areSettingsValid($this->resolveExperimentSettings($experiment), $paymentType)
            ) {
                $target = self::PROGRESS_AFTER_SETTINGS;
            }

            if ($progress < $target) {
                $experiment->progress = $target;
                $experiment->save();
            }

            return;
        }

        // Drop below photos threshold: keep campaign progress if bound, else 0.
        // Also step down from settings progress (70) when photos fall below 2.
        $target = $experiment->wb_advert_id ? self::PROGRESS_AFTER_CAMPAIGN : 0;
        if ($progress <= self::PROGRESS_AFTER_SETTINGS && $progress > $target) {
            $experiment->progress = $target;
            $experiment->save();
        }
    }

    private function requireExperimentProduct(AbExperiment $experiment): AbProduct
    {
        $product = $experiment->relationLoaded('product')
            ? $experiment->product
            : $experiment->product()->first();

        if (! $product) {
            throw ValidationException::withMessages([
                'experiment' => 'У эксперимента не найден товар.',
            ]);
        }

        return $product;
    }

    /**
     * @param  array<string, mixed>|null  $liveAdvert
     * @param  list<int>  $busyAdvertIds
     * @return array<string, mixed>
     */
    private function mapRegistryCampaignRow(
        AbCampaign $registry,
        ?array $liveAdvert,
        int $productNmId,
        ?int $selectedAdvertId,
        array $busyAdvertIds,
    ): array {
        $id = (int) $registry->wb_advert_id;
        $isBusy = in_array($id, $busyAdvertIds, true);
        $missingOnWb = $liveAdvert === null;

        if ($liveAdvert !== null) {
            $status = (int) Arr::get($liveAdvert, 'status', 0);
            $nmIds = $this->extractAdvertNmIds($liveAdvert);
            $bidType = (string) Arr::get($liveAdvert, 'bid_type', $registry->bid_type ?? '');
            $paymentType = (string) Arr::get($liveAdvert, 'settings.payment_type', $registry->payment_type ?? '');
            $name = (string) Arr::get($liveAdvert, 'settings.name', $registry->name);
            $placements = Arr::get($liveAdvert, 'settings.placements', []);
        } else {
            $status = null;
            $nmIds = [];
            $bidType = (string) ($registry->bid_type ?? '');
            $paymentType = (string) ($registry->payment_type ?? '');
            $name = $registry->name;
            $placements = [];
        }

        $canEditNms = ! $missingOnWb
            && ! $isBusy
            && $status !== null
            && in_array($status, WbAdvertApiClient::SERVICE_NMS_EDITABLE_STATUSES, true);

        $containsProduct = in_array($productNmId, $nmIds, true);

        // Row click attaches campaign to experiment (auto-prepare nms under the hood).
        $canSelect = $canEditNms;

        $canPause = ! $missingOnWb && ! $isBusy && $status === 9;
        $canDelete = ! $missingOnWb
            && ! $isBusy
            && $status !== null
            && $status !== -1;
        $canDeposit = ! $missingOnWb && $status !== null && $status !== -1;

        $editBlockReason = null;
        if ($missingOnWb) {
            $editBlockReason = 'Кампания не найдена в Wildberries';
        } elseif ($isBusy) {
            $editBlockReason = 'Занята запущенным A/B-тестом';
        } elseif ($status === 9) {
            $editBlockReason = 'Активна — сначала поставьте на паузу, чтобы выбрать для эксперимента';
        } elseif ($status !== null && ! in_array($status, WbAdvertApiClient::SERVICE_NMS_EDITABLE_STATUSES, true)) {
            $editBlockReason = 'Статус не позволяет привязать кампанию к эксперименту';
        }

        return [
            'id' => $id,
            'registry_id' => $registry->id,
            'name' => $name !== '' ? $name : ('Кампания #'.$id),
            'status' => $status,
            'status_label' => $missingOnWb
                ? 'Не найдена на WB'
                : $this->campaignStatusLabel((int) $status),
            'status_variant' => $missingOnWb
                ? 'destructive'
                : $this->campaignStatusVariant((int) $status),
            'bid_type' => $bidType,
            'bid_type_label' => $this->bidTypeLabel($bidType),
            'payment_type' => $paymentType,
            'payment_type_label' => $this->paymentTypeLabel($paymentType),
            'placements' => is_array($placements) ? $placements : [],
            'nm_ids' => $nmIds,
            'nm_count' => count($nmIds),
            'contains_product' => $containsProduct,
            'can_edit_nms' => $canEditNms,
            'can_select' => $canSelect,
            'can_prepare' => $canEditNms,
            'can_pause' => $canPause,
            'can_delete' => $canDelete,
            'can_deposit' => $canDeposit,
            'is_busy_by_ab' => $isBusy,
            'is_missing_on_wb' => $missingOnWb,
            'edit_block_reason' => $editBlockReason,
            'is_selected' => $selectedAdvertId !== null && $selectedAdvertId === $id,
            'budget' => null,
            'ctr' => null,
            'cpm' => null,
        ];
    }

    /**
     * @return list<int>
     */
    private function extractAdvertNmIds(array $advert): array
    {
        $nmIds = [];
        foreach ((array) Arr::get($advert, 'nm_settings', []) as $nmData) {
            if (! is_array($nmData)) {
                continue;
            }
            $nmId = (int) Arr::get($nmData, 'nm_id', 0);
            if ($nmId > 0) {
                $nmIds[] = $nmId;
            }
        }

        return array_values(array_unique($nmIds));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function firstAdvertFromPayload(mixed $data, int $advertId): ?array
    {
        $rows = Arr::get($data, 'adverts', is_array($data) ? $data : []);
        if (! is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ((int) Arr::get($row, 'id', 0) === $advertId) {
                return $row;
            }
        }

        $first = reset($rows);

        return is_array($first) ? $first : null;
    }

    private function campaignStatusLabel(int $status): string
    {
        return match ($status) {
            -1 => 'Удаляется',
            4 => 'Готова к запуску',
            7 => 'Завершена',
            8 => 'Отменена',
            9 => 'Активна',
            11 => 'Приостановлена',
            default => 'Статус '.$status,
        };
    }

    private function campaignStatusVariant(int $status): string
    {
        return match ($status) {
            9 => 'success',
            4 => 'default',
            11 => 'warning',
            7, 8 => 'secondary',
            -1 => 'destructive',
            default => 'outline',
        };
    }

    private function bidTypeLabel(string $bidType): string
    {
        return match ($bidType) {
            'manual' => 'Ручная ставка',
            'unified' => 'Единая ставка',
            default => $bidType !== '' ? $bidType : '—',
        };
    }

    private function paymentTypeLabel(string $paymentType): string
    {
        return match (strtolower($paymentType)) {
            'cpm' => 'CPM',
            'cpc' => 'CPC',
            default => $paymentType !== '' ? strtoupper($paymentType) : '—',
        };
    }

    /**
     * POST Analytics item-rating v2.
     *
     * @param  array<string, mixed>  $body
     * @return array{data: array<string, mixed>|null, function: string}
     */
    public function apiPostItemRating(string $apiKey, array $body): array
    {
        $url = 'https://seller-analytics-api.wildberries.ru/api/analytics/v2/item-rating';

        $result = $this->postRequest($url, $apiKey, $body);

        return ['data' => $result, 'function' => 'apiPostItemRating'];
    }

    /**
     * @param  array<string, mixed>  $goods
     */
    private function extractGoodsPrice(array $goods): ?float
    {
        $sizes = $goods['sizes'] ?? null;
        if (! is_array($sizes) || $sizes === []) {
            return null;
        }

        $prices = [];
        foreach ($sizes as $size) {
            if (! is_array($size)) {
                continue;
            }
            $value = $size['price'] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $number = (float) $value;
            if ($number > 0) {
                $prices[] = $number;
            }
        }

        if ($prices === []) {
            return null;
        }

        return min($prices);
    }

    /**
     * @param  mixed  $data  Decoded API JSON (after parseApiResponse).
     * @return list<array<string, mixed>>
     */
    private function extractItemRatingRows(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        $candidates = [
            data_get($data, 'data.items'),
            data_get($data, 'items'),
            data_get($data, 'data.products'),
            data_get($data, 'products'),
            data_get($data, 'data.cards'),
            data_get($data, 'cards'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate) || $candidate === [] || ! $this->isListArray($candidate)) {
                continue;
            }

            $rows = [];
            foreach ($candidate as $row) {
                if (! is_array($row)) {
                    continue;
                }

                // Nested product object (sales-funnel style samples).
                if (isset($row['product']) && is_array($row['product'])) {
                    $rows[] = array_merge($row['product'], [
                        'feedbackRating' => $row['product']['feedbackRating']
                            ?? $row['feedbackRating']
                            ?? null,
                    ]);
                    continue;
                }

                if (
                    array_key_exists('nmId', $row)
                    || array_key_exists('nmID', $row)
                    || array_key_exists('feedbackRating', $row)
                ) {
                    $rows[] = $row;
                }
            }

            if ($rows !== []) {
                return $rows;
            }
        }

        return [];
    }

    /**
     * Prefer feedbackRating.current (stars 1–5). Fallback to scalar feedbackRating.
     *
     * @param  array<string, mixed>  $item
     */
    private function extractFeedbackRatingValue(array $item): ?float
    {
        $feedback = $item['feedbackRating'] ?? $item['feedback_rating'] ?? null;

        if (is_array($feedback)) {
            $current = $feedback['current'] ?? null;
            if ($current === null || $current === '') {
                return null;
            }

            return round((float) $current, 2);
        }

        if ($feedback !== null && $feedback !== '') {
            return round((float) $feedback, 2);
        }

        return null;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isListArray(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapProductRow(AbProduct $product): array
    {
        $latest = $product->relationLoaded('latestExperiment')
            ? $product->latestExperiment
            : null;

        if ($latest instanceof AbExperiment) {
            $status = $latest->status instanceof WbAbTestStatus
                ? $latest->status
                : WbAbTestStatus::tryFrom((string) $latest->status) ?? WbAbTestStatus::Draft;
            $testStatus = $status->value;
            $testStatusLabel = $status->label();
            $testCreatedAt = optional($latest->created_at)?->toIso8601String();
        } else {
            $testStatus = WbAbTestStatus::NotCreated->value;
            $testStatusLabel = WbAbTestStatus::NotCreated->label();
            $testCreatedAt = null;
        }

        return [
            'id' => $product->id,
            'nm_id' => $product->nm_id,
            'vendor_code' => $product->vendor_code,
            'title' => $product->title,
            'brand' => $product->brand,
            'subject_name' => $product->subject_name,
            'photo_url' => $product->photo_url,
            'price' => $product->price,
            'rating' => $product->rating,
            'test_status' => $testStatus,
            'test_status_label' => $testStatusLabel,
            'test_created_at' => $testCreatedAt,
        ];
    }

    /**
     * @param  mixed  $photos
     */
    private function extractPhotoUrl($photos): ?string
    {
        if (! is_array($photos) || $photos === []) {
            return null;
        }

        $first = $photos[0];
        if (! is_array($first)) {
            return null;
        }

        foreach (['c246x328', 'square', 'tm', 'big'] as $key) {
            $url = $first[$key] ?? null;
            if (is_string($url) && trim($url) !== '') {
                return trim($url);
            }
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
