<?php

namespace App\Services\Subscriber\Oz;

use App\Enums\OzAbTestStatus;
use App\Models\Subscribers\Oz\AbTesting\AbCampaign;
use App\Models\Subscribers\Oz\AbTesting\AbExperiment;
use App\Models\Subscribers\Oz\AbTesting\AbExperimentCycle;
use App\Models\Subscribers\Oz\AbTesting\AbExperimentEvent;
use App\Models\Subscribers\Oz\AbTesting\AbExperimentPhoto;
use App\Models\Subscribers\Oz\AbTesting\AbProduct;
use App\Models\Subscribers\Oz\OzCabinet;
use App\Services\Ozon\OzonApiService;
use App\Services\Ozon\OzonPerformanceApiService;
use App\Services\Subscriber\Oz\AbTesting\OzAbExperimentEngine;
use App\Services\Subscriber\Oz\AbTesting\OzAbExperimentJournal;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OzAbTestingService
{
    private const PROGRESS_AFTER_CAMPAIGN = 30;

    private const PROGRESS_AFTER_PHOTOS = 50;

    private const PROGRESS_AFTER_SETTINGS = 70;

    public const MAX_PHOTOS = 6;

    public const MIN_PHOTOS_TO_CONTINUE = 2;

    public const DEFAULT_IMPRESSIONS_PER_PHOTO = 100_000;

    public const DEFAULT_IMPRESSIONS_PER_ROUND = 10_000;

    public const DEFAULT_ROUND_MINUTES = 30;

    public const DEFAULT_CPC = 15;

    private const PHOTO_DISK = 'public';

    private const PHOTO_MAX_BYTES = 10 * 1024 * 1024;

    /** @var list<string> */
    private const PHOTO_ALLOWED_MIMES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
    ];

    public const USABLE_CAMPAIGN_STATES = [
        'CAMPAIGN_STATE_RUNNING',
        'CAMPAIGN_STATE_INACTIVE',
    ];

    public function __construct(
        private readonly OzonApiService $sellerApi,
        private readonly OzonPerformanceApiService $performanceApi,
        private readonly OzAbExperimentEngine $experimentEngine,
        private readonly OzAbExperimentJournal $journal,
    ) {
    }

    /**
     * Список карточек товара: одна строка = товар, внутри — его SKU.
     * Пагинация по товарам, не по размерам.
     *
     * @return array{items: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listProducts(int $cabinetId, Request $request): array
    {
        $perPage = max(1, min(100, $request->integer('per_page', 25)));
        $page = max(1, $request->integer('page', 1));
        $search = trim((string) $request->input('search', ''));

        $query = AbProduct::query()->where('cabinet_id', $cabinetId);

        if ($search !== '') {
            $matched = (clone $query)
                ->where(function ($builder) use ($search) {
                    $builder->where('offer_id', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('oz_product_id', 'like', "%{$search}%")
                        ->orWhere('title', 'like', "%{$search}%");
                })
                ->get(['id', 'model_id', 'title', 'oz_product_id']);

            $keys = $matched->map(fn (AbProduct $product) => $this->productGroupKey($product))->unique()->all();
            if ($keys === []) {
                return $this->emptyProductList($page, $perPage);
            }

            $siblingIds = $query
                ->get(['id', 'model_id', 'title', 'oz_product_id'])
                ->filter(fn (AbProduct $product) => in_array($this->productGroupKey($product), $keys, true))
                ->pluck('id')
                ->all();

            $products = AbProduct::query()
                ->whereIn('id', $siblingIds)
                ->with('latestExperiment')
                ->orderBy('title')
                ->orderBy('sku')
                ->orderBy('offer_id')
                ->get();
        } else {
            $products = $query
                ->with('latestExperiment')
                ->orderBy('title')
                ->orderBy('sku')
                ->orderBy('offer_id')
                ->get();
        }

        $groups = $products
            ->groupBy(fn (AbProduct $product) => $this->productGroupKey($product))
            ->map(fn ($items, $key) => $this->mapProductGroup((string) $key, $items))
            ->sortBy(fn (array $group) => mb_strtolower((string) ($group['title'] ?? '')).'|'.$group['group_key'])
            ->values();

        $total = $groups->count();
        $pageItems = $groups->slice(($page - 1) * $perPage, $perPage)->values()->all();
        $lastPage = max(1, (int) ceil($total / $perPage));
        if ($page > $lastPage && $total > 0) {
            $page = $lastPage;
            $pageItems = $groups->slice(($page - 1) * $perPage, $perPage)->values()->all();
        }

        return [
            'items' => $pageItems,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    /**
     * @return array{success: bool, messages: list<string>, synced?: int}
     */
    public function syncProducts(OzCabinet $cabinet): array
    {
        $apiKey = (string) $cabinet->apikey;
        $clientId = (string) $cabinet->client_id;
        $lastId = '';
        $synced = 0;

        try {
            for ($page = 0; $page < 200; $page++) {
                $list = $this->sellerApi->getProductsList($apiKey, $clientId, [
                    'filter' => ['visibility' => 'ALL'],
                    'last_id' => $lastId,
                    'limit' => 100,
                ]);
                if (! ($list['success'] ?? false)) {
                    return [
                        'success' => false,
                        'messages' => [$this->experimentEngine->apiMessage($list, 'Не удалось получить список товаров')],
                    ];
                }

                $items = Arr::get($list, 'data.result.items', Arr::get($list, 'data.items', []));
                if (! is_array($items) || $items === []) {
                    break;
                }

                $productIds = [];
                foreach ($items as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $id = (int) ($item['product_id'] ?? $item['id'] ?? 0);
                    if ($id > 0) {
                        $productIds[] = $id;
                    }
                }

                foreach (array_chunk($productIds, 100) as $chunk) {
                    $info = $this->sellerApi->getProductsInfo($apiKey, $clientId, $chunk);
                    $details = Arr::get($info, 'data.items', Arr::get($info, 'data.result.items', []));
                    if (! is_array($details)) {
                        continue;
                    }
                    foreach ($details as $detail) {
                        if (! is_array($detail)) {
                            continue;
                        }
                        $this->upsertProductFromInfo((int) $cabinet->id, $detail);
                        $synced++;
                    }
                }

                $lastId = $this->firstString(Arr::get($list, 'data.result.last_id', Arr::get($list, 'data.last_id', '')));
                if ($lastId === '' || count($items) < 100) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            Log::error('[OzAbTestingService] sync failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return ['success' => false, 'messages' => ['Не удалось обновить список товаров']];
        }

        return [
            'success' => true,
            'messages' => ['Список товаров обновлён: '.$synced],
            'synced' => $synced,
        ];
    }

    public function getProductForCabinet(int $cabinetId, int $productId): ?AbProduct
    {
        return AbProduct::query()
            ->where('cabinet_id', $cabinetId)
            ->where('id', $productId)
            ->first();
    }

    public function getExperimentForCabinet(int $cabinetId, int $experimentId): ?AbExperiment
    {
        return AbExperiment::query()
            ->where('cabinet_id', $cabinetId)
            ->where('id', $experimentId)
            ->first();
    }

    /**
     * @return list<string>
     */
    public function experimentDetailRelations(): array
    {
        return ['photos', 'product', 'events', 'cycles'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listExperiments(int $cabinetId, int $productId): array
    {
        return AbExperiment::query()
            ->where('cabinet_id', $cabinetId)
            ->where('ab_product_id', $productId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (AbExperiment $experiment) => $this->mapExperiment($experiment))
            ->values()
            ->all();
    }

    public function createDraftExperiment(OzCabinet $cabinet, AbProduct $product, ?string $name = null): AbExperiment
    {
        $name = trim((string) $name);
        if ($name === '') {
            $name = 'Эксперимент '.now('Europe/Moscow')->format('d.m.Y H:i');
        }

        return AbExperiment::query()->create([
            'ab_product_id' => $product->id,
            'cabinet_id' => $cabinet->id,
            'name' => $name,
            'status' => OzAbTestStatus::Draft->value,
            'progress' => 0,
            'sku' => $product->sku,
        ]);
    }

    public function renameExperiment(AbExperiment $experiment, string $name): AbExperiment
    {
        $experiment->name = trim($name);
        $experiment->save();

        return $experiment;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{success: bool, experiment?: array<string, mixed>, messages: list<string>}
     */
    public function updateExperimentSettings(OzCabinet $cabinet, AbExperiment $experiment, array $input): array
    {
        $this->assertEditableExperiment($experiment);

        $experiment->impressions_per_photo = (int) $input['impressions_per_photo'];
        $experiment->impressions_per_round = (int) $input['impressions_per_round'];
        $experiment->round_minutes = (int) $input['round_minutes'];
        $experiment->cpm = (int) $input['cpm'];
        $this->refreshSetupProgress($experiment);
        $experiment->save();

        return [
            'success' => true,
            'experiment' => $this->mapExperiment($experiment->fresh($this->experimentDetailRelations())),
            'messages' => ['Настройки сохранены'],
        ];
    }

    /**
     * @return array{success: bool, experiment?: array<string, mixed>, messages: list<string>}
     */
    public function startExperiment(OzCabinet $cabinet, AbExperiment $experiment): array
    {
        $result = $this->experimentEngine->start($cabinet, $experiment);
        if (($result['success'] ?? false) && isset($result['experiment'])) {
            $result['experiment'] = $this->mapExperiment(
                $result['experiment']->load($this->experimentDetailRelations())
            );
        }

        return $result;
    }

    /**
     * @return array{success: bool, experiment?: array<string, mixed>, messages: list<string>}
     */
    public function stopExperiment(OzCabinet $cabinet, AbExperiment $experiment): array
    {
        $result = $this->experimentEngine->stop($cabinet, $experiment);
        if (($result['success'] ?? false) && isset($result['experiment'])) {
            $result['experiment'] = $this->mapExperiment(
                $result['experiment']->load($this->experimentDetailRelations())
            );
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function mapProductRow(AbProduct $product): array
    {
        $latest = $product->latestExperiment;
        $status = $latest?->status instanceof OzAbTestStatus
            ? $latest->status
            : ($latest ? OzAbTestStatus::tryFrom((string) $latest->status) : OzAbTestStatus::NotCreated);
        $status ??= OzAbTestStatus::NotCreated;

        return [
            'id' => $product->id,
            'oz_product_id' => (int) $product->oz_product_id,
            'offer_id' => $product->offer_id,
            'sku' => $product->sku ? (int) $product->sku : null,
            'model_id' => $product->model_id ? (int) $product->model_id : null,
            'group_key' => $this->productGroupKey($product),
            'nm_id' => $product->sku ? (int) $product->sku : (int) $product->oz_product_id,
            'vendor_code' => $product->offer_id,
            'title' => $product->title,
            'photo_url' => $product->photo_url,
            'test_status' => $status->value,
            'test_status_label' => $status->label(),
            'updated_at' => optional($product->updated_at)?->toIso8601String(),
        ];
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
        $status = $experiment->status instanceof OzAbTestStatus
            ? $experiment->status
            : OzAbTestStatus::tryFrom((string) $experiment->status) ?? OzAbTestStatus::Draft;

        if ($experiment->relationLoaded('photos')) {
            $photosCount = $experiment->photos->count();
        } else {
            $photosCount = (int) $experiment->photos()->count();
        }

        $settings = $this->resolveExperimentSettings($experiment);
        $settingsReady = $this->experimentEngine->areSettingsReady($experiment);
        $canContinueWorkspace = $photosCount >= self::MIN_PHOTOS_TO_CONTINUE && $settingsReady;
        $canEdit = $status->isEditable();
        $canStart = $status->isStartable()
            && $settingsReady
            && $photosCount >= self::MIN_PHOTOS_TO_CONTINUE
            && (bool) $experiment->oz_campaign_id;
        $canStop = $status === OzAbTestStatus::Running;

        $photoAggregates = $this->experimentEngine->photoAggregates($experiment);
        $openCycle = $status === OzAbTestStatus::Running
            ? $experiment->resolveOpenCycle()
            : null;
        $progressMeta = $this->resolveProgressMeta($experiment, $status, $settings['impressions_per_photo'], $openCycle);

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
            'oz_campaign_id' => $experiment->oz_campaign_id ? (int) $experiment->oz_campaign_id : null,
            'wb_advert_id' => $experiment->oz_campaign_id ? (int) $experiment->oz_campaign_id : null,
            'oz_campaign_name' => $experiment->oz_campaign_name,
            'wb_advert_name' => $experiment->oz_campaign_name,
            'campaign_payment_type' => 'cpc',
            'campaign_bound_at' => optional($experiment->campaign_bound_at)?->toIso8601String(),
            'sku' => $experiment->sku ? (int) $experiment->sku : null,
            'created_at' => optional($experiment->created_at)?->toIso8601String(),
            'started_at' => optional($experiment->started_at)?->toIso8601String(),
            'finished_at' => optional($experiment->finished_at)?->toIso8601String(),
            'error_message' => $experiment->error_message,
            'consecutive_failures' => (int) ($experiment->consecutive_failures ?? 0),
            'max_consecutive_failures' => OzAbExperimentEngine::MAX_CONSECUTIVE_FAILURES,
            'current_photo_id' => $openCycle ? (int) $openCycle->ab_experiment_photo_id : null,
            'current_cycle_id' => $openCycle ? (int) $openCycle->id : null,
            'winner_photo_id' => $experiment->winner_photo_id ? (int) $experiment->winner_photo_id : null,
            'last_processed_at' => optional($experiment->last_processed_at)?->toIso8601String(),
            'photos_count' => $photosCount,
            'can_continue_photos' => $photosCount >= self::MIN_PHOTOS_TO_CONTINUE,
            'settings' => $settings,
            'settings_summary' => $this->formatSettingsSummary($settings),
            'settings_ready' => $settingsReady,
            'can_continue_workspace' => $canContinueWorkspace,
            'can_edit' => $canEdit,
            'can_delete_photos' => $canEdit || $status === OzAbTestStatus::Running,
            'can_start' => $canStart,
            'can_stop' => $canStop,
            'is_terminal' => $status->isTerminal(),
            'campaign_created_by_tool' => $this->isCampaignCreatedByTool($experiment),
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
            $payload['last_api_error'] = null;
        } else {
            $payload['events'] = [];
            $payload['last_api_error'] = null;
        }

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
     * @return array{success: bool, items: list<array<string, mixed>>, messages: list<string>}
     */
    public function listCampaignsForExperiment(OzCabinet $cabinet, AbExperiment $experiment): array
    {
        $tokenResult = $this->experimentEngine->accessTokenResult($cabinet);
        $token = $tokenResult['token'];
        if ($token === null) {
            return [
                'success' => false,
                'items' => [],
                'messages' => [$tokenResult['error'] ?? OzAbExperimentEngine::MSG_MISSING_PERFORMANCE_CREDENTIALS],
            ];
        }

        $product = $this->requireExperimentProduct($experiment);
        $sku = (int) ($experiment->sku ?: $product->sku);
        $selectedId = $experiment->oz_campaign_id ? (int) $experiment->oz_campaign_id : null;
        $busyIds = $this->runningCampaignIdsForCabinet((int) $cabinet->id);

        $campaigns = [];
        $page = 1;
        while ($page <= 50) {
            $response = $this->performanceApi->listCampaigns($token, [
                'page' => $page,
                'pageSize' => 100,
                'advObjectType' => 'SKU',
            ]);
            if (! ($response['success'] ?? false)) {
                return [
                    'success' => false,
                    'items' => [],
                    'messages' => [$this->experimentEngine->apiMessage($response, 'Не удалось получить список кампаний')],
                ];
            }
            $list = Arr::get($response, 'data.list', []);
            if (! is_array($list) || $list === []) {
                break;
            }
            if (Arr::isAssoc($list) && isset($list['id'])) {
                $list = [$list];
            }
            foreach ($list as $item) {
                if (is_array($item)) {
                    $campaigns[] = $item;
                }
            }
            if (count($list) < 100) {
                break;
            }
            $page++;
        }

        $registry = AbCampaign::query()
            ->where('cabinet_id', $cabinet->id)
            ->get()
            ->keyBy(fn (AbCampaign $row) => (int) $row->oz_campaign_id);

        $items = [];
        foreach ($campaigns as $campaign) {
            $id = (int) ($campaign['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $isSelected = $selectedId !== null && $selectedId === $id;
            if (! $isSelected && ! $this->isCampaignUsable($campaign)) {
                continue;
            }

            $state = (string) ($campaign['state'] ?? '');
            $skus = $this->experimentEngine->extractCampaignSkus($token, $id, $campaign, fetchObjects: false);
            $contains = $sku > 0 && in_array($sku, $skus, true);
            $busy = in_array($id, $busyIds, true);
            $auto = strtoupper((string) ($campaign['productCampaignMode'] ?? '')) === 'PRODUCT_CAMPAIGN_MODE_AUTO';
            $canSelect = $isSelected
                || (! $busy && ! $auto && (
                    $contains
                    || $state === 'CAMPAIGN_STATE_INACTIVE'
                    || $state === 'CAMPAIGN_STATE_RUNNING'
                ));

            $items[] = [
                'id' => $id,
                'name' => (string) ($campaign['title'] ?? ('Кампания '.$id)),
                'status' => $state,
                'status_label' => $this->campaignStateLabel($state),
                'status_variant' => $this->campaignStateVariant($state),
                'payment_type' => strtolower((string) ($campaign['paymentType'] ?? 'CPC')),
                'bid_type' => 'manual',
                'nm_ids' => $skus,
                'nm_count' => count($skus),
                'contains_product' => $contains,
                'can_select' => $canSelect && ! $busy,
                'can_pause' => $state === 'CAMPAIGN_STATE_RUNNING' && ! $busy,
                'can_delete' => (int) ($registry->get($id)?->created_by_experiment_id ?? 0) > 0 && ! $busy,
                'is_selected' => $isSelected,
                'is_busy_by_ab' => $busy,
                'created_by_tool' => (int) ($registry->get($id)?->created_by_experiment_id ?? 0) > 0,
            ];
        }

        usort($items, static function (array $a, array $b): int {
            if (($a['is_selected'] ?? false) !== ($b['is_selected'] ?? false)) {
                return ($a['is_selected'] ?? false) ? -1 : 1;
            }
            if (($a['can_select'] ?? false) !== ($b['can_select'] ?? false)) {
                return ($a['can_select'] ?? false) ? -1 : 1;
            }
            if (($a['contains_product'] ?? false) !== ($b['contains_product'] ?? false)) {
                return ($a['contains_product'] ?? false) ? -1 : 1;
            }

            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return ['success' => true, 'items' => $items, 'messages' => []];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{success: bool, experiment?: array<string, mixed>, campaign?: array<string, mixed>, messages: list<string>}
     */
    public function createAndBindCampaign(OzCabinet $cabinet, AbExperiment $experiment, array $input): array
    {
        $this->assertEditableExperiment($experiment);
        $product = $this->requireExperimentProduct($experiment);
        $token = $this->requireToken($cabinet);
        $sku = (int) ($product->sku ?? 0);
        if ($sku <= 0) {
            throw ValidationException::withMessages(['campaign' => 'У товара нет SKU для рекламы.']);
        }

        $title = trim((string) ($input['name'] ?? ''));
        if ($title === '') {
            $title = $this->defaultCampaignName($product);
        }

        $bid = (int) ($input['cpm'] ?? $experiment->cpm ?? self::DEFAULT_CPC);
        $payload = [
            'title' => $title,
            'fromDate' => now('Europe/Moscow')->toDateString(),
            'productCampaignMode' => 'PRODUCT_CAMPAIGN_MODE_MANUAL',
            'products' => [
                ['sku' => (string) $sku, 'bid' => (string) max(1, $bid)],
            ],
        ];

        $created = $this->performanceApi->createCpcProductCampaign($token, $payload);
        if (! ($created['success'] ?? false)) {
            return [
                'success' => false,
                'messages' => [$this->experimentEngine->apiMessage($created, 'Не удалось создать рекламную кампанию')],
            ];
        }

        $campaignId = (int) Arr::get($created, 'data.campaignId', Arr::get($created, 'data.id', 0));
        if ($campaignId <= 0) {
            return ['success' => false, 'messages' => ['Ozon не вернул идентификатор кампании']];
        }

        AbCampaign::query()->updateOrCreate(
            ['cabinet_id' => $cabinet->id, 'oz_campaign_id' => $campaignId],
            [
                'name' => $title,
                'state' => 'CAMPAIGN_STATE_INACTIVE',
                'payment_type' => 'CPC',
                'created_by_experiment_id' => $experiment->id,
            ],
        );

        $this->bindCampaignRecord($experiment, $campaignId, $title, $sku);
        $this->refreshSetupProgress($experiment);
        $experiment->save();

        return [
            'success' => true,
            'experiment' => $this->mapExperiment($experiment->fresh($this->experimentDetailRelations())),
            'campaign' => ['id' => $campaignId, 'name' => $title],
            'messages' => ['Кампания создана и привязана к эксперименту'],
        ];
    }

    /**
     * @return array{success: bool, experiment?: array<string, mixed>, campaign?: array<string, mixed>, messages: list<string>}
     */
    public function prepareCampaignForProduct(OzCabinet $cabinet, AbExperiment $experiment, int $campaignId): array
    {
        $this->assertEditableExperiment($experiment);
        $product = $this->requireExperimentProduct($experiment);
        $token = $this->requireToken($cabinet);
        $sku = (int) ($product->sku ?? 0);
        if ($sku <= 0) {
            throw ValidationException::withMessages(['campaign' => 'У товара нет SKU для рекламы.']);
        }

        if (in_array($campaignId, $this->runningCampaignIdsForCabinet((int) $cabinet->id, (int) $experiment->id), true)) {
            throw ValidationException::withMessages([
                'campaign' => 'Эта кампания уже используется в другом запущенном эксперименте.',
            ]);
        }

        $campaign = $this->experimentEngine->findCampaign($token, $campaignId);
        if ($campaign === null) {
            return ['success' => false, 'messages' => ['Кампания не найдена']];
        }
        if (! $this->isCampaignUsable($campaign)) {
            return ['success' => false, 'messages' => ['Эту кампанию нельзя использовать для A/B-теста']];
        }

        $skus = $this->experimentEngine->extractCampaignSkus($token, $campaignId, $campaign);
        $contains = in_array($sku, $skus, true);
        $state = (string) ($campaign['state'] ?? '');

        if (! $contains) {
            $bid = (int) ($experiment->cpm ?: self::DEFAULT_CPC);
            $added = $this->performanceApi->addCampaignProducts($token, $campaignId, [
                'bids' => [
                    ['sku' => (string) $sku, 'bid' => (string) max(1, $bid)],
                ],
            ]);
            if (! ($added['success'] ?? false)) {
                return [
                    'success' => false,
                    'messages' => [$this->experimentEngine->apiMessage($added, 'Не удалось добавить товар в кампанию')],
                ];
            }
        }

        $name = (string) ($campaign['title'] ?? ('Кампания '.$campaignId));
        AbCampaign::query()->updateOrCreate(
            ['cabinet_id' => $cabinet->id, 'oz_campaign_id' => $campaignId],
            [
                'name' => $name,
                'state' => $state,
                'payment_type' => (string) ($campaign['paymentType'] ?? 'CPC'),
            ],
        );

        $this->bindCampaignRecord($experiment, $campaignId, $name, $sku);
        $this->refreshSetupProgress($experiment);
        $experiment->save();

        $message = $contains
            ? 'Кампания выбрана — товар уже в ней'
            : 'Товар добавлен в кампанию';

        return [
            'success' => true,
            'experiment' => $this->mapExperiment($experiment->fresh($this->experimentDetailRelations())),
            'campaign' => ['id' => $campaignId, 'name' => $name],
            'messages' => [$message],
        ];
    }

    /**
     * @return array{success: bool, messages: list<string>}
     */
    public function pauseCampaign(OzCabinet $cabinet, int $campaignId): array
    {
        if (in_array($campaignId, $this->runningCampaignIdsForCabinet((int) $cabinet->id), true)) {
            return ['success' => false, 'messages' => ['Нельзя остановить кампанию, пока идёт A/B-тест']];
        }
        $token = $this->requireToken($cabinet);
        $result = $this->performanceApi->deactivateCampaign($token, $campaignId);
        if (! ($result['success'] ?? false)) {
            return ['success' => false, 'messages' => [$this->experimentEngine->apiMessage($result, 'Не удалось остановить кампанию')]];
        }

        return ['success' => true, 'messages' => ['Кампания остановлена']];
    }

    /**
     * @return array{success: bool, messages: list<string>}
     */
    public function deleteCampaign(OzCabinet $cabinet, int $campaignId): array
    {
        $registry = AbCampaign::query()
            ->where('cabinet_id', $cabinet->id)
            ->where('oz_campaign_id', $campaignId)
            ->first();
        if (! $registry || ! $registry->created_by_experiment_id) {
            return ['success' => false, 'messages' => ['Удалить можно только кампанию, созданную этим инструментом']];
        }
        if (in_array($campaignId, $this->runningCampaignIdsForCabinet((int) $cabinet->id), true)) {
            return ['success' => false, 'messages' => ['Нельзя удалить кампанию, пока идёт A/B-тест']];
        }

        $token = $this->requireToken($cabinet);
        $this->performanceApi->deactivateCampaign($token, $campaignId);

        AbExperiment::query()
            ->where('cabinet_id', $cabinet->id)
            ->where('oz_campaign_id', $campaignId)
            ->whereIn('status', [OzAbTestStatus::Draft->value, OzAbTestStatus::Stopped->value, OzAbTestStatus::Error->value])
            ->update([
                'oz_campaign_id' => null,
                'oz_campaign_name' => null,
                'campaign_bound_at' => null,
            ]);

        $registry->delete();

        return ['success' => true, 'messages' => ['Кампания удалена из инструмента']];
    }

    public function defaultCampaignName(AbProduct $product): string
    {
        $base = trim((string) ($product->title ?: $product->offer_id ?: 'Товар'));

        return mb_substr('A/B '.$base, 0, 100);
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return array{success: bool, photos?: list<array<string, mixed>>, experiment?: array<string, mixed>, messages: list<string>}
     */
    public function storePhotos(OzCabinet $cabinet, AbExperiment $experiment, array $files): array
    {
        $this->assertEditableExperiment($experiment);
        $existing = (int) $experiment->photos()->count();
        if ($existing + count($files) > self::MAX_PHOTOS) {
            return ['success' => false, 'messages' => ['Можно загрузить не больше '.self::MAX_PHOTOS.' фотографий']];
        }

        foreach ($files as $index => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }
            $this->assertPhotoFile($file);
            $ext = strtolower((string) $file->getClientOriginalExtension()) ?: 'jpg';
            $path = sprintf(
                'oz/ab-testing/%d/%d/%s.%s',
                $cabinet->id,
                $experiment->id,
                (string) Str::uuid(),
                $ext,
            );
            Storage::disk(self::PHOTO_DISK)->put($path, file_get_contents($file->getRealPath()) ?: '');
            AbExperimentPhoto::query()->create([
                'ab_experiment_id' => $experiment->id,
                'cabinet_id' => $cabinet->id,
                'sort_order' => $existing + $index,
                'disk' => self::PHOTO_DISK,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
            ]);
        }

        $this->refreshSetupProgress($experiment);
        $experiment->save();

        return [
            'success' => true,
            'photos' => $this->listPhotos($experiment->fresh('photos')),
            'experiment' => $this->mapExperiment($experiment->fresh($this->experimentDetailRelations())),
            'messages' => ['Фотографии загружены'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPhotos(AbExperiment $experiment): array
    {
        $experiment->loadMissing('photos');
        $status = $this->experimentEngine->resolveStatus($experiment) ?? OzAbTestStatus::Draft;
        $agg = $this->experimentEngine->photoAggregates($experiment);
        [$winnerId, $winnerCtr] = $this->resolveWinnerComparison($experiment, $status, $agg);

        return $experiment->photos
            ->map(fn (AbExperimentPhoto $photo) => $this->mapPhotoWithStats($photo, $status, $agg, $winnerId, $winnerCtr))
            ->values()
            ->all();
    }

    /**
     * @return array{success: bool, photos?: list<array<string, mixed>>, experiment?: array<string, mixed>, messages: list<string>}
     */
    public function replacePhoto(OzCabinet $cabinet, AbExperiment $experiment, AbExperimentPhoto $photo, UploadedFile $file): array
    {
        $this->assertEditableExperiment($experiment);
        $this->assertPhotoFile($file);
        if ((int) $photo->ab_experiment_id !== (int) $experiment->id) {
            return ['success' => false, 'messages' => ['Фотография не принадлежит эксперименту']];
        }

        Storage::disk((string) $photo->disk)->delete((string) $photo->path);
        $ext = strtolower((string) $file->getClientOriginalExtension()) ?: 'jpg';
        $path = sprintf(
            'oz/ab-testing/%d/%d/%s.%s',
            $cabinet->id,
            $experiment->id,
            (string) Str::uuid(),
            $ext,
        );
        Storage::disk(self::PHOTO_DISK)->put($path, file_get_contents($file->getRealPath()) ?: '');
        $photo->disk = self::PHOTO_DISK;
        $photo->path = $path;
        $photo->original_name = $file->getClientOriginalName();
        $photo->mime = $file->getMimeType();
        $photo->size = $file->getSize();
        $photo->save();

        return [
            'success' => true,
            'photos' => $this->listPhotos($experiment->fresh('photos')),
            'experiment' => $this->mapExperiment($experiment->fresh($this->experimentDetailRelations())),
            'messages' => ['Фотография заменена'],
        ];
    }

    /**
     * @return array{success: bool, messages: list<string>}
     */
    public function destroyPhoto(OzCabinet $cabinet, AbExperiment $experiment, AbExperimentPhoto $photo): array
    {
        $status = $this->experimentEngine->resolveStatus($experiment);
        if ($status === OzAbTestStatus::Completed) {
            return ['success' => false, 'messages' => ['Нельзя удалить фотографию завершённого эксперимента']];
        }
        if ((int) $photo->ab_experiment_id !== (int) $experiment->id) {
            return ['success' => false, 'messages' => ['Фотография не принадлежит эксперименту']];
        }

        $count = (int) $experiment->photos()->count();
        if ($status === OzAbTestStatus::Running && $count <= 1) {
            return ['success' => false, 'messages' => ['Нельзя удалить последнюю фотографию во время теста']];
        }

        Storage::disk((string) ($photo->disk ?: self::PHOTO_DISK))->delete((string) $photo->path);
        $photo->delete();

        $remaining = $experiment->photos()->orderBy('sort_order')->orderBy('id')->get();
        foreach ($remaining as $index => $item) {
            if ((int) $item->sort_order !== $index) {
                $item->sort_order = $index;
                $item->save();
            }
        }

        $this->refreshSetupProgress($experiment);
        $experiment->save();

        return ['success' => true, 'messages' => ['Фотография удалена']];
    }

    /**
     * @param  list<int>  $orderedIds
     * @return array{success: bool, photos?: list<array<string, mixed>>, experiment?: array<string, mixed>, messages: list<string>}
     */
    public function reorderPhotos(OzCabinet $cabinet, AbExperiment $experiment, array $orderedIds): array
    {
        $this->assertEditableExperiment($experiment);
        $photos = $experiment->photos()->get();
        $existingIds = $photos->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $incomingSorted = collect($orderedIds)->map(fn ($id) => (int) $id)->sort()->values()->all();
        if ($existingIds !== $incomingSorted) {
            return ['success' => false, 'messages' => ['Некорректный порядок фотографий']];
        }

        $byId = $photos->keyBy(fn (AbExperimentPhoto $photo) => (int) $photo->id);
        foreach (array_values($orderedIds) as $index => $id) {
            $item = $byId->get((int) $id);
            if ($item && (int) $item->sort_order !== $index) {
                $item->sort_order = $index;
                $item->save();
            }
        }

        return [
            'success' => true,
            'photos' => $this->listPhotos($experiment->fresh('photos')),
            'experiment' => $this->mapExperiment($experiment->fresh($this->experimentDetailRelations())),
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
     * @return array{impressions_per_photo:int,impressions_per_round:int,round_minutes:int,cpm:int}
     */
    private function resolveExperimentSettings(AbExperiment $experiment): array
    {
        return [
            'impressions_per_photo' => (int) ($experiment->impressions_per_photo ?: self::DEFAULT_IMPRESSIONS_PER_PHOTO),
            'impressions_per_round' => (int) ($experiment->impressions_per_round ?: self::DEFAULT_IMPRESSIONS_PER_ROUND),
            'round_minutes' => (int) ($experiment->round_minutes ?: self::DEFAULT_ROUND_MINUTES),
            'cpm' => (int) ($experiment->cpm ?: self::DEFAULT_CPC),
        ];
    }

    /**
     * @param  array{impressions_per_photo:int,impressions_per_round:int,round_minutes:int,cpm:int}  $settings
     */
    private function formatSettingsSummary(array $settings): string
    {
        $fmt = static fn (int $n): string => number_format($n, 0, ',', ' ');

        return $fmt($settings['impressions_per_photo']).' на фото • '
            .$fmt($settings['impressions_per_round']).' за круг • '
            .$fmt($settings['round_minutes']).' мин • CPC '
            .$fmt($settings['cpm']).' ₽';
    }

    /**
     * @return array{progress:int,mode:string,label:string,impressions_progress:array<string,mixed>}
     */
    private function resolveProgressMeta(
        AbExperiment $experiment,
        OzAbTestStatus $status,
        int $target,
        ?AbExperimentCycle $openCycle,
    ): array {
        if ($status === OzAbTestStatus::Completed) {
            return [
                'progress' => 100,
                'mode' => 'completed',
                'label' => 'Завершён',
                'impressions_progress' => $this->experimentEngine->impressionsProgressBreakdown($experiment, $target),
            ];
        }

        if ($status === OzAbTestStatus::Running) {
            $breakdown = $this->experimentEngine->impressionsProgressBreakdown($experiment, $target, $openCycle);
            $label = ($breakdown['mode'] ?? '') === 'pending'
                ? 'Ожидание показов'
                : 'Сбор статистики';

            return [
                'progress' => (int) ($breakdown['progress'] ?? 0),
                'mode' => (string) ($breakdown['mode'] ?? 'views'),
                'label' => $label,
                'impressions_progress' => $breakdown,
            ];
        }

        $progress = 0;
        if ($experiment->oz_campaign_id) {
            $progress = self::PROGRESS_AFTER_CAMPAIGN;
        }
        if ((int) $experiment->photos()->count() >= self::MIN_PHOTOS_TO_CONTINUE) {
            $progress = max($progress, self::PROGRESS_AFTER_PHOTOS);
        }
        if ($this->experimentEngine->areSettingsReady($experiment)) {
            $progress = max($progress, self::PROGRESS_AFTER_SETTINGS);
        }

        return [
            'progress' => $progress,
            'mode' => 'setup',
            'label' => 'Подготовка',
            'impressions_progress' => [],
        ];
    }

    /**
     * @param  array<int, array{views:int,clicks:int,ctr:float|null}>  $agg
     * @return array{0: ?int, 1: ?float}
     */
    private function resolveWinnerComparison(AbExperiment $experiment, OzAbTestStatus $status, array $agg): array
    {
        if ($status !== OzAbTestStatus::Completed) {
            return [null, null];
        }
        $winnerId = $experiment->winner_photo_id ? (int) $experiment->winner_photo_id : $this->experimentEngine->resolveWinnerPhotoId($experiment);
        $winnerCtr = $winnerId !== null ? ($agg[$winnerId]['ctr'] ?? null) : null;

        return [$winnerId, is_numeric($winnerCtr) ? (float) $winnerCtr : null];
    }

    /**
     * @param  array<int, array{views:int,clicks:int,ctr:float|null}>  $agg
     * @return array<string, mixed>
     */
    private function mapPhotoWithStats(
        AbExperimentPhoto $photo,
        OzAbTestStatus $status,
        array $agg,
        ?int $winnerId,
        ?float $winnerCtr,
    ): array {
        $row = $agg[(int) $photo->id] ?? ['views' => 0, 'clicks' => 0, 'ctr' => null];
        $efficiency = null;
        if ($status === OzAbTestStatus::Completed && $winnerCtr && $winnerCtr > 0 && $row['ctr'] !== null) {
            $efficiency = round((((float) $row['ctr'] / $winnerCtr) - 1) * 100, 1);
        }

        return [
            'id' => $photo->id,
            'sort_order' => (int) $photo->sort_order,
            'original_name' => $photo->original_name,
            'mime' => $photo->mime,
            'size' => $photo->size,
            'url' => $this->experimentEngine->publicPhotoUrl($photo),
            'views' => (int) $row['views'],
            'clicks' => (int) $row['clicks'],
            'ctr' => $row['ctr'],
            'efficiency_pct' => $efficiency,
            'is_winner' => $winnerId !== null && (int) $photo->id === $winnerId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapEvent(AbExperimentEvent $event): array
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
     * @return list<array<string, mixed>>
     */
    private function mapActionHistory(AbExperiment $experiment): array
    {
        $cycles = $experiment->cycles->sortByDesc('sequence')->take(100)->values();
        $photos = $experiment->relationLoaded('photos')
            ? $experiment->photos->keyBy('id')
            : $experiment->photos()->get()->keyBy('id');

        return $cycles->map(function (AbExperimentCycle $cycle) use ($photos) {
            $photo = $photos->get($cycle->ab_experiment_photo_id);
            $views = $cycle->deltaViews();
            $clicks = $cycle->deltaClicks();

            return [
                'id' => $cycle->id,
                'sequence' => (int) $cycle->sequence,
                'started_at' => optional($cycle->started_at)?->toIso8601String(),
                'ended_at' => optional($cycle->ended_at)?->toIso8601String(),
                'end_reason' => $cycle->end_reason,
                'photo_id' => (int) $cycle->ab_experiment_photo_id,
                'photo_url' => $photo ? $this->experimentEngine->publicPhotoUrl($photo) : null,
                'sort_order' => $photo ? (int) $photo->sort_order : null,
                'views' => $views,
                'clicks' => $clicks,
                'ctr' => $views > 0 ? round(($clicks / $views) * 100, 4) : null,
                'in_progress' => $cycle->ended_at === null,
            ];
        })->values()->all();
    }

    /**
     * @return list<array{key: string, ok: bool, label: string}>
     */
    private function buildStartChecksSummary(AbExperiment $experiment, bool $settingsReady, int $photosCount): array
    {
        $status = $experiment->status instanceof OzAbTestStatus
            ? $experiment->status
            : OzAbTestStatus::tryFrom((string) $experiment->status) ?? OzAbTestStatus::Draft;

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
                'ok' => (bool) $experiment->oz_campaign_id,
                'label' => 'Рекламная кампания выбрана',
            ],
        ];
    }

    private function isCampaignCreatedByTool(AbExperiment $experiment): bool
    {
        if (! $experiment->oz_campaign_id) {
            return false;
        }

        return AbCampaign::query()
            ->where('cabinet_id', $experiment->cabinet_id)
            ->where('oz_campaign_id', $experiment->oz_campaign_id)
            ->whereNotNull('created_by_experiment_id')
            ->where('created_by_experiment_id', '>', 0)
            ->exists();
    }

    private function refreshSetupProgress(AbExperiment $experiment): void
    {
        $status = $this->experimentEngine->resolveStatus($experiment);
        if ($status === OzAbTestStatus::Running || $status === OzAbTestStatus::Completed) {
            return;
        }
        $progress = 0;
        if ($experiment->oz_campaign_id) {
            $progress = self::PROGRESS_AFTER_CAMPAIGN;
        }
        if ((int) $experiment->photos()->count() >= self::MIN_PHOTOS_TO_CONTINUE) {
            $progress = max($progress, self::PROGRESS_AFTER_PHOTOS);
        }
        if ($this->experimentEngine->areSettingsReady($experiment)) {
            $progress = max($progress, self::PROGRESS_AFTER_SETTINGS);
        }
        $experiment->progress = $progress;
    }

    private function bindCampaignRecord(AbExperiment $experiment, int $campaignId, string $name, int $sku): void
    {
        $experiment->oz_campaign_id = $campaignId;
        $experiment->oz_campaign_name = $name;
        $experiment->campaign_bound_at = now();
        $experiment->sku = $sku;
    }

    /**
     * @return list<int>
     */
    private function runningCampaignIdsForCabinet(int $cabinetId, ?int $exceptExperimentId = null): array
    {
        $query = AbExperiment::query()
            ->where('cabinet_id', $cabinetId)
            ->where('status', OzAbTestStatus::Running->value)
            ->whereNotNull('oz_campaign_id');
        if ($exceptExperimentId) {
            $query->where('id', '!=', $exceptExperimentId);
        }

        return $query->pluck('oz_campaign_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  array<string, mixed>  $campaign
     */
    private function isCampaignUsable(array $campaign): bool
    {
        $type = strtoupper((string) ($campaign['advObjectType'] ?? 'SKU'));
        if ($type !== 'SKU') {
            return false;
        }
        $state = (string) ($campaign['state'] ?? '');
        if (! in_array($state, self::USABLE_CAMPAIGN_STATES, true)) {
            return false;
        }
        $mode = strtoupper((string) ($campaign['productCampaignMode'] ?? ''));
        if ($mode === 'PRODUCT_CAMPAIGN_MODE_AUTO') {
            return false;
        }

        return true;
    }

    private function campaignStateLabel(string $state): string
    {
        return match ($state) {
            'CAMPAIGN_STATE_RUNNING' => 'Активна',
            'CAMPAIGN_STATE_INACTIVE' => 'Остановлена',
            'CAMPAIGN_STATE_STOPPED' => 'Остановлена',
            'CAMPAIGN_STATE_PLANNED' => 'Запланирована',
            'CAMPAIGN_STATE_ARCHIVED' => 'В архиве',
            'CAMPAIGN_STATE_FINISHED' => 'Завершена',
            default => '—',
        };
    }

    private function campaignStateVariant(string $state): string
    {
        return match ($state) {
            'CAMPAIGN_STATE_RUNNING' => 'success',
            'CAMPAIGN_STATE_INACTIVE' => 'warning',
            'CAMPAIGN_STATE_STOPPED' => 'destructive',
            default => 'outline',
        };
    }

    private function requireExperimentProduct(AbExperiment $experiment): AbProduct
    {
        $product = $experiment->relationLoaded('product')
            ? $experiment->product
            : AbProduct::query()->find($experiment->ab_product_id);
        if (! $product) {
            throw ValidationException::withMessages(['product' => 'Товар эксперимента не найден.']);
        }

        return $product;
    }

    private function requireToken(OzCabinet $cabinet): string
    {
        $tokenResult = $this->experimentEngine->accessTokenResult($cabinet);
        if ($tokenResult['token'] === null) {
            throw ValidationException::withMessages([
                'campaign' => $tokenResult['error'] ?? OzAbExperimentEngine::MSG_MISSING_PERFORMANCE_CREDENTIALS,
            ]);
        }

        return $tokenResult['token'];
    }

    private function assertEditableExperiment(AbExperiment $experiment): void
    {
        $status = $this->experimentEngine->resolveStatus($experiment);
        if ($status === null || ! $status->isEditable()) {
            throw ValidationException::withMessages([
                'experiment' => 'Изменить можно черновик, остановленный или ошибочный эксперимент.',
            ]);
        }
    }

    private function assertPhotoFile(UploadedFile $file): void
    {
        if ($file->getSize() > self::PHOTO_MAX_BYTES) {
            throw ValidationException::withMessages(['photos' => 'Размер файла не должен превышать 10 МБ.']);
        }
        $mime = (string) $file->getMimeType();
        if (! in_array($mime, self::PHOTO_ALLOWED_MIMES, true)) {
            throw ValidationException::withMessages(['photos' => 'Допустимы только JPEG и PNG.']);
        }
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function upsertProductFromInfo(int $cabinetId, array $detail): void
    {
        $ozProductId = (int) ($detail['id'] ?? $detail['product_id'] ?? 0);
        if ($ozProductId <= 0) {
            return;
        }

        $sku = $this->extractProductSku($detail);
        $modelId = (int) Arr::get($detail, 'model_info.model_id', $detail['model_id'] ?? 0);

        $photo = $this->firstString($detail['primary_image'] ?? null);
        if ($photo === '') {
            $photo = $this->firstString($detail['images'] ?? null);
        }

        $title = $this->firstString($detail['name'] ?? null);
        $brand = $this->firstString($detail['brand'] ?? null);
        $offerId = $this->firstString($detail['offer_id'] ?? null);

        AbProduct::query()->updateOrCreate(
            ['cabinet_id' => $cabinetId, 'oz_product_id' => $ozProductId],
            [
                'offer_id' => $offerId,
                'sku' => $sku > 0 ? $sku : null,
                'model_id' => $modelId > 0 ? $modelId : null,
                'title' => $title !== '' ? $title : null,
                'brand' => $brand !== '' ? $brand : null,
                'photo_url' => $photo !== '' ? $photo : null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function extractProductSku(array $detail): int
    {
        foreach ((array) ($detail['sources'] ?? []) as $source) {
            if (! is_array($source)) {
                continue;
            }
            $sku = (int) ($source['sku'] ?? 0);
            if ($sku > 0) {
                return $sku;
            }
        }

        foreach (['sku', 'fbs_sku', 'fbo_sku'] as $key) {
            $value = $detail[$key] ?? 0;
            if (is_array($value)) {
                $value = $value[0] ?? 0;
            }
            $sku = is_numeric($value) ? (int) $value : 0;
            if ($sku > 0) {
                return $sku;
            }
        }

        return 0;
    }

    /**
     * @return array{items: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function emptyProductList(int $page, int $perPage): array
    {
        return [
            'items' => [],
            'meta' => [
                'current_page' => $page,
                'last_page' => 1,
                'per_page' => $perPage,
                'total' => 0,
            ],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AbProduct>  $items
     * @return array<string, mixed>
     */
    private function mapProductGroup(string $groupKey, $items): array
    {
        $skus = $items
            ->sortBy(fn (AbProduct $product) => sprintf('%020d|%s', (int) ($product->sku ?? 0), (string) $product->offer_id))
            ->values();

        $mappedSkus = $skus->map(fn (AbProduct $product) => $this->mapProductRow($product))->all();
        $first = $skus->first(fn (AbProduct $product) => filled($product->photo_url)) ?? $skus->first();
        $status = $this->groupTestStatus($mappedSkus);

        return [
            'group_key' => $groupKey,
            'title' => $first?->title ?: 'Без названия',
            'photo_url' => $first?->photo_url,
            'sku_count' => $skus->count(),
            'test_status' => $status->value,
            'test_status_label' => $status->label(),
            'skus' => $mappedSkus,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $skus
     */
    private function groupTestStatus(array $skus): OzAbTestStatus
    {
        $priority = [
            OzAbTestStatus::Running->value => 0,
            OzAbTestStatus::Draft->value => 1,
            OzAbTestStatus::Stopped->value => 2,
            OzAbTestStatus::Error->value => 3,
            OzAbTestStatus::Completed->value => 4,
            OzAbTestStatus::NotCreated->value => 5,
        ];

        $best = OzAbTestStatus::NotCreated;
        $bestRank = 5;
        foreach ($skus as $sku) {
            $status = OzAbTestStatus::tryFrom((string) ($sku['test_status'] ?? '')) ?? OzAbTestStatus::NotCreated;
            $rank = $priority[$status->value] ?? 5;
            if ($rank < $bestRank) {
                $best = $status;
                $bestRank = $rank;
            }
        }

        return $best;
    }

    private function productGroupKey(AbProduct $product): string
    {
        $modelId = (int) ($product->model_id ?? 0);
        if ($modelId > 0) {
            return 'm:'.$modelId;
        }

        $title = mb_strtolower(trim((string) $product->title));
        if ($title !== '') {
            return 't:'.$title;
        }

        return 'p:'.(int) $product->oz_product_id;
    }

    /**
     * Seller API часто отдаёт картинки и часть полей массивом, а не строкой.
     */
    private function firstString(mixed $value): string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return trim((string) $value);
        }
        if (! is_array($value)) {
            return '';
        }

        foreach ($value as $item) {
            $nested = $this->firstString(
                is_array($item) ? ($item['file_name'] ?? $item['url'] ?? $item['name'] ?? $item) : $item
            );
            if ($nested !== '') {
                return $nested;
            }
        }

        return '';
    }
}
