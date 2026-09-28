<?php

namespace App\Services\Subscriber\Oz\AbTesting;

use App\Enums\OzAbTestStatus;
use App\Jobs\Oz\AbTesting\ProcessOzAbCabinetTickJob;
use App\Models\Subscribers\Oz\AbTesting\AbExperiment;
use App\Models\Subscribers\Oz\AbTesting\AbExperimentCycle;
use App\Models\Subscribers\Oz\AbTesting\AbExperimentEvent;
use App\Models\Subscribers\Oz\AbTesting\AbExperimentPhoto;
use App\Models\Subscribers\Oz\AbTesting\AbProduct;
use App\Models\Subscribers\Oz\OzCabinet;
use App\Services\Ozon\OzonApiService;
use App\Services\Ozon\OzonPerformanceApiService;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Жизненный цикл эксперимента Ozon: старт, тик, смена фото, завершение, стоп.
 */
class OzAbExperimentEngine
{
    public const MAX_CONSECUTIVE_FAILURES = 5;

    public const PHOTO_DISK = 'public';

    public const STARTABLE_CAMPAIGN_STATES = [
        'CAMPAIGN_STATE_RUNNING',
        'CAMPAIGN_STATE_INACTIVE',
    ];

    /** Performance API: не больше 10 кампаний в одном запросе статистики. */
    public const STATS_CAMPAIGN_CHUNK = 10;

    /** Первая пауза после /v2/product/pictures/import, пока Ozon обрабатывает файлы. */
    private const PICTURES_WAIT_INITIAL_SECONDS = 8;

    /** Интервал повторного pictures/info. */
    private const PICTURES_POLL_INTERVAL_SECONDS = 2;

    /** Максимум ожидания подтверждения загрузки фото. */
    private const PICTURES_WAIT_MAX_SECONDS = 32;

    public const MSG_MISSING_PERFORMANCE_CREDENTIALS = 'Укажите ключи рекламы Performance API в кабинете Ozon.';

    public const MSG_INVALID_PERFORMANCE_CREDENTIALS = 'Неверные данные для подключения';

    public const MSG_PERFORMANCE_TOKEN_FAILED = 'Не удалось подключиться к рекламе Ozon. Проверьте данные для подключения.';

    public const MSG_PERFORMANCE_RATE_LIMITED = 'Ozon сейчас не принимает запросы. Подождите несколько секунд и обновите список.';

    private ?string $cachedAccessToken = null;

    private ?int $cachedAccessTokenCabinetId = null;

    public function __construct(
        private readonly OzonPerformanceApiService $performanceApi,
        private readonly OzonApiService $sellerApi,
        private readonly OzAbExperimentJournal $journal,
    ) {}

    /**
     * @return list<string>
     */
    public function validateReadyForStart(OzCabinet $cabinet, AbExperiment $experiment): array
    {
        $errors = [];

        $status = $this->resolveStatus($experiment);
        if ($status === null || ! $status->isStartable()) {
            $errors[] = 'Запустить можно эксперимент в статусе «Черновик», «Остановлен» или «Ошибка».';
        }

        if (trim((string) $cabinet->apikey) === '' || trim((string) $cabinet->client_id) === '') {
            $errors[] = 'У кабинета не указаны ключи Seller API.';
        }

        if (! $this->hasPerformanceCredentials($cabinet)) {
            $errors[] = self::MSG_MISSING_PERFORMANCE_CREDENTIALS;
        }

        if (! $this->areSettingsReady($experiment)) {
            $errors[] = 'Сохраните настройки эксперимента перед запуском.';
        }

        $photos = $experiment->relationLoaded('photos')
            ? $experiment->photos
            : $experiment->photos()->orderBy('sort_order')->orderBy('id')->get();

        if ($photos->count() < 2) {
            $errors[] = 'Загрузите минимум 2 фотографии для A/B-теста.';
        }

        if (! $experiment->oz_campaign_id) {
            $errors[] = 'Привяжите рекламную кампанию к эксперименту.';
        }

        $product = $experiment->relationLoaded('product')
            ? $experiment->product
            : AbProduct::query()->find($experiment->ab_product_id);

        if (! $product) {
            $errors[] = 'Товар эксперимента не найден.';
        } elseif ((int) ($experiment->sku ?: $product->sku) <= 0) {
            $errors[] = 'У товара нет SKU для рекламы.';
        }

        $running = AbExperiment::query()
            ->where('cabinet_id', $experiment->cabinet_id)
            ->where('ab_product_id', $experiment->ab_product_id)
            ->where('status', OzAbTestStatus::Running->value)
            ->where('id', '!=', $experiment->id)
            ->first();

        if ($running) {
            $errors[] = 'По этому товару уже запущен эксперимент «' . $running->name . '».';
        }

        $campaignId = (int) ($experiment->oz_campaign_id ?? 0);
        if ($campaignId > 0) {
            $busy = AbExperiment::query()
                ->where('cabinet_id', $experiment->cabinet_id)
                ->where('oz_campaign_id', $campaignId)
                ->where('status', OzAbTestStatus::Running->value)
                ->where('id', '!=', $experiment->id)
                ->first();

            if ($busy) {
                $errors[] = 'Эта кампания уже используется в запущенном эксперименте «' . $busy->name
                    . '». Дождитесь завершения или остановите его.';
            }
        }

        foreach ($photos as $photo) {
            $disk = (string) ($photo->disk ?: self::PHOTO_DISK);
            $path = (string) $photo->path;
            if ($path === '' || ! Storage::disk($disk)->exists($path)) {
                $errors[] = 'Файл фотографии #' . ((int) $photo->sort_order + 1) . ' недоступен на диске.';
            }
        }

        return $errors;
    }

    /**
     * @return array{success: bool, experiment?: AbExperiment, messages: list<string>}
     */
    public function start(OzCabinet $cabinet, AbExperiment $experiment): array
    {
        $experiment->loadMissing(['photos', 'product']);

        $errors = $this->validateReadyForStart($cabinet, $experiment);
        if ($errors !== []) {
            return ['success' => false, 'messages' => $errors];
        }

        $tokenResult = $this->accessTokenResult($cabinet);
        $token = $tokenResult['token'];
        if ($token === null) {
            return ['success' => false, 'messages' => [$tokenResult['error'] ?? self::MSG_PERFORMANCE_TOKEN_FAILED]];
        }

        $campaignId = (int) $experiment->oz_campaign_id;
        $product = $experiment->product;
        $sku = (int) ($experiment->sku ?: $product->sku);
        $ozProductId = (int) $product->oz_product_id;

        $campaign = $this->findCampaign($token, $campaignId);
        if ($campaign === null) {
            return ['success' => false, 'messages' => ['Рекламная кампания не найдена в кабинете Ozon.']];
        }

        $state = (string) ($campaign['state'] ?? '');
        if (! in_array($state, self::STARTABLE_CAMPAIGN_STATES, true)) {
            return [
                'success' => false,
                'messages' => ['Кампанию нельзя запустить в текущем статусе. Выберите активную или остановленную кампанию.'],
            ];
        }

        $skus = $this->extractCampaignSkus($token, $campaignId, $campaign);
        if (! in_array($sku, $skus, true)) {
            $attach = $this->ensureCampaignContainsSku(
                $token,
                $campaignId,
                $sku,
                $campaign,
            );
            if (! ($attach['success'] ?? false)) {
                return [
                    'success' => false,
                    'messages' => [$attach['message'] ?? 'Товар не входит в рекламную кампанию. Добавьте его перед запуском.'],
                ];
            }
        }

        $photos = $experiment->photos->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values();
        /** @var AbExperimentPhoto $firstPhoto */
        $firstPhoto = $photos->first();

        $campaignStarted = false;

        try {
            $snapshotGallery = $this->withPrimaryFingerprint(
                $this->captureGallerySnapshot($cabinet, $ozProductId),
            );
            $experiment->gallery_snapshot = $snapshotGallery;
            $experiment->sku = $sku;
            $experiment->save();

            if ($state === 'CAMPAIGN_STATE_RUNNING') {
                $this->journal->log(
                    $experiment,
                    OzAbExperimentJournal::TYPE_CAMPAIGN_ALREADY_ACTIVE,
                    'Рекламная кампания уже активна — запуск пропущен.',
                    ['campaign_id' => $campaignId, 'state' => $state],
                );
            } else {
                $activate = $this->performanceApi->activateCampaign($token, $campaignId);
                if (! ($activate['success'] ?? false)) {
                    return [
                        'success' => false,
                        'messages' => [$this->apiMessage($activate, 'Не удалось включить рекламную кампанию')],
                    ];
                }
                $campaignStarted = true;
                $this->journal->log(
                    $experiment,
                    OzAbExperimentJournal::TYPE_CAMPAIGN_STARTED,
                    'Рекламная кампания включена.',
                    ['campaign_id' => $campaignId],
                );
            }

            $upload = $this->uploadPhotoAsMain($cabinet, $experiment, $firstPhoto);
            if (! ($upload['success'] ?? false)) {
                if ($campaignStarted) {
                    $this->safeDeactivate($token, $campaignId);
                }

                return [
                    'success' => false,
                    'messages' => [$upload['message'] ?? 'Не удалось установить главную фотографию'],
                ];
            }

            $this->journal->log(
                $experiment,
                OzAbExperimentJournal::TYPE_PHOTO_SET,
                'Установлена фотография №1 как главная в карточке.',
                array_merge(
                    ['photo_id' => $firstPhoto->id, 'sort_order' => $firstPhoto->sort_order],
                    is_array($upload['meta'] ?? null) ? $upload['meta'] : [],
                ),
            );

            $experiment = DB::transaction(function () use ($experiment, $firstPhoto) {
                /** @var AbExperiment $locked */
                $locked = AbExperiment::query()->whereKey($experiment->id)->lockForUpdate()->firstOrFail();
                $lockedStatus = $this->resolveStatus($locked);
                if ($lockedStatus === null || ! $lockedStatus->isStartable()) {
                    throw ValidationException::withMessages([
                        'experiment' => 'Запустить можно только «Черновик», «Остановлен» или «Ошибка».',
                    ]);
                }

                AbExperimentCycle::query()
                    ->where('ab_experiment_id', $locked->id)
                    ->whereNull('ended_at')
                    ->update([
                        'ended_at' => now(),
                        'end_reason' => AbExperimentCycle::END_STOPPED,
                    ]);

                $nextSequence = (int) AbExperimentCycle::query()
                    ->where('ab_experiment_id', $locked->id)
                    ->max('sequence');
                $nextSequence = $nextSequence > 0 ? $nextSequence + 1 : 1;

                AbExperimentCycle::query()->create([
                    'ab_experiment_id' => $locked->id,
                    'cabinet_id' => $locked->cabinet_id,
                    'ab_experiment_photo_id' => $firstPhoto->id,
                    'sequence' => $nextSequence,
                    'started_at' => now(),
                    'views_start' => 0,
                    'clicks_start' => 0,
                    'spend_start' => 0,
                    'orders_start' => 0,
                ]);

                $isRestart = in_array($lockedStatus, [OzAbTestStatus::Stopped, OzAbTestStatus::Error], true);

                $locked->status = OzAbTestStatus::Running;
                $locked->started_at = now();
                $locked->finished_at = null;
                $locked->error_message = null;
                $locked->winner_photo_id = null;
                $locked->consecutive_failures = 0;
                $locked->last_processed_at = now();
                $locked->progress = 0;
                $locked->save();

                $locked->setAttribute('_is_restart', $isRestart);
                $locked->setAttribute('_cycle_sequence', $nextSequence);

                return $locked;
            });

            $openedCycle = $experiment->resolveOpenCycle();
            $cycleSeq = (int) ($experiment->getAttribute('_cycle_sequence') ?? $openedCycle?->sequence ?? 1);
            $isRestart = (bool) $experiment->getAttribute('_is_restart');

            $this->journal->log(
                $experiment,
                OzAbExperimentJournal::TYPE_CYCLE_OPENED,
                'Открыт цикл №' . $cycleSeq . ' эксперимента.',
                ['cycle_id' => $openedCycle?->id, 'photo_id' => $firstPhoto->id, 'sequence' => $cycleSeq],
            );
            $this->journal->log(
                $experiment,
                OzAbExperimentJournal::TYPE_PHOTO_PENDING,
                'Ждём, пока на карточке появится это фото.',
                ['cycle_id' => $openedCycle?->id, 'photo_id' => $firstPhoto->id],
            );
            $this->journal->log(
                $experiment,
                OzAbExperimentJournal::TYPE_EXPERIMENT_STARTED,
                $isRestart
                    ? 'Эксперимент перезапущен. Дальнейшая работа выполняется автоматически.'
                    : 'Эксперимент запущен. Дальнейшая работа выполняется автоматически.',
                ['campaign_id' => $campaignId, 'restart' => $isRestart],
            );

            $this->ensureCabinetTickScheduled((int) $cabinet->id, (int) $experiment->id);

            return [
                'success' => true,
                'experiment' => $experiment->fresh(['photos', 'product']),
                'messages' => ['Эксперимент запущен.'],
            ];
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('[OzAbExperimentEngine] start failed', [
                'experiment_id' => $experiment->id,
                'error' => $e->getMessage(),
            ]);

            if ($campaignStarted) {
                $this->safeDeactivate($token, $campaignId);
            }

            return [
                'success' => false,
                'messages' => ['Не удалось запустить эксперимент: ' . $e->getMessage()],
            ];
        }
    }

    /**
     * @return array{success: bool, experiment?: AbExperiment, messages: list<string>}
     */
    public function stop(OzCabinet $cabinet, AbExperiment $experiment): array
    {
        $status = $this->resolveStatus($experiment);
        if ($status !== OzAbTestStatus::Running) {
            return [
                'success' => false,
                'messages' => ['Остановить можно только эксперимент «В процессе».'],
            ];
        }

        $token = $this->accessToken($cabinet);
        $campaignId = (int) $experiment->oz_campaign_id;
        $sku = (int) ($experiment->sku ?: ($experiment->product?->sku ?? 0));

        $snapshot = ['views' => 0, 'clicks' => 0, 'spend' => 0.0, 'orders' => 0];
        if ($token !== null && $campaignId > 0 && $sku > 0) {
            try {
                $snapshot = $this->fetchStatsSnapshot(
                    $token,
                    $campaignId,
                    $sku,
                    $this->cycleStatsFromDate($experiment),
                );
            } catch (Throwable) {
                // zeros
            }
        }

        DB::transaction(function () use ($experiment, $snapshot) {
            /** @var AbExperiment $locked */
            $locked = AbExperiment::query()->whereKey($experiment->id)->lockForUpdate()->firstOrFail();
            $cycle = $locked->resolveOpenCycle();
            if ($cycle) {
                $this->anchorFirstCycleBaseline($locked, $cycle);
                $snapshot = $this->monotonicSnapshot($cycle, $snapshot);
                $cycle->views_end = $snapshot['views'];
                $cycle->clicks_end = $snapshot['clicks'];
                $cycle->spend_end = $snapshot['spend'];
                $cycle->orders_end = $snapshot['orders'];
                $cycle->ended_at = now();
                $cycle->end_reason = AbExperimentCycle::END_STOPPED;
                $cycle->save();
            }

            $locked->status = OzAbTestStatus::Stopped;
            $locked->finished_at = now();
            $locked->last_processed_at = now();
            $locked->save();
        });

        if ($token !== null && $campaignId > 0) {
            $this->safeDeactivate($token, $campaignId);
            $this->journal->log(
                $experiment,
                OzAbExperimentJournal::TYPE_CAMPAIGN_PAUSED,
                'Рекламная кампания остановлена.',
                ['campaign_id' => $campaignId],
            );
        }

        $this->restoreGallery($cabinet, $experiment);

        $this->journal->log(
            $experiment,
            OzAbExperimentJournal::TYPE_EXPERIMENT_STOPPED,
            'Эксперимент остановлен.',
            ['campaign_id' => $campaignId],
        );

        return [
            'success' => true,
            'experiment' => $experiment->fresh(['photos', 'product']),
            'messages' => ['Эксперимент остановлен.'],
        ];
    }

    /**
     * Тик кабинета: один запрос статистики на все running-кампании, затем обработка каждого эксперимента.
     *
     * @return array{success: bool, reschedule: bool, processed?: int, messages?: list<string>}
     */
    public function processCabinet(int $cabinetId): array
    {
        $experiments = AbExperiment::query()
            ->with(['photos', 'product', 'cabinet'])
            ->where('cabinet_id', $cabinetId)
            ->where('status', OzAbTestStatus::Running->value)
            ->orderBy('id')
            ->get();

        if ($experiments->isEmpty()) {
            return ['success' => true, 'reschedule' => false, 'processed' => 0];
        }

        /** @var OzCabinet|null $cabinet */
        $cabinet = $experiments->first()?->cabinet ?? OzCabinet::query()->find($cabinetId);
        if (! $cabinet || ! $this->hasPerformanceCredentials($cabinet)) {
            foreach ($experiments as $experiment) {
                $this->failExperiment($experiment, 'Нет ключей Performance API для обработки эксперимента.');
            }

            return ['success' => false, 'reschedule' => false, 'messages' => ['Нет ключей рекламы']];
        }

        $token = $this->accessToken($cabinet);
        if ($token === null) {
            foreach ($experiments as $experiment) {
                $this->handleTransientFailure($experiment, 'Не удалось получить доступ к рекламе Ozon.');
            }

            return ['success' => false, 'reschedule' => true, 'messages' => ['Не удалось получить доступ к рекламе Ozon.']];
        }

        $campaignIds = $experiments
            ->map(fn(AbExperiment $experiment) => (int) $experiment->oz_campaign_id)
            ->filter(fn(int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        try {
            $index = $this->fetchCabinetStatsSnapshots($token, $campaignIds);
        } catch (Throwable $e) {
            foreach ($experiments as $experiment) {
                $this->handleTransientFailure($experiment, $e->getMessage());
            }

            return ['success' => false, 'reschedule' => true, 'messages' => [$e->getMessage()]];
        }

        $processed = 0;
        foreach ($experiments as $experiment) {
            $campaignId = (int) $experiment->oz_campaign_id;
            $sku = (int) ($experiment->sku ?: ($experiment->product?->sku ?? 0));
            $fromDate = $this->cycleStatsFromDate($experiment);
            $snapshot = $this->snapshotFromIndex($index, $campaignId, $sku, $fromDate);
            $responseSkus = array_map('intval', array_keys($index[$campaignId] ?? []));
            if (
                $this->isEmptySnapshot($snapshot)
                && $responseSkus !== []
                && ($sku <= 0 || ! in_array($sku, $responseSkus, true))
            ) {
                $this->photoLog('warning', 'В статистике рекламы нет SKU эксперимента', [
                    'experiment_id' => $experiment->id,
                    'campaign_id' => $campaignId,
                    'sku' => $sku,
                    'response_skus' => $responseSkus,
                    'from_date' => $fromDate,
                    'snapshot' => $snapshot,
                ]);
            }
            $this->process($experiment, $snapshot);
            $processed++;
        }

        $stillRunning = AbExperiment::query()
            ->where('cabinet_id', $cabinetId)
            ->where('status', OzAbTestStatus::Running->value)
            ->exists();

        return ['success' => true, 'reschedule' => $stillRunning, 'processed' => $processed];
    }

    /**
     * @param  array{views:int,clicks:int,spend:float,orders:int}|null  $prefetchedSnapshot
     * @return array{success: bool, action?: string, messages: list<string>}
     */
    public function process(AbExperiment $experiment, ?array $prefetchedSnapshot = null): array
    {
        $cabinet = $experiment->relationLoaded('cabinet')
            ? $experiment->cabinet
            : OzCabinet::query()->find($experiment->cabinet_id);

        if (! $cabinet || ! $this->hasPerformanceCredentials($cabinet)) {
            $this->failExperiment($experiment, 'Нет ключей Performance API для обработки эксперимента.');

            return ['success' => false, 'action' => 'error', 'messages' => ['Нет ключей рекламы']];
        }

        if ($this->resolveStatus($experiment) !== OzAbTestStatus::Running) {
            return ['success' => true, 'action' => 'skipped', 'messages' => []];
        }

        $token = $this->accessToken($cabinet);
        if ($token === null) {
            return $this->handleTransientFailure($experiment, 'Не удалось получить доступ к рекламе Ozon.');
        }

        $campaignId = (int) $experiment->oz_campaign_id;
        $product = $experiment->relationLoaded('product')
            ? $experiment->product
            : AbProduct::query()->find($experiment->ab_product_id);
        $sku = (int) ($experiment->sku ?: ($product?->sku ?? 0));

        if ($campaignId <= 0 || $sku <= 0) {
            $this->failExperiment($experiment, 'Не задана кампания или SKU товара.');

            return ['success' => false, 'action' => 'error', 'messages' => ['Некорректные данные эксперимента']];
        }

        try {
            $snapshot = $prefetchedSnapshot ?? $this->fetchStatsSnapshot(
                $token,
                $campaignId,
                $sku,
                $this->cycleStatsFromDate($experiment),
            );
        } catch (Throwable $e) {
            return $this->handleTransientFailure($experiment, $e->getMessage());
        }

        $cardCheck = $this->inspectCardPhoto($cabinet, $experiment);

        try {
            return DB::transaction(function () use ($experiment, $snapshot, $cabinet, $token, $campaignId, $cardCheck) {
                /** @var AbExperiment $locked */
                $locked = AbExperiment::query()->whereKey($experiment->id)->lockForUpdate()->firstOrFail();
                if ($this->resolveStatus($locked) !== OzAbTestStatus::Running) {
                    return ['success' => true, 'action' => 'skipped', 'messages' => []];
                }

                $locked->load(['photos', 'product']);
                $cycle = $locked->resolveOpenCycle();
                if (! $cycle) {
                    $this->failExperiment($locked, 'Не найден активный цикл эксперимента.', false);

                    return ['success' => false, 'action' => 'error', 'messages' => ['Нет активного цикла']];
                }

                $snapshot = $this->monotonicSnapshot($cycle, $snapshot);
                $this->anchorFirstCycleBaseline($locked, $cycle);

                if ($cycle->photo_confirmed_at === null) {
                    $waiting = $this->holdCycleUntilPhotoConfirmed($locked, $cycle, $snapshot, $cardCheck);
                    if ($waiting !== null) {
                        return $waiting;
                    }
                    $cycle->refresh();
                }

                $settings = $this->settingsOf($locked);
                $deltaViews = $cycle->deltaViews($snapshot['views']);
                $elapsedMinutes = $cycle->photo_confirmed_at
                    ? $cycle->photo_confirmed_at->diffInMinutes(now())
                    : 0;
                $this->photoLog('info', 'Прирост показов варианта', [
                    'experiment_id' => (int) $locked->id,
                    'cycle_id' => (int) $cycle->id,
                    'photo_id' => (int) $cycle->ab_experiment_photo_id,
                    'photo_confirmed_at' => $cycle->photo_confirmed_at?->toDateTimeString(),
                    'views_start' => (int) $cycle->views_start,
                    'views_now' => (int) $snapshot['views'],
                    'delta_views' => $deltaViews,
                    'clicks_start' => (int) $cycle->clicks_start,
                    'clicks_now' => (int) $snapshot['clicks'],
                    'spend_now' => (float) $snapshot['spend'],
                    'orders_now' => (int) $snapshot['orders'],
                    'elapsed_minutes' => $elapsedMinutes,
                ]);

                $shouldSwitch = false;
                $endReason = null;
                if ($deltaViews >= $settings['impressions_per_round']) {
                    $shouldSwitch = true;
                    $endReason = AbExperimentCycle::END_IMPRESSIONS;
                } elseif ($elapsedMinutes >= $settings['round_minutes']) {
                    $shouldSwitch = true;
                    $endReason = AbExperimentCycle::END_TIME;
                }

                if ($this->allPhotosReachedTarget($locked, $cycle, $snapshot, $settings['impressions_per_photo'])) {
                    return $this->finalizeCompletedInTransaction(
                        $locked,
                        $cycle,
                        $snapshot,
                        $cabinet,
                        $token,
                        $campaignId,
                    );
                }

                if ($shouldSwitch && $endReason !== null) {
                    if ($locked->photos->count() <= 1) {
                        $this->applyProvisionalCycleEnds($cycle, $snapshot);
                        $locked->consecutive_failures = 0;
                        $locked->last_processed_at = now();
                        $locked->progress = $this->computeProgress(
                            $locked,
                            $settings['impressions_per_photo'],
                            $cycle,
                            $snapshot,
                        );
                        $locked->save();

                        return ['success' => true, 'action' => 'updated', 'messages' => []];
                    }

                    $switchResult = $this->switchPhoto($locked, $cycle, $snapshot, $endReason, $cabinet);
                    if (! ($switchResult['success'] ?? false)) {
                        $locked->consecutive_failures = (int) $locked->consecutive_failures + 1;
                        $locked->save();
                        $this->journal->log(
                            $locked,
                            OzAbExperimentJournal::TYPE_API_RETRY,
                            'Не удалось сменить фотографию: ' . ($switchResult['message'] ?? 'ошибка'),
                            array_merge(
                                ['failures' => $locked->consecutive_failures],
                                is_array($switchResult['meta'] ?? null) ? $switchResult['meta'] : [],
                            ),
                        );

                        if ($locked->consecutive_failures >= self::MAX_CONSECUTIVE_FAILURES) {
                            $this->finalizeErrorInTransaction(
                                $locked,
                                $cycle,
                                $snapshot,
                                $switchResult['message'] ?? 'Ошибка смены фотографии',
                                $token,
                                $campaignId,
                            );

                            return [
                                'success' => false,
                                'action' => 'error',
                                'messages' => [$switchResult['message'] ?? 'error'],
                            ];
                        }

                        return [
                            'success' => false,
                            'action' => 'retry',
                            'messages' => [$switchResult['message'] ?? 'switch failed'],
                        ];
                    }

                    $locked->refresh();
                    if ($this->allPhotosReachedTarget($locked, null, $snapshot, $settings['impressions_per_photo'])) {
                        $open = AbExperimentCycle::query()
                            ->where('ab_experiment_id', $locked->id)
                            ->whereNull('ended_at')
                            ->orderByDesc('sequence')
                            ->first();
                        if ($open) {
                            return $this->finalizeCompletedInTransaction(
                                $locked,
                                $open,
                                $snapshot,
                                $cabinet,
                                $token,
                                $campaignId,
                            );
                        }
                    }

                    $locked->consecutive_failures = 0;
                    $locked->last_processed_at = now();
                    $locked->progress = $this->computeProgress($locked, $settings['impressions_per_photo']);
                    $locked->save();

                    return ['success' => true, 'action' => 'switched', 'messages' => []];
                }

                $this->applyProvisionalCycleEnds($cycle, $snapshot);
                $locked->consecutive_failures = 0;
                $locked->last_processed_at = now();
                $locked->progress = $this->computeProgress(
                    $locked,
                    $settings['impressions_per_photo'],
                    $cycle,
                    $snapshot,
                );
                $locked->save();

                return ['success' => true, 'action' => 'updated', 'messages' => []];
            });
        } catch (Throwable $e) {
            Log::error('[OzAbExperimentEngine] process failed', [
                'experiment_id' => $experiment->id,
                'error' => $e->getMessage(),
            ]);

            return $this->handleTransientFailure($experiment, $e->getMessage());
        }
    }

    /**
     * @param  array{views:int,clicks:int,spend:float,orders:int}  $snapshot
     * @return array{success: bool, message?: string, meta?: array<string, mixed>}
     */
    private function switchPhoto(
        AbExperiment $experiment,
        AbExperimentCycle $cycle,
        array $snapshot,
        string $endReason,
        OzCabinet $cabinet,
    ): array {
        $photos = $experiment->photos->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values();
        if ($photos->isEmpty()) {
            return ['success' => false, 'message' => 'Нет фотографий для переключения'];
        }

        $currentIndex = $photos->search(
            fn(AbExperimentPhoto $p) => (int) $p->id === (int) $cycle->ab_experiment_photo_id,
        );
        if ($currentIndex === false) {
            $currentIndex = 0;
        }
        $nextIndex = ((int) $currentIndex + 1) % $photos->count();
        /** @var AbExperimentPhoto $nextPhoto */
        $nextPhoto = $photos[$nextIndex];

        $upload = $this->uploadPhotoAsMain($cabinet, $experiment, $nextPhoto);
        if (! ($upload['success'] ?? false)) {
            $this->applyProvisionalCycleEnds($cycle, $snapshot);

            return [
                'success' => false,
                'message' => $upload['message'] ?? 'Не удалось загрузить следующую фотографию',
                'meta' => is_array($upload['meta'] ?? null) ? $upload['meta'] : [],
            ];
        }

        $cycle->views_end = $snapshot['views'];
        $cycle->clicks_end = $snapshot['clicks'];
        $cycle->spend_end = $snapshot['spend'];
        $cycle->orders_end = $snapshot['orders'];
        $cycle->ended_at = now();
        $cycle->end_reason = $endReason;
        $cycle->save();

        $this->journal->log(
            $experiment,
            OzAbExperimentJournal::TYPE_CYCLE_CLOSED,
            'Цикл №' . $cycle->sequence . ' закрыт.',
            ['cycle_id' => $cycle->id, 'reason' => $endReason],
        );

        $nextSequence = (int) $cycle->sequence + 1;
        AbExperimentCycle::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $experiment->cabinet_id,
            'ab_experiment_photo_id' => $nextPhoto->id,
            'sequence' => $nextSequence,
            'started_at' => now(),
            'views_start' => $snapshot['views'],
            'clicks_start' => $snapshot['clicks'],
            'spend_start' => $snapshot['spend'],
            'orders_start' => $snapshot['orders'],
        ]);

        $this->journal->log(
            $experiment,
            OzAbExperimentJournal::TYPE_PHOTO_SWITCHED,
            'На карточке установлен следующий вариант фотографии.',
            array_merge(
                ['photo_id' => $nextPhoto->id, 'sequence' => $nextSequence],
                is_array($upload['meta'] ?? null) ? $upload['meta'] : [],
            ),
        );
        $opened = AbExperimentCycle::query()
            ->where('ab_experiment_id', $experiment->id)
            ->whereNull('ended_at')
            ->orderByDesc('sequence')
            ->first();
        $this->journal->log(
            $experiment,
            OzAbExperimentJournal::TYPE_CYCLE_OPENED,
            'Открыт цикл №' . $nextSequence . ' эксперимента.',
            ['cycle_id' => $opened?->id, 'photo_id' => $nextPhoto->id, 'sequence' => $nextSequence],
        );
        $this->journal->log(
            $experiment,
            OzAbExperimentJournal::TYPE_PHOTO_PENDING,
            'Ждём, пока на карточке появится это фото.',
            ['cycle_id' => $opened?->id, 'photo_id' => $nextPhoto->id],
        );

        return ['success' => true];
    }

    /**
     * Пока кадр варианта не найден на карточке, круг не сменяется.
     * Показы за окно цикла при этом уже пишутся.
     * null — фото подтверждено, можно считать круг дальше.
     *
     * @param  array{views:int,clicks:int,spend:float,orders:int}  $snapshot
     * @param  array<string, mixed>  $cardCheck
     * @return array{success: bool, action: string, messages: list<string>}|null
     */
    private function holdCycleUntilPhotoConfirmed(
        AbExperiment $experiment,
        AbExperimentCycle $cycle,
        array $snapshot,
        array $cardCheck,
    ): ?array {
        $sameCycle = (int) ($cardCheck['cycle_id'] ?? 0) === (int) $cycle->id;
        $status = (string) ($cardCheck['status'] ?? 'download_failed');
        $primaryUrl = (string) ($cardCheck['primary_url'] ?? '');

        if ($sameCycle && $status === 'confirmed') {
            $cycle->photo_confirmed_at = now();
            if ($primaryUrl !== '') {
                $cycle->last_seen_primary_url = $primaryUrl;
            }
            $cycle->save();
            $this->photoLog('info', 'Фото подтверждено, baseline показов зафиксирован', [
                'experiment_id' => (int) $experiment->id,
                'cycle_id' => (int) $cycle->id,
                'photo_id' => (int) $cycle->ab_experiment_photo_id,
                'primary_url' => $primaryUrl,
                'card_md5' => $cardCheck['md5'] ?? null,
                'card_hash' => $cardCheck['hash'] ?? null,
                'views_start' => (int) $cycle->views_start,
                'clicks_start' => (int) $cycle->clicks_start,
                'spend_start' => (float) $cycle->spend_start,
                'orders_start' => (int) $cycle->orders_start,
                'received_views' => (int) $snapshot['views'],
                'received_clicks' => (int) $snapshot['clicks'],
            ]);
            $this->journal->log(
                $experiment,
                OzAbExperimentJournal::TYPE_PHOTO_CONFIRMED,
                'На карточке нужное фото.',
                [
                    'cycle_id' => $cycle->id,
                    'photo_id' => $cycle->ab_experiment_photo_id,
                    'primary_url' => $primaryUrl,
                ],
            );

            return null;
        }

        if ($sameCycle && $status === 'waiting' && $primaryUrl !== '') {
            $cycle->last_seen_primary_url = $primaryUrl;
        }
        $this->applyProvisionalCycleEnds($cycle, $snapshot);
        $this->photoLog('info', 'Показы с рекламы записаны в круг', [
            'experiment_id' => (int) $experiment->id,
            'cycle_id' => (int) $cycle->id,
            'photo_id' => (int) $cycle->ab_experiment_photo_id,
            'card_status' => $status,
            'same_cycle' => $sameCycle,
            'primary_url' => $primaryUrl,
            'card_md5' => $cardCheck['md5'] ?? null,
            'card_hash' => $cardCheck['hash'] ?? null,
            'received_views' => (int) $snapshot['views'],
            'received_clicks' => (int) $snapshot['clicks'],
            'received_spend' => (float) $snapshot['spend'],
            'received_orders' => (int) $snapshot['orders'],
            'views_start' => (int) $cycle->views_start,
            'views_end' => (int) $cycle->views_end,
            'delta_views' => $cycle->deltaViews(),
            'delta_clicks' => $cycle->deltaClicks(),
        ]);

        $settings = $this->settingsOf($experiment);
        $experiment->consecutive_failures = 0;
        $experiment->last_processed_at = now();
        $experiment->progress = $this->computeProgress(
            $experiment,
            $settings['impressions_per_photo'],
            $cycle,
            $snapshot,
        );
        $experiment->save();
        $this->journalPhotoPendingOnce($experiment, $cycle);

        return ['success' => true, 'action' => 'waiting_photo', 'messages' => []];
    }

    /**
     * @return array{status: string, cycle_id: int, primary_url: string, md5: ?string, hash: ?string}
     */
    private function inspectCardPhoto(OzCabinet $cabinet, AbExperiment $experiment): array
    {
        $empty = [
            'status' => 'download_failed',
            'cycle_id' => 0,
            'primary_url' => '',
            'md5' => null,
            'hash' => null,
        ];

        try {
            $experiment->loadMissing(['product', 'photos']);
            $cycle = $experiment->resolveOpenCycle();
            if (! $cycle || $cycle->photo_confirmed_at !== null) {
                return $empty;
            }

            $product = $experiment->product;
            $photo = $experiment->photos->firstWhere('id', (int) $cycle->ab_experiment_photo_id);
            if (! $photo) {
                $photo = AbExperimentPhoto::query()->find($cycle->ab_experiment_photo_id);
            }
            if (! $product || ! $photo) {
                return ['status' => 'download_failed', 'cycle_id' => (int) $cycle->id, 'primary_url' => '', 'md5' => null, 'hash' => null];
            }

            $photo = $this->ensurePhotoFingerprint($photo);
            $primaryUrls = $this->currentPrimaryUrls($cabinet, (int) $product->oz_product_id);
            $context = [
                'experiment_id' => (int) $experiment->id,
                'cycle_id' => (int) $cycle->id,
                'photo_id' => (int) $photo->id,
                'product_id' => (int) $product->oz_product_id,
                'photo_url' => $this->publicPhotoUrl($photo),
                'photo_md5' => $photo->content_md5,
                'photo_hash' => $photo->content_hash,
                'gd' => function_exists('imagecreatefromstring'),
                'primary_urls' => $primaryUrls,
            ];
            if (! function_exists('imagecreatefromstring')) {
                $this->photoLog('warning', 'На сервере нет библиотеки GD: отпечаток картинки не считается, совпадение возможно только по MD5', $context);
            }

            if ($primaryUrls === []) {
                $this->photoLog('warning', 'Не удалось прочитать главное фото карточки', $context);

                return ['status' => 'download_failed', 'cycle_id' => (int) $cycle->id, 'primary_url' => '', 'md5' => null, 'hash' => null];
            }

            // Одну и ту же ссылку проверяем каждый тик: Ozon может подменить байты, не меняя URL.
            $downloadedAny = false;
            $waitingUrl = '';
            $waitingActual = ['md5' => null, 'hash' => null];
            $snapshot = is_array($experiment->gallery_snapshot) ? $experiment->gallery_snapshot : [];

            foreach ($primaryUrls as $primaryUrl) {
                $binary = $this->imageBytesForCompare($primaryUrl, $photo);
                if ($binary === null) {
                    continue;
                }
                $downloadedAny = true;
                $actual = OzAbPhotoFingerprint::fromBinary($binary) ?? ['md5' => md5($binary), 'hash' => null];
                $matched = OzAbPhotoFingerprint::matches(
                    $photo->content_md5,
                    $photo->content_hash,
                    $actual['md5'],
                    $actual['hash'],
                );
                $distance = ($photo->content_hash && $actual['hash'])
                    ? OzAbPhotoFingerprint::distance((string) $photo->content_hash, (string) $actual['hash'])
                    : null;
                $stillOriginal = OzAbPhotoFingerprint::matches(
                    isset($snapshot['primary_md5']) ? (string) $snapshot['primary_md5'] : null,
                    isset($snapshot['primary_hash']) ? (string) $snapshot['primary_hash'] : null,
                    $actual['md5'],
                    $actual['hash'],
                );
                $decision = $matched ? 'confirmed' : ($stillOriginal ? 'still_original' : 'different');
                $this->photoLog('info', $matched
                    ? 'На карточке фото варианта'
                    : ($stillOriginal ? 'На карточке ещё исходное фото' : 'Главное фото карточки не совпало с вариантом'), $context + [
                        'public_url' => $primaryUrl,
                        'bytes' => strlen($binary),
                        'card_md5' => $actual['md5'],
                        'card_hash' => $actual['hash'],
                        'photo_md5' => $photo->content_md5,
                        'photo_hash' => $photo->content_hash,
                        'hash_distance' => $distance,
                        'distance_max' => OzAbPhotoFingerprint::HASH_DISTANCE_MAX,
                        'decision' => $decision,
                    ]);

                if ($matched) {
                    return [
                        'status' => 'confirmed',
                        'cycle_id' => (int) $cycle->id,
                        'primary_url' => $primaryUrl,
                        'md5' => $actual['md5'],
                        'hash' => $actual['hash'],
                    ];
                }

                $waitingUrl = $primaryUrl;
                $waitingActual = $actual;
            }

            if (! $downloadedAny) {
                $this->photoLog('warning', 'Не удалось скачать главное фото карточки', $context);

                return ['status' => 'download_failed', 'cycle_id' => (int) $cycle->id, 'primary_url' => '', 'md5' => null, 'hash' => null];
            }

            return [
                'status' => 'waiting',
                'cycle_id' => (int) $cycle->id,
                'primary_url' => $waitingUrl,
                'md5' => $waitingActual['md5'],
                'hash' => $waitingActual['hash'],
            ];
        } catch (Throwable $e) {
            $this->photoLog('warning', 'Проверка фото карточки не удалась', [
                'experiment_id' => $experiment->id,
                'error' => $e->getMessage(),
            ]);

            return $empty;
        }
    }

    private function ensurePhotoFingerprint(AbExperimentPhoto $photo): AbExperimentPhoto
    {
        if ($photo->content_md5 && $photo->content_hash) {
            return $photo;
        }

        $disk = (string) ($photo->disk ?: self::PHOTO_DISK);
        $path = (string) $photo->path;
        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            return $photo;
        }

        $binary = Storage::disk($disk)->get($path);
        if (! is_string($binary) || $binary === '') {
            return $photo;
        }

        $fingerprint = OzAbPhotoFingerprint::fromBinary($binary);
        if ($fingerprint === null) {
            $this->photoLog('warning', 'Не удалось посчитать отпечаток файла варианта', [
                'photo_id' => (int) $photo->id,
                'bytes' => strlen($binary),
                'gd' => function_exists('imagecreatefromstring'),
            ]);

            return $photo;
        }

        $photo->content_md5 = $fingerprint['md5'];
        if ($fingerprint['hash'] === null) {
            $this->photoLog('warning', 'MD5 файла варианта есть, отпечаток пустой', [
                'photo_id' => (int) $photo->id,
                'photo_md5' => $fingerprint['md5'],
                'bytes' => strlen($binary),
                'gd' => function_exists('imagecreatefromstring'),
            ]);
        }
        if ($fingerprint['hash'] !== null) {
            $photo->content_hash = $fingerprint['hash'];
        }
        $photo->save();

        return $photo;
    }

    /**
     * Ссылки главного фото: сначала то, что сейчас в карточке товара, затем статус загрузки.
     * Статус загрузки может ещё держать прежний кадр, когда на карточке уже вариант.
     *
     * @return list<string>
     */
    private function currentPrimaryUrls(OzCabinet $cabinet, int $productId): array
    {
        $urls = [];
        $list = $this->sellerApi->getProductsInfo(
            (string) $cabinet->apikey,
            (string) $cabinet->client_id,
            [$productId],
        );
        $items = Arr::get($list, 'data.items', Arr::get($list, 'data.result.items', []));
        $item = is_array($items) ? ($items[0] ?? null) : null;
        $fromCard = '';
        if (is_array($item)) {
            $fromCard = $this->firstImageUrl($item['primary_image'] ?? null);
            if ($fromCard !== '') {
                $urls[] = $fromCard;
            }
        }

        $info = $this->sellerApi->getProductPicturesInfo(
            (string) $cabinet->apikey,
            (string) $cabinet->client_id,
            [$productId],
        );
        $pictures = $this->summarizePicturesInfo($info);
        foreach ($pictures['primary'] as $url) {
            if ($url !== '' && ! in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        $this->photoLog('info', 'Что вернула карточка товара', [
            'product_id' => $productId,
            'card_success' => $list['success'] ?? null,
            'card_primary' => $fromCard,
            'card_primary_type' => is_array($item) ? get_debug_type($item['primary_image'] ?? null) : null,
            'card_items' => is_array($items) ? count($items) : 0,
            'pictures_success' => $info['success'] ?? null,
            'pictures_primary' => $pictures['primary'],
            'pictures_errors' => $pictures['errors'],
            'compare_urls' => $urls,
        ]);

        return $urls;
    }

    /**
     * Наш файл читаем с диска: так сверка не зависит от того, открывается ли ссылка с сервера.
     */
    private function imageBytesForCompare(string $url, AbExperimentPhoto $photo): ?string
    {
        $own = $this->publicPhotoUrl($photo);
        if (
            $own !== null
            && $this->pictureUrlKey($own) !== ''
            && $this->pictureUrlKey($own) === $this->pictureUrlKey($url)
        ) {
            $disk = (string) ($photo->disk ?: self::PHOTO_DISK);
            $path = (string) $photo->path;
            if ($path !== '' && Storage::disk($disk)->exists($path)) {
                $binary = Storage::disk($disk)->get($path);
                if (is_string($binary) && $binary !== '') {
                    $this->photoLog('info', 'Сверка читает свой файл варианта с диска', [
                        'photo_id' => (int) $photo->id,
                        'public_url' => $url,
                        'bytes' => strlen($binary),
                    ]);

                    return $binary;
                }
            }

            $this->photoLog('warning', 'Ссылка совпала с файлом варианта, с диска прочитать не удалось', [
                'photo_id' => (int) $photo->id,
                'public_url' => $url,
                'disk' => $disk,
                'path' => $path,
            ]);
        }

        return $this->downloadImage($url);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function withPrimaryFingerprint(array $snapshot): array
    {
        $url = $this->firstImageUrl($snapshot['primary_image'] ?? null);
        if ($url === '') {
            return $snapshot;
        }

        $binary = $this->downloadImage($url);
        if ($binary === null) {
            $this->photoLog('warning', 'Не удалось скачать исходное главное фото', ['public_url' => $url]);

            return $snapshot;
        }

        $fingerprint = OzAbPhotoFingerprint::fromBinary($binary);
        if ($fingerprint === null) {
            return $snapshot;
        }

        $snapshot['primary_md5'] = $fingerprint['md5'];
        $snapshot['primary_hash'] = $fingerprint['hash'];

        return $snapshot;
    }

    private function downloadImage(string $url): ?string
    {
        try {
            $response = Http::timeout(8)
                ->connectTimeout(4)
                ->withHeaders(['Accept' => 'image/*'])
                ->get($url);
            $body = $response->body();
            $meta = [
                'public_url' => $url,
                'http_status' => $response->status(),
                'content_type' => $response->header('Content-Type'),
                'bytes' => strlen($body),
            ];
            if (! $response->successful() || $body === '' || strlen($body) > 8_000_000) {
                $this->photoLog('warning', 'Скачивание картинки не удалось', $meta);

                return null;
            }

            $this->photoLog('info', 'Картинка скачана', $meta);

            return $body;
        } catch (Throwable $e) {
            $this->photoLog('warning', 'Скачивание картинки не удалось', [
                'public_url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function journalPhotoPendingOnce(AbExperiment $experiment, AbExperimentCycle $cycle): void
    {
        $already = AbExperimentEvent::query()
            ->where('ab_experiment_id', $experiment->id)
            ->where('type', OzAbExperimentJournal::TYPE_PHOTO_PENDING)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->contains(fn (AbExperimentEvent $event): bool => (int) ($event->meta['cycle_id'] ?? 0) === (int) $cycle->id);

        if ($already) {
            return;
        }

        $this->journal->log(
            $experiment,
            OzAbExperimentJournal::TYPE_PHOTO_PENDING,
            'Ждём, пока на карточке появится это фото.',
            ['cycle_id' => $cycle->id, 'photo_id' => $cycle->ab_experiment_photo_id],
        );
    }

    /**
     * @param  array{views:int,clicks:int,spend:float,orders:int}  $snapshot
     * @return array{success: bool, action: string, messages: list<string>}
     */
    private function finalizeCompletedInTransaction(
        AbExperiment $experiment,
        AbExperimentCycle $cycle,
        array $snapshot,
        OzCabinet $cabinet,
        string $token,
        int $campaignId,
    ): array {
        $this->closeCycle($cycle, $snapshot, AbExperimentCycle::END_COMPLETED);

        $winnerId = $this->resolveWinnerPhotoId($experiment);
        $experiment->winner_photo_id = $winnerId;
        $experiment->status = OzAbTestStatus::Completed;
        $experiment->progress = 100;
        $experiment->finished_at = now();
        $experiment->last_processed_at = now();
        $experiment->consecutive_failures = 0;
        $experiment->save();

        $this->safeDeactivate($token, $campaignId);

        $winnerPhoto = $winnerId
            ? AbExperimentPhoto::query()->find($winnerId)
            : null;
        if ($winnerPhoto) {
            $upload = $this->uploadPhotoAsMain($cabinet, $experiment, $winnerPhoto);
            if (! ($upload['success'] ?? false)) {
                $experiment->error_message = 'Эксперимент завершён, но победившее фото не удалось установить: '
                    . ($upload['message'] ?? 'ошибка');
                $experiment->save();
            }
        }

        $this->journal->log(
            $experiment,
            OzAbExperimentJournal::TYPE_WINNER_SELECTED,
            $winnerId ? 'Выбран победитель по CTR.' : 'Недостаточно данных для выбора победителя.',
            ['winner_photo_id' => $winnerId],
        );
        $this->journal->log(
            $experiment,
            OzAbExperimentJournal::TYPE_EXPERIMENT_COMPLETED,
            'Эксперимент завершён.',
            ['campaign_id' => $campaignId],
        );

        return ['success' => true, 'action' => 'completed', 'messages' => ['Эксперимент завершён.']];
    }

    /**
     * @param  array{views:int,clicks:int,spend:float,orders:int}  $snapshot
     */
    private function finalizeErrorInTransaction(
        AbExperiment $experiment,
        AbExperimentCycle $cycle,
        array $snapshot,
        string $message,
        string $token,
        int $campaignId,
    ): void {
        $this->closeCycle($cycle, $snapshot, AbExperimentCycle::END_ERROR);
        $experiment->status = OzAbTestStatus::Error;
        $experiment->error_message = mb_substr($message, 0, 1000);
        $experiment->finished_at = now();
        $experiment->last_processed_at = now();
        $experiment->save();
        $this->safeDeactivate($token, $campaignId);
        $this->journal->log(
            $experiment,
            OzAbExperimentJournal::TYPE_EXPERIMENT_ERROR,
            $message,
            ['campaign_id' => $campaignId],
        );
    }

    public function failExperiment(AbExperiment $experiment, string $message, bool $pauseCampaign = true): void
    {
        $experiment->status = OzAbTestStatus::Error;
        $experiment->error_message = mb_substr($message, 0, 1000);
        $experiment->finished_at = now();
        $experiment->last_processed_at = now();
        $experiment->save();

        if ($pauseCampaign && $experiment->oz_campaign_id) {
            $cabinet = $experiment->relationLoaded('cabinet')
                ? $experiment->cabinet
                : OzCabinet::query()->find($experiment->cabinet_id);
            if ($cabinet) {
                $token = $this->accessToken($cabinet);
                if ($token !== null) {
                    $this->safeDeactivate($token, (int) $experiment->oz_campaign_id);
                }
            }
        }

        $this->journal->log(
            $experiment,
            OzAbExperimentJournal::TYPE_EXPERIMENT_ERROR,
            $message,
        );
    }

    /**
     * @return array{success: bool, action: string, messages: list<string>}
     */
    private function handleTransientFailure(AbExperiment $experiment, string $message): array
    {
        $experiment->consecutive_failures = (int) $experiment->consecutive_failures + 1;
        $experiment->last_processed_at = now();
        $experiment->save();

        $this->journal->log(
            $experiment,
            OzAbExperimentJournal::TYPE_API_RETRY,
            mb_substr($message, 0, 500),
            ['failures' => $experiment->consecutive_failures],
        );

        if ($experiment->consecutive_failures >= self::MAX_CONSECUTIVE_FAILURES) {
            $this->failExperiment($experiment, $message);

            return ['success' => false, 'action' => 'error', 'messages' => [$message]];
        }

        return ['success' => false, 'action' => 'retry', 'messages' => [$message]];
    }

    /**
     * @param  array{views:int,clicks:int,spend:float,orders:int}  $snapshot
     */
    private function closeCycle(AbExperimentCycle $cycle, array $snapshot, string $reason): void
    {
        $cycle->views_end = $snapshot['views'];
        $cycle->clicks_end = $snapshot['clicks'];
        $cycle->spend_end = $snapshot['spend'];
        $cycle->orders_end = $snapshot['orders'];
        $cycle->ended_at = now();
        $cycle->end_reason = $reason;
        $cycle->save();
    }

    /**
     * @param  array{views:int,clicks:int,spend:float,orders:int}  $snapshot
     */
    private function applyProvisionalCycleEnds(AbExperimentCycle $cycle, array $snapshot): void
    {
        $cycle->views_end = $snapshot['views'];
        $cycle->clicks_end = $snapshot['clicks'];
        $cycle->spend_end = $snapshot['spend'];
        $cycle->orders_end = $snapshot['orders'];
        $cycle->save();
    }

    /**
     * @param  array{views:int,clicks:int,spend:float,orders:int}  $snapshot
     */
    private function allPhotosReachedTarget(
        AbExperiment $experiment,
        ?AbExperimentCycle $openCycle,
        array $snapshot,
        int $targetImpressions,
    ): bool {
        $photos = $experiment->relationLoaded('photos')
            ? $experiment->photos
            : $experiment->photos()->get();

        if ($photos->isEmpty()) {
            return false;
        }

        $totals = $this->photoViewTotals($experiment, $openCycle, $snapshot);
        foreach ($photos as $photo) {
            $views = (int) ($totals[(int) $photo->id] ?? 0);
            if ($views < $targetImpressions) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{views:int,clicks:int,spend:float,orders:int}|null  $openSnapshot
     * @return array<int, int>
     */
    public function photoViewTotals(
        AbExperiment $experiment,
        ?AbExperimentCycle $openCycle = null,
        ?array $openSnapshot = null,
    ): array {
        $totals = [];
        foreach ($this->loadAllCycles($experiment) as $cycle) {
            $photoId = (int) $cycle->ab_experiment_photo_id;
            if ($cycle->ended_at !== null) {
                $totals[$photoId] = ($totals[$photoId] ?? 0) + $cycle->deltaViews();
            } elseif (
                $openCycle
                && (int) $cycle->id === (int) $openCycle->id
                && $openSnapshot !== null
            ) {
                $totals[$photoId] = ($totals[$photoId] ?? 0) + $cycle->deltaViews($openSnapshot['views']);
            } elseif ($cycle->views_end !== null) {
                $totals[$photoId] = ($totals[$photoId] ?? 0) + $cycle->deltaViews();
            }
        }

        return $totals;
    }

    /**
     * @return array<int, array{views:int,clicks:int,ctr:float|null}>
     */
    public function photoAggregates(AbExperiment $experiment): array
    {
        $agg = [];
        foreach ($this->loadAllCycles($experiment) as $cycle) {
            if ($cycle->ended_at === null && $cycle->views_end === null) {
                continue;
            }
            $photoId = (int) $cycle->ab_experiment_photo_id;
            if (! isset($agg[$photoId])) {
                $agg[$photoId] = ['views' => 0, 'clicks' => 0, 'ctr' => null];
            }
            $agg[$photoId]['views'] += $cycle->deltaViews();
            $agg[$photoId]['clicks'] += $cycle->deltaClicks();
        }

        foreach ($agg as $photoId => $row) {
            $views = $row['views'];
            $clicks = $row['clicks'];
            $agg[$photoId]['ctr'] = $views > 0 ? round(($clicks / $views) * 100, 4) : null;
        }

        return $agg;
    }

    /**
     * @return Collection<int, AbExperimentCycle>
     */
    public function loadAllCycles(AbExperiment $experiment): Collection
    {
        return AbExperimentCycle::query()
            ->where('ab_experiment_id', $experiment->id)
            ->get();
    }

    public function totalRounds(AbExperiment $experiment): int
    {
        return (int) AbExperimentCycle::query()
            ->where('ab_experiment_id', $experiment->id)
            ->max('sequence');
    }

    public function resolveWinnerPhotoId(AbExperiment $experiment): ?int
    {
        $agg = $this->photoAggregates($experiment);
        if ($agg === []) {
            return null;
        }

        $photos = $experiment->relationLoaded('photos')
            ? $experiment->photos
            : $experiment->photos()->orderBy('sort_order')->orderBy('id')->get();

        $bestId = null;
        $bestCtr = -1.0;
        $bestViews = -1;

        foreach ($photos as $photo) {
            $id = (int) $photo->id;
            $row = $agg[$id] ?? null;
            if ($row === null || $row['views'] <= 0) {
                continue;
            }
            $ctr = (float) ($row['ctr'] ?? 0);
            $views = (int) $row['views'];
            if (
                $ctr > $bestCtr
                || ($ctr === $bestCtr && $views > $bestViews)
                || ($ctr === $bestCtr && $views === $bestViews && ($bestId === null || $id < $bestId))
            ) {
                $bestCtr = $ctr;
                $bestViews = $views;
                $bestId = $id;
            }
        }

        return $bestId;
    }

    /**
     * @param  array{views:int,clicks:int,spend:float,orders:int}|null  $openSnapshot
     */
    public function computeProgress(
        AbExperiment $experiment,
        int $targetImpressions,
        ?AbExperimentCycle $openCycle = null,
        ?array $openSnapshot = null,
    ): int {
        $breakdown = $this->impressionsProgressBreakdown(
            $experiment,
            $targetImpressions,
            $openCycle,
            $openSnapshot,
        );

        return (int) ($breakdown['progress'] ?? (int) $experiment->progress);
    }

    /**
     * @param  array{views:int,clicks:int,spend:float,orders:int}|null  $openSnapshot
     * @return array<string, mixed>
     */
    public function impressionsProgressBreakdown(
        AbExperiment $experiment,
        int $targetImpressions,
        ?AbExperimentCycle $openCycle = null,
        ?array $openSnapshot = null,
    ): array {
        $photos = $experiment->relationLoaded('photos')
            ? $experiment->photos->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values()
            : $experiment->photos()->orderBy('sort_order')->orderBy('id')->get();

        $target = max(0, $targetImpressions);
        $totals = $this->photoViewTotals($experiment, $openCycle, $openSnapshot);
        $photoRows = [];
        $ratios = [];
        $totalViews = 0;

        foreach ($photos as $photo) {
            $id = (int) $photo->id;
            $views = (int) ($totals[$id] ?? 0);
            $totalViews += $views;
            $ratio = $target > 0 ? min(1.0, $views / $target) : 0.0;
            $ratios[] = $ratio;
            $photoRows[] = [
                'id' => $id,
                'sort_order' => (int) $photo->sort_order,
                'views' => $views,
                'ratio' => round($ratio, 4),
            ];
        }

        $bottleneck = $ratios === [] ? 0.0 : min($ratios);
        $progress = $target > 0 && $photos->isNotEmpty()
            ? max(0, min(99, (int) floor($bottleneck * 100)))
            : 0;

        return [
            'progress' => $progress,
            'mode' => $totalViews <= 0 ? 'pending' : 'views',
            'target_per_photo' => $target,
            'total_views' => $totalViews,
            'bottleneck_ratio' => round($bottleneck, 4),
            'photos' => $photoRows,
        ];
    }

    /**
     * Sync-снимок по всем кампаниям кабинета (пачки до 10 id).
     * Индекс: campaignId → sku → date → метрики. Дата `_` — строка без поля date.
     *
     * @param  list<int>  $campaignIds
     * @return array<int, array<int, array<string, array{views:int,clicks:int,spend:float,orders:int}>>>
     */
    public function fetchCabinetStatsSnapshots(string $accessToken, array $campaignIds): array
    {
        $campaignIds = array_values(array_unique(array_filter(
            array_map(static fn($id): int => (int) $id, $campaignIds),
            static fn(int $id): bool => $id > 0,
        )));
        if ($campaignIds === []) {
            return [];
        }

        $today = now('Europe/Moscow')->toDateString();
        $yesterday = now('Europe/Moscow')->subDay()->toDateString();
        $index = [];

        foreach (array_chunk($campaignIds, self::STATS_CAMPAIGN_CHUNK) as $chunk) {
            $response = $this->performanceApi->getProductSkuStatistics($accessToken, [
                'campaignIds' => array_map(static fn(int $id): string => (string) $id, $chunk),
                'dateFrom' => $yesterday,
                'dateTo' => $today,
            ]);
            if (! ($response['success'] ?? false)) {
                $this->logAdsStatsResponse($chunk, $yesterday, $today, $response, $index);

                throw new \RuntimeException(
                    $this->apiMessage($response, 'Не удалось получить статистику кампаний'),
                );
            }
            $this->mergeIndexedSkuStats($index, $response['data'] ?? null, $chunk);
            $this->logAdsStatsResponse($chunk, $yesterday, $today, $response, $index);
        }

        return $index;
    }

    /**
     * @return array{views:int,clicks:int,spend:float,orders:int}
     */
    public function fetchStatsSnapshot(
        string $accessToken,
        int $campaignId,
        int $sku,
        ?string $fromDate = null,
    ): array {
        $index = $this->fetchCabinetStatsSnapshots($accessToken, [$campaignId]);

        return $this->snapshotFromIndex($index, $campaignId, $sku, $fromDate);
    }

    /**
     * @return array{views:int,clicks:int,spend:float,orders:int}|null
     */
    public function extractSkuStats(mixed $data, int $campaignId, int $sku, ?string $fromDate = null): ?array
    {
        $rows = Arr::get($data, 'rows', $data);
        if (! is_array($rows)) {
            return null;
        }

        $index = [];
        $this->mergeIndexedSkuStats($index, $data, [$campaignId]);

        return $this->snapshotFromIndex($index, $campaignId, $sku, $fromDate);
    }

    /**
     * @param  array<int, array<int, array<string, array{views:int,clicks:int,spend:float,orders:int}>>>  $index
     * @param  list<int>  $fallbackCampaignIds
     */
    private function mergeIndexedSkuStats(array &$index, mixed $data, array $fallbackCampaignIds = []): void
    {
        $rows = Arr::get($data, 'rows', $data);
        if (! is_array($rows)) {
            return;
        }
        if (Arr::isAssoc($rows) && (isset($rows['sku']) || isset($rows['views']))) {
            $rows = [$rows];
        }

        $fallbackCampaignId = count($fallbackCampaignIds) === 1 ? (int) $fallbackCampaignIds[0] : 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rowSku = (int) ($row['sku'] ?? $row['skuId'] ?? $row['offerSku'] ?? 0);
            if ($rowSku <= 0) {
                continue;
            }
            $rowCampaign = (int) ($row['campaignId'] ?? $row['campaign_id'] ?? 0);
            if ($rowCampaign <= 0) {
                $rowCampaign = $fallbackCampaignId;
            }
            if ($rowCampaign <= 0) {
                continue;
            }

            $date = trim((string) ($row['date'] ?? ''));
            if ($date === '') {
                $date = '_';
            }

            if (! isset($index[$rowCampaign][$rowSku][$date])) {
                $index[$rowCampaign][$rowSku][$date] = $this->emptyStats();
            }
            $index[$rowCampaign][$rowSku][$date]['views'] += (int) round((float) ($row['views'] ?? 0));
            $index[$rowCampaign][$rowSku][$date]['clicks'] += (int) round((float) ($row['clicks'] ?? 0));
            $index[$rowCampaign][$rowSku][$date]['spend'] += (float) ($row['expense'] ?? $row['moneySpent'] ?? 0);
            $index[$rowCampaign][$rowSku][$date]['orders'] += (int) round((float) ($row['orders'] ?? 0));
        }
    }

    /**
     * @param  array<int, array<int, array<string, array{views:int,clicks:int,spend:float,orders:int}>>>  $index
     * @return array{views:int,clicks:int,spend:float,orders:int}
     */
    public function snapshotFromIndex(array $index, int $campaignId, int $sku, ?string $fromDate = null): array
    {
        $bySku = $index[$campaignId] ?? [];
        $dates = null;
        $usedSku = 0;
        if ($sku > 0 && isset($bySku[$sku])) {
            $dates = $bySku[$sku];
            $usedSku = $sku;
        } elseif (count($bySku) === 1) {
            $usedSku = (int) array_key_first($bySku);
            $dates = $bySku[$usedSku];
        }
        if (! is_array($dates) || $dates === []) {
            $this->photoLog('info', 'В ответе статистики нет строк этого товара', [
                'campaign_id' => $campaignId,
                'sku' => $sku,
                'from_date' => $fromDate,
                'response_skus' => array_map('intval', array_keys($bySku)),
            ]);

            return $this->emptyStats();
        }

        $sum = $this->emptyStats();
        $kept = [];
        $dropped = [];
        foreach ($dates as $date => $row) {
            $point = [
                'date' => (string) $date,
                'views' => (int) ($row['views'] ?? 0),
                'clicks' => (int) ($row['clicks'] ?? 0),
                'spend' => (float) ($row['spend'] ?? 0),
                'orders' => (int) ($row['orders'] ?? 0),
            ];
            if ($fromDate !== null && $date !== '_' && (string) $date < $fromDate) {
                $dropped[] = $point;

                continue;
            }
            $kept[] = $point;
            $sum['views'] += $point['views'];
            $sum['clicks'] += $point['clicks'];
            $sum['spend'] += $point['spend'];
            $sum['orders'] += $point['orders'];
        }

        $sum['spend'] = round($sum['spend'], 2);
        $this->photoLog('info', 'Снимок показов по датам', [
            'campaign_id' => $campaignId,
            'sku' => $sku,
            'from_date' => $fromDate,
            'used_sku' => $usedSku,
            'kept' => $kept,
            'dropped_before_from' => $dropped,
            'sum' => $sum,
        ]);

        return $sum;
    }

    /**
     * @return array{views:int,clicks:int,spend:float,orders:int}
     */
    private function emptyStats(): array
    {
        return ['views' => 0, 'clicks' => 0, 'spend' => 0.0, 'orders' => 0];
    }

    /**
     * @param  array{views:int,clicks:int,spend:float,orders:int}  $snapshot
     */
    private function isEmptySnapshot(array $snapshot): bool
    {
        return (int) $snapshot['views'] === 0
            && (int) $snapshot['clicks'] === 0
            && (float) $snapshot['spend'] <= 0
            && (int) $snapshot['orders'] === 0;
    }

    /**
     * Пустой ответ API не затирает уже накопленный снимок цикла.
     *
     * @param  array{views:int,clicks:int,spend:float,orders:int}  $snapshot
     * @return array{views:int,clicks:int,spend:float,orders:int}
     */
    /**
     * Старт круга — ноль, либо конец предыдущего круга.
     * Нельзя подтягивать его к текущему снимку: дельта тогда всегда ноль.
     */
    private function anchorFirstCycleBaseline(AbExperiment $experiment, AbExperimentCycle $cycle): void
    {
        $previous = AbExperimentCycle::query()
            ->where('ab_experiment_id', $experiment->id)
            ->where('id', '!=', $cycle->id)
            ->where('sequence', '<', (int) $cycle->sequence)
            ->orderByDesc('sequence')
            ->first();

        $views = $previous ? (int) $previous->views_end : 0;
        $clicks = $previous ? (int) $previous->clicks_end : 0;
        $spend = $previous ? (float) $previous->spend_end : 0.0;
        $orders = $previous ? (int) $previous->orders_end : 0;

        if (
            (int) $cycle->views_start === $views
            && (int) $cycle->clicks_start === $clicks
            && (float) $cycle->spend_start == $spend
            && (int) $cycle->orders_start === $orders
        ) {
            return;
        }
        if ((int) $cycle->views_start <= $views && (int) $cycle->clicks_start <= $clicks) {
            return;
        }

        $cycle->views_start = $views;
        $cycle->clicks_start = $clicks;
        $cycle->spend_start = $spend;
        $cycle->orders_start = $orders;
        $cycle->save();
        $this->photoLog('info', 'Старт круга возвращён к сумме предыдущего круга', [
            'experiment_id' => (int) $experiment->id,
            'cycle_id' => (int) $cycle->id,
            'views_start' => $views,
            'clicks_start' => $clicks,
        ]);
    }

    private function monotonicSnapshot(AbExperimentCycle $cycle, array $snapshot): array
    {
        if (! $this->isEmptySnapshot($snapshot)) {
            return $snapshot;
        }
        if ($cycle->views_end === null || (int) $cycle->views_end <= 0) {
            return $snapshot;
        }

        return [
            'views' => (int) $cycle->views_end,
            'clicks' => (int) ($cycle->clicks_end ?? 0),
            'spend' => (float) ($cycle->spend_end ?? 0),
            'orders' => (int) ($cycle->orders_end ?? 0),
        ];
    }

    private function cycleStatsFromDate(AbExperiment $experiment): string
    {
        $cycle = $experiment->resolveOpenCycle();
        $started = $cycle?->started_at ?? $experiment->started_at;
        if ($started) {
            return $started->copy()->timezone('Europe/Moscow')->toDateString();
        }

        return now('Europe/Moscow')->toDateString();
    }

    private function ensureCabinetTickScheduled(int $cabinetId, int $justStartedExperimentId): void
    {
        $othersRunning = AbExperiment::query()
            ->where('cabinet_id', $cabinetId)
            ->where('status', OzAbTestStatus::Running->value)
            ->where('id', '!=', $justStartedExperimentId)
            ->exists();

        if ($othersRunning) {
            return;
        }

        ProcessOzAbCabinetTickJob::dispatchFor($cabinetId);
    }

    /**
     * @return array{success: bool, message?: string}
     */
    /**
     * @return array{success: bool, message?: string, meta?: array<string, mixed>}
     */
    public function uploadPhotoAsMain(OzCabinet $cabinet, AbExperiment $experiment, AbExperimentPhoto $photo): array
    {
        $product = $experiment->relationLoaded('product')
            ? $experiment->product
            : AbProduct::query()->find($experiment->ab_product_id);
        if (! $product) {
            return ['success' => false, 'message' => 'Товар эксперимента не найден'];
        }

        $url = $this->publicPhotoUrl($photo);
        if ($url === null) {
            $this->photoLog('warning', 'Нет публичной ссылки на фото варианта', [
                'experiment_id' => $experiment->id,
                'cabinet_id' => $cabinet->id,
                'photo_id' => $photo->id,
                'disk' => $photo->disk,
                'path' => $photo->path,
            ]);

            return ['success' => false, 'message' => 'Нет публичной ссылки на фотографию'];
        }

        $snapshot = is_array($experiment->gallery_snapshot) ? $experiment->gallery_snapshot : [];
        $productId = (int) $product->oz_product_id;
        $offerId = trim((string) $product->offer_id);
        if ($offerId === '') {
            return ['success' => false, 'message' => 'У товара нет артикула, маркетплейс не принимает фото без него.'];
        }
        $payload = $this->picturesImportPayload($offerId, $url, $snapshot);
        $item = $payload['items'][0] ?? [];
        $previousPrimary = $this->firstImageUrl($snapshot['primary_image'] ?? null);

        $context = [
            'experiment_id' => (int) $experiment->id,
            'cabinet_id' => (int) $cabinet->id,
            'product_id' => $productId,
            'offer_id' => $offerId,
            'sku' => (int) ($experiment->sku ?: $product->sku),
            'photo_id' => (int) $photo->id,
            'sort_order' => (int) $photo->sort_order,
            'public_url' => $url,
            'public_url_reachable' => $this->isPubliclyFetchableUrl($url),
            'previous_primary' => $previousPrimary,
            'images' => $item['images'] ?? [],
            'images360' => $item['images360'] ?? [],
            'color_image' => $item['color_image'] ?? null,
        ];

        $this->photoLog('info', 'Отправка главного фото в карточку', $context);

        if (! $context['public_url_reachable']) {
            $this->photoLog('warning', 'Ссылка на фото недоступна из интернета', $context);

            return [
                'success' => false,
                'message' => 'Ссылка на фотографию недоступна из интернета, маркетплейс не может её скачать.',
                'meta' => $this->photoJournalMeta($context, null, null, false),
            ];
        }

        $response = $this->sellerApi->importProductPictures(
            (string) $cabinet->apikey,
            (string) $cabinet->client_id,
            $payload,
        );
        $importStatus = (int) ($response['status'] ?? 0);
        $this->photoLog('info', 'Ответ загрузки фото карточки', $context + [
            'import_status' => $importStatus,
            'import_success' => (bool) ($response['success'] ?? false),
            'import_body' => $this->clipForLog($response['data'] ?? null),
        ]);

        if (! ($response['success'] ?? false)) {
            return [
                'success' => false,
                'message' => $this->apiMessage($response, 'Не удалось загрузить фотографию в карточку'),
                'meta' => $this->photoJournalMeta($context, $importStatus, null, false),
            ];
        }

        return $this->waitPicturesUploaded($cabinet, $productId, $url, $previousPrimary, $context, $importStatus);
    }

    public function restoreGallery(OzCabinet $cabinet, AbExperiment $experiment): void
    {
        $snapshot = is_array($experiment->gallery_snapshot) ? $experiment->gallery_snapshot : [];
        $product = $experiment->relationLoaded('product')
            ? $experiment->product
            : AbProduct::query()->find($experiment->ab_product_id);
        if (! $product || $snapshot === []) {
            $this->photoLog('warning', 'Возврат галереи пропущен: нет товара или снимка', [
                'experiment_id' => $experiment->id,
                'cabinet_id' => $cabinet->id,
                'has_product' => (bool) $product,
                'has_snapshot' => $snapshot !== [],
            ]);

            return;
        }

        $images = $this->extractImageUrls($snapshot['images'] ?? []);
        $primary = $this->firstImageUrl($snapshot['primary_image'] ?? ($images[0] ?? ''));
        if ($primary === '') {
            $this->photoLog('warning', 'Возврат галереи пропущен: нет исходного главного фото', [
                'experiment_id' => $experiment->id,
                'product_id' => (int) $product->oz_product_id,
            ]);

            return;
        }

        $offerId = trim((string) $product->offer_id);
        if ($offerId === '') {
            $this->photoLog('warning', 'Возврат галереи пропущен: нет артикула товара', [
                'experiment_id' => (int) $experiment->id,
                'product_id' => (int) $product->oz_product_id,
            ]);

            return;
        }
        $payload = $this->picturesImportPayload($offerId, $primary, $snapshot);
        $item = $payload['items'][0] ?? [];
        $this->photoLog('info', 'Возврат исходной галереи', [
            'experiment_id' => (int) $experiment->id,
            'cabinet_id' => (int) $cabinet->id,
            'product_id' => (int) $product->oz_product_id,
            'offer_id' => $offerId,
            'public_url' => $primary,
            'images' => $item['images'] ?? [],
        ]);

        $response = $this->sellerApi->importProductPictures(
            (string) $cabinet->apikey,
            (string) $cabinet->client_id,
            $payload,
        );
        $this->photoLog('info', 'Ответ возврата галереи', [
            'experiment_id' => (int) $experiment->id,
            'product_id' => (int) $product->oz_product_id,
            'import_status' => (int) ($response['status'] ?? 0),
            'import_success' => (bool) ($response['success'] ?? false),
            'import_body' => $this->clipForLog($response['data'] ?? null),
        ]);
    }

    /**
     * POST /v2/product/pictures/import: items из 1–100 товаров, товар определяется offer_id.
     * product_id в элемент не входит. primary_image — строка, не массив.
     * В images только остальные кадры, не больше 29, без дубля главного.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{items: list<array<string, mixed>>}
     */
    private function picturesImportPayload(string $offerId, string $mainUrl, array $snapshot): array
    {
        $previousPrimary = $this->firstImageUrl($snapshot['primary_image'] ?? null);
        $gallery = $this->extractImageUrls($snapshot['images'] ?? []);
        $rest = [];
        foreach (array_merge($previousPrimary !== '' ? [$previousPrimary] : [], $gallery) as $url) {
            if ($url === '' || $url === $mainUrl || in_array($url, $rest, true)) {
                continue;
            }
            $rest[] = $url;
        }

        $item = [
            'offer_id' => $offerId,
            'primary_image' => $mainUrl,
            'images' => array_slice($rest, 0, 29),
        ];
        $images360 = $this->extractImageUrls($snapshot['images360'] ?? []);
        if ($images360 !== []) {
            $item['images360'] = $images360;
        }
        $color = $this->firstImageUrl($snapshot['color_image'] ?? null);
        if ($color !== '') {
            $item['color_image'] = $color;
        }

        return ['items' => [$item]];
    }

    /**
     * @return array<string, mixed>
     */
    public function captureGallerySnapshot(OzCabinet $cabinet, int $ozProductId): array
    {
        $info = $this->sellerApi->getProductsInfo(
            (string) $cabinet->apikey,
            (string) $cabinet->client_id,
            [$ozProductId],
        );
        $items = Arr::get($info, 'data.items', Arr::get($info, 'data.result.items', []));
        if (! is_array($items) || $items === []) {
            return [];
        }
        $item = $items[0] ?? [];
        if (! is_array($item)) {
            return [];
        }

        $images = $this->extractImageUrls($item['images'] ?? []);
        $images360 = $this->extractImageUrls($item['images360'] ?? []);
        $primary = $this->firstImageUrl($item['primary_image'] ?? null);
        if ($primary === '') {
            $primary = $images[0] ?? '';
        }

        $color = $this->firstImageUrl($item['color_image'] ?? null);

        return [
            'primary_image' => $primary,
            'images' => $images,
            'images360' => $images360,
            'color_image' => $color !== '' ? $color : null,
        ];
    }

    /**
     * @param  mixed  $images
     * @return list<string>
     */
    private function extractImageUrls(mixed $images): array
    {
        $urls = [];
        foreach ((array) $images as $image) {
            $url = $this->firstImageUrl($image);
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Seller API: primary_image / images часто массив URL, а не строка.
     */
    private function firstImageUrl(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (! is_array($value)) {
            return '';
        }

        foreach ($value as $item) {
            $nested = $this->firstImageUrl(
                is_array($item) ? ($item['file_name'] ?? $item['url'] ?? $item) : $item
            );
            if ($nested !== '') {
                return $nested;
            }
        }

        return '';
    }

    public function publicPhotoUrl(AbExperimentPhoto $photo): ?string
    {
        $disk = (string) ($photo->disk ?: self::PHOTO_DISK);
        $path = (string) $photo->path;
        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            return null;
        }

        if ($disk === 'public') {
            $relative = Storage::disk('public')->url($path);

            if (Str::startsWith($relative, ['http://', 'https://'])) {
                return $relative;
            }

            $base = rtrim((string) config('app.url', url('/')), '/');

            return $base . '/' . ltrim($relative, '/');
        }

        return url()->route('subscriber.oz.ab-testing.media.show', ['photo' => $photo->id]);
    }

    /**
     * @return array{impressions_per_photo:int,impressions_per_round:int,round_minutes:int}
     */
    public function settingsOf(AbExperiment $experiment): array
    {
        return [
            'impressions_per_photo' => (int) ($experiment->impressions_per_photo ?: 100000),
            'impressions_per_round' => (int) ($experiment->impressions_per_round ?: 10000),
            'round_minutes' => (int) ($experiment->round_minutes ?: 30),
        ];
    }

    public function areSettingsReady(AbExperiment $experiment): bool
    {
        return $experiment->impressions_per_photo !== null
            && $experiment->impressions_per_round !== null
            && $experiment->round_minutes !== null
            && (int) $experiment->round_minutes >= 30;
    }

    public function hasPerformanceCredentials(OzCabinet $cabinet): bool
    {
        return trim((string) $cabinet->performance_client_id) !== ''
            && trim((string) $cabinet->performance_client_secret) !== '';
    }

    /**
     * @return array{token: ?string, error: ?string}
     */
    public function accessTokenResult(OzCabinet $cabinet): array
    {
        if (! $this->hasPerformanceCredentials($cabinet)) {
            return ['token' => null, 'error' => self::MSG_MISSING_PERFORMANCE_CREDENTIALS];
        }

        if ($this->cachedAccessToken !== null && $this->cachedAccessTokenCabinetId === (int) $cabinet->id) {
            return ['token' => $this->cachedAccessToken, 'error' => null];
        }

        $response = $this->performanceApi->getAccessToken(
            (string) $cabinet->performance_client_id,
            (string) $cabinet->performance_client_secret,
        );
        $token = (string) Arr::get($response, 'data.access_token', '');
        if ($token === '') {
            return ['token' => null, 'error' => $this->performanceAuthMessage($response)];
        }

        $this->cachedAccessToken = $token;
        $this->cachedAccessTokenCabinetId = (int) $cabinet->id;

        return ['token' => $token, 'error' => null];
    }

    public function accessToken(OzCabinet $cabinet): ?string
    {
        return $this->accessTokenResult($cabinet)['token'];
    }

    /**
     * @param  array{success?: bool, status?: int, data?: mixed}  $response
     */
    public function performanceAuthMessage(array $response): string
    {
        $status = (int) ($response['status'] ?? 0);
        $data = $response['data'] ?? null;
        $error = is_array($data) ? (string) ($data['error'] ?? '') : '';

        if ($status === 401 || $error === 'invalid_client') {
            return self::MSG_INVALID_PERFORMANCE_CREDENTIALS;
        }

        if ($status === 429 || $this->isPerformanceRateLimitError($error)) {
            return self::MSG_PERFORMANCE_RATE_LIMITED;
        }

        return self::MSG_PERFORMANCE_TOKEN_FAILED;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findCampaign(string $accessToken, int $campaignId): ?array
    {
        $response = $this->performanceApi->listCampaigns($accessToken, [
            'campaignIds' => $campaignId,
        ]);
        if (! ($response['success'] ?? false)) {
            return null;
        }

        $list = Arr::get($response, 'data.list', []);
        if (! is_array($list)) {
            return null;
        }
        if (Arr::isAssoc($list) && isset($list['id'])) {
            $list = [$list];
        }
        foreach ($list as $item) {
            if (is_array($item) && (int) ($item['id'] ?? 0) === $campaignId) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $campaign
     * @return list<int>
     */
    public function extractCampaignSkus(
        string $accessToken,
        int $campaignId,
        array $campaign = [],
        bool $fetchObjects = true,
    ): array {
        $skus = [];
        foreach ((array) Arr::get($campaign, 'products', []) as $product) {
            if (is_array($product) && isset($product['sku'])) {
                $skus[] = (int) $product['sku'];
            } elseif (is_numeric($product)) {
                $skus[] = (int) $product;
            }
        }

        if (! $fetchObjects) {
            return array_values(array_unique(array_filter($skus)));
        }

        $objects = $this->performanceApi->getCampaignObjects($accessToken, $campaignId);
        $list = Arr::get($objects, 'data.list', Arr::get($objects, 'data.skus', Arr::get($objects, 'data', [])));
        if (is_array($list)) {
            foreach ($list as $item) {
                if (is_array($item)) {
                    $sku = (int) ($item['sku'] ?? $item['id'] ?? 0);
                    if ($sku > 0) {
                        $skus[] = $sku;
                    }
                } elseif (is_numeric($item)) {
                    $skus[] = (int) $item;
                }
            }
        }

        $skus = array_values(array_unique(array_filter($skus)));

        return $skus;
    }

    /**
     * @param  array<string, mixed>  $campaign
     * @return array{success: bool, added: bool, already: bool, message?: string}
     */
    private function ensureCampaignContainsSku(
        string $accessToken,
        int $campaignId,
        int $sku,
        array $campaign = [],
    ): array {
        $knownSkus = $this->extractCampaignSkus($accessToken, $campaignId, $campaign);
        if (in_array($sku, $knownSkus, true)) {
            return ['success' => true, 'added' => false, 'already' => true];
        }

        $attempts = [
            ['bids' => [['sku' => (string) $sku]]],
        ];

        $lastResponse = null;
        foreach ($attempts as $payload) {
            $lastResponse = $this->performanceApi->addCampaignProducts($accessToken, $campaignId, $payload);
            if (! ($lastResponse['success'] ?? false)) {
                continue;
            }

            for ($i = 0; $i < 4; $i++) {
                if ($i > 0) {
                    usleep(350_000);
                }
                $freshSkus = $this->extractCampaignSkus($accessToken, $campaignId);
                if (in_array($sku, $freshSkus, true)) {
                    return ['success' => true, 'added' => true, 'already' => false];
                }
            }
        }

        $freshSkus = $this->extractCampaignSkus($accessToken, $campaignId);
        if (in_array($sku, $freshSkus, true)) {
            return ['success' => true, 'added' => true, 'already' => false];
        }

        if (is_array($lastResponse)) {
            return [
                'success' => false,
                'added' => false,
                'already' => false,
                'message' => $this->apiMessage($lastResponse, 'Товар не удалось добавить в рекламную кампанию'),
            ];
        }

        return [
            'success' => false,
            'added' => false,
            'already' => false,
            'message' => 'Товар не удалось добавить в рекламную кампанию',
        ];
    }

    public function resolveStatus(AbExperiment $experiment): ?OzAbTestStatus
    {
        return $experiment->status instanceof OzAbTestStatus
            ? $experiment->status
            : OzAbTestStatus::tryFrom((string) $experiment->status);
    }

    /**
     * @param  array{success?: bool, status?: int, data?: mixed}  $response
     */
    public function apiMessage(array $response, string $fallback): string
    {
        $status = (int) ($response['status'] ?? 0);
        $data = $response['data'] ?? null;
        $raw = '';
        if (is_array($data)) {
            $candidate = $data['message'] ?? $data['error'] ?? $data['errorMessage'] ?? null;
            $raw = is_string($candidate) ? $candidate : '';
        }

        if ($status === 429 || $this->isPerformanceRateLimitError($raw)) {
            return self::MSG_PERFORMANCE_RATE_LIMITED;
        }

        if ($raw !== '') {
            return $raw;
        }

        return $fallback;
    }

    private function isPerformanceRateLimitError(string $message): bool
    {
        $normalized = mb_strtolower($message);

        return $normalized === 'rate_limited'
            || str_contains($normalized, 'лимит активных запросов')
            || str_contains($normalized, 'too many requests');
    }

    /**
     * Разбор ответа pictures/info: uploaded / failed / pending.
     *
     * @param  array<string, mixed>  $info
     */
    public function interpretPicturesInfo(array $info, bool $afterInitialWait = false): string
    {
        if (! ($info['success'] ?? false)) {
            return 'pending';
        }

        $states = [];
        $errors = [];
        $hasUrls = false;
        $this->collectPictureStates(Arr::get($info, 'data', []), $states, $errors, $hasUrls);

        if ($errors !== []) {
            return 'failed';
        }

        $normalized = array_values(array_unique(array_filter($states)));
        if (in_array('failed', $normalized, true) || in_array('error', $normalized, true) || in_array('rejected', $normalized, true)) {
            return 'failed';
        }
        if (in_array('uploaded', $normalized, true) && array_intersect($normalized, ['imported', 'processing', 'pending', 'downloading']) === []) {
            return 'uploaded';
        }
        if ($normalized === []) {
            // v2 часто отдаёт URL без state — после паузы принимаем как обработанные.
            return ($afterInitialWait && $hasUrls) ? 'uploaded' : 'pending';
        }

        return 'pending';
    }

    /**
     * @param  mixed  $node
     * @param  list<string>  $states
     * @param  list<string>  $errors
     */
    private function collectPictureStates(mixed $node, array &$states, array &$errors, bool &$hasUrls): void
    {
        if (is_string($node)) {
            if (str_starts_with($node, 'http://') || str_starts_with($node, 'https://')) {
                $hasUrls = true;
            }

            return;
        }
        if (! is_array($node)) {
            return;
        }

        if (isset($node['errors']) && is_array($node['errors']) && $node['errors'] !== []) {
            foreach ($node['errors'] as $error) {
                if (is_string($error) && $error !== '') {
                    $errors[] = $error;
                } elseif (is_array($error)) {
                    $text = trim((string) ($error['description'] ?? $error['message'] ?? $error['error'] ?? ''));
                    if ($text !== '') {
                        $errors[] = $text;
                    }
                }
            }
        }

        if (isset($node['state']) && is_string($node['state']) && $node['state'] !== '') {
            $states[] = strtolower($node['state']);
        }
        foreach (['url', 'file_name'] as $urlKey) {
            if (isset($node[$urlKey]) && is_string($node[$urlKey]) && $node[$urlKey] !== '') {
                $hasUrls = true;
            }
        }

        foreach (['pictures', 'items', 'primary_photo', 'photo', 'photos', 'color_photo', 'photo_360', 'result'] as $key) {
            if (isset($node[$key]) && is_array($node[$key])) {
                if ($this->isListArray($node[$key])) {
                    foreach ($node[$key] as $child) {
                        $this->collectPictureStates($child, $states, $errors, $hasUrls);
                    }
                } else {
                    $this->collectPictureStates($node[$key], $states, $errors, $hasUrls);
                }
            }
        }
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
     * @param  array<string, mixed>  $context
     * @return array{success: bool, message?: string, meta: array<string, mixed>}
     */
    private function waitPicturesUploaded(
        OzCabinet $cabinet,
        int $ozProductId,
        string $expectedUrl,
        string $previousPrimary,
        array $context,
        int $importStatus,
    ): array {
        $startedAt = microtime(true);
        $deadline = $startedAt + $this->picturesWaitMaxSeconds();
        $waitedInitial = false;
        $lastVerdict = 'pending';
        $lastSummary = ['primary' => [], 'photos' => [], 'errors' => []];

        while (true) {
            $info = $this->sellerApi->getProductPicturesInfo(
                (string) $cabinet->apikey,
                (string) $cabinet->client_id,
                [$ozProductId],
            );
            $lastVerdict = $this->interpretPicturesInfo($info, $waitedInitial);
            $lastSummary = $this->summarizePicturesInfo($info);
            $elapsed = (int) round(microtime(true) - $startedAt);

            $this->photoLog('info', 'Статус фото карточки', $context + [
                'elapsed_seconds' => $elapsed,
                'verdict' => $lastVerdict,
                'primary_photo' => $lastSummary['primary'],
                'photo' => $lastSummary['photos'],
                'errors' => $lastSummary['errors'],
                'info_body' => $this->clipForLog($info['data'] ?? null),
            ]);

            if ($lastVerdict === 'failed') {
                $this->photoLog('warning', 'Маркетплейс отклонил фото карточки', $context + [
                    'errors' => $lastSummary['errors'],
                ]);

                return [
                    'success' => false,
                    'message' => 'Ozon не принял фотографию карточки. Проверьте, что файл открывается по ссылке из интернета.',
                    'meta' => $this->photoJournalMeta($context, $importStatus, $lastSummary, false),
                ];
            }

            if ($lastVerdict === 'uploaded') {
                $decision = $this->decidePictureApplied($expectedUrl, $previousPrimary, $lastSummary);
                $this->photoLog('info', $decision === 'unchanged'
                    ? 'Файл принят, на карточке пока прежнее фото — ждём модерацию'
                    : ($decision === 'applied'
                        ? 'Главное фото карточки обновлено'
                        : 'Статус загрузки есть, главное фото в ответе пустое — сравнить не с чем'), $context + [
                            'decision' => $decision,
                            'primary_photo' => $lastSummary['primary'],
                        ]);

                return [
                    'success' => true,
                    'meta' => $this->photoJournalMeta($context, $importStatus, $lastSummary, $decision === 'applied'),
                ];
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            $sleepFor = $waitedInitial
                ? $this->picturesPollIntervalSeconds()
                : $this->picturesWaitInitialSeconds();
            $waitedInitial = true;
            if ($sleepFor > 0) {
                sleep($sleepFor);
            } else {
                break;
            }
        }

        $this->photoLog('warning', 'Истекло ожидание обработки фото карточки', $context + [
            'verdict' => $lastVerdict,
            'primary_photo' => $lastSummary['primary'],
        ]);

        return [
            'success' => false,
            'message' => $lastVerdict === 'pending'
                ? 'Ozon не успел обработать фотографию карточки. Повторите запуск через минуту.'
                : 'Ozon не принял фотографию карточки. Проверьте, что файл открывается по ссылке из интернета.',
            'meta' => $this->photoJournalMeta($context, $importStatus, $lastSummary, false),
        ];
    }

    /**
     * applied — главное сменилось или в ответе есть наш URL как главное.
     * unchanged — главное совпало со снимком, нашего URL нет.
     * unknown — список главного пуст, сравнивать нечего.
     *
     * @param  array{primary: list<string>, photos: list<string>, errors: list<string>}  $summary
     */
    private function decidePictureApplied(string $expectedUrl, string $previousPrimary, array $summary): string
    {
        $primary = $summary['primary'][0] ?? '';
        if ($primary === '') {
            return 'unknown';
        }

        $primaryKey = $this->pictureUrlKey($primary);
        if ($this->pictureUrlKey($expectedUrl) !== '' && $primaryKey === $this->pictureUrlKey($expectedUrl)) {
            return 'applied';
        }

        $previousKey = $this->pictureUrlKey($previousPrimary);
        if ($previousKey !== '' && $primaryKey === $previousKey) {
            return 'unchanged';
        }

        return 'applied';
    }

    /**
     * @param  array<string, mixed>  $info
     * @return array{primary: list<string>, photos: list<string>, errors: list<string>}
     */
    private function summarizePicturesInfo(array $info): array
    {
        $primary = [];
        $photos = [];
        $errors = [];
        $this->collectPictureUrls(Arr::get($info, 'data', []), $primary, $photos, $errors, false);

        return [
            'primary' => array_values(array_unique($primary)),
            'photos' => array_values(array_unique($photos)),
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * @param  list<string>  $primary
     * @param  list<string>  $photos
     * @param  list<string>  $errors
     */
    private function collectPictureUrls(mixed $node, array &$primary, array &$photos, array &$errors, bool $asPrimary): void
    {
        if (is_string($node)) {
            $url = trim($node);
            if ($url === '' || (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://'))) {
                return;
            }
            if ($asPrimary) {
                $primary[] = $url;
            } else {
                $photos[] = $url;
            }

            return;
        }
        if (! is_array($node)) {
            return;
        }

        if ($this->isListArray($node)) {
            foreach ($node as $child) {
                $this->collectPictureUrls($child, $primary, $photos, $errors, $asPrimary);
            }

            return;
        }

        if (isset($node['errors']) && is_array($node['errors'])) {
            foreach ($node['errors'] as $error) {
                if (is_string($error) && $error !== '') {
                    $errors[] = $error;
                } elseif (is_array($error)) {
                    $text = trim((string) ($error['description'] ?? $error['message'] ?? $error['error'] ?? ''));
                    if ($text !== '') {
                        $errors[] = $text;
                    }
                }
            }
        }

        $nodeIsPrimary = $asPrimary || (($node['is_primary'] ?? false) === true);
        foreach (['url', 'file_name'] as $urlKey) {
            if (isset($node[$urlKey]) && is_string($node[$urlKey]) && $nodeIsPrimary) {
                $url = trim($node[$urlKey]);
                if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
                    $primary[] = $url;
                }
            }
        }

        foreach (['primary_photo', 'primary_image'] as $key) {
            if (isset($node[$key])) {
                $this->collectPictureUrls($node[$key], $primary, $photos, $errors, true);
            }
        }
        foreach (['pictures', 'items', 'photo', 'photos', 'color_photo', 'photo_360', 'result'] as $key) {
            if (! isset($node[$key]) || ! is_array($node[$key])) {
                continue;
            }
            if ($this->isListArray($node[$key])) {
                foreach ($node[$key] as $child) {
                    $this->collectPictureUrls($child, $primary, $photos, $errors, $asPrimary);
                }
            } else {
                $this->collectPictureUrls($node[$key], $primary, $photos, $errors, $asPrimary);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array{primary: list<string>, photos: list<string>, errors: list<string>}|null  $summary
     * @return array<string, mixed>
     */
    private function photoJournalMeta(array $context, ?int $importStatus, ?array $summary, bool $changed): array
    {
        return [
            'product_id' => $context['product_id'] ?? null,
            'public_url' => $context['public_url'] ?? null,
            'public_url_reachable' => $context['public_url_reachable'] ?? null,
            'previous_primary' => $context['previous_primary'] ?? null,
            'primary_after' => $summary['primary'][0] ?? null,
            'images' => $context['images'] ?? [],
            'import_status' => $importStatus,
            'changed' => $changed,
        ];
    }

    private function pictureUrlKey(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return $url;
        }

        return strtolower((string) $parts['host']).($parts['path'] ?? '');
    }

    private function isPubliclyFetchableUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }

    /**
     * @param  list<int>  $campaignIds
     * @param  array{success?: bool, status?: int, data?: mixed}  $response
     * @param  array<int, array<int, array<string, array{views:int,clicks:int,spend:float,orders:int}>>>  $index
     */
    private function logAdsStatsResponse(array $campaignIds, string $from, string $to, array $response, array $index): void
    {
        $data = $response['data'] ?? null;
        $rows = $this->statsRows($data);
        $compact = [];
        foreach ($rows as $row) {
            if (count($compact) >= 40) {
                break;
            }
            $compact[] = [
                'sku' => $row['sku'] ?? $row['skuId'] ?? $row['offerSku'] ?? null,
                'campaign_id' => $row['campaignId'] ?? $row['campaign_id'] ?? null,
                'date' => $row['date'] ?? null,
                'views' => $row['views'] ?? null,
                'clicks' => $row['clicks'] ?? null,
                'orders' => $row['orders'] ?? null,
                'expense' => $row['expense'] ?? $row['moneySpent'] ?? null,
                'keys' => array_keys($row),
            ];
        }

        $this->photoLog(
            ($response['success'] ?? false) ? 'info' : 'warning',
            'Ответ статистики рекламы',
            [
                'campaign_ids' => $campaignIds,
                'date_from' => $from,
                'date_to' => $to,
                'http_status' => $response['status'] ?? null,
                'top_keys' => is_array($data) ? array_keys($data) : [gettype($data)],
                'row_count' => count($rows),
                'rows' => $compact,
                'parsed_views' => $this->parsedViewsSummary($index, $campaignIds),
                'body_sample' => $rows === [] ? $this->clipForLog($data) : null,
            ],
        );
    }

    /**
     * Строки в том же виде, в каком их разбирает mergeIndexedSkuStats.
     *
     * @return list<array<string, mixed>>
     */
    private function statsRows(mixed $data): array
    {
        $rows = Arr::get($data, 'rows', $data);
        if (! is_array($rows)) {
            return [];
        }
        if (Arr::isAssoc($rows) && (isset($rows['sku']) || isset($rows['views']))) {
            return [$rows];
        }

        $list = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $list[] = $row;
            }
        }

        return $list;
    }

    /**
     * @param  array<int, array<int, array<string, array{views:int,clicks:int,spend:float,orders:int}>>>  $index
     * @param  list<int>  $campaignIds
     * @return list<array{campaign_id:int, sku:int, date:string, views:int, clicks:int}>
     */
    private function parsedViewsSummary(array $index, array $campaignIds): array
    {
        $summary = [];
        foreach ($campaignIds as $campaignId) {
            foreach ($index[(int) $campaignId] ?? [] as $sku => $dates) {
                if (! is_array($dates)) {
                    continue;
                }
                foreach ($dates as $date => $row) {
                    $summary[] = [
                        'campaign_id' => (int) $campaignId,
                        'sku' => (int) $sku,
                        'date' => (string) $date,
                        'views' => (int) ($row['views'] ?? 0),
                        'clicks' => (int) ($row['clicks'] ?? 0),
                    ];
                }
            }
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function photoLog(string $level, string $message, array $context = []): void
    {
        Log::channel('oz_ab_photos')->log($level, '[OzAbPhoto] '.$message, $context);
    }

    private function clipForLog(mixed $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            return '';
        }

        return mb_strlen($json) > 8000 ? mb_substr($json, 0, 8000).'…' : $json;
    }

    private function picturesWaitInitialSeconds(): int
    {
        return app()->runningUnitTests() ? 0 : self::PICTURES_WAIT_INITIAL_SECONDS;
    }

    private function picturesPollIntervalSeconds(): int
    {
        return app()->runningUnitTests() ? 0 : self::PICTURES_POLL_INTERVAL_SECONDS;
    }

    private function picturesWaitMaxSeconds(): int
    {
        return app()->runningUnitTests() ? 0 : self::PICTURES_WAIT_MAX_SECONDS;
    }

    private function safeDeactivate(string $accessToken, int $campaignId): void
    {
        try {
            $this->performanceApi->deactivateCampaign($accessToken, $campaignId);
        } catch (Throwable) {
            // ignore
        }
    }
}
