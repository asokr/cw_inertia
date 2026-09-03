<?php

namespace App\Services\Subscriber\Oz\AbTesting;

use App\Enums\OzAbTestStatus;
use App\Jobs\Oz\AbTesting\ProcessOzAbCabinetTickJob;
use App\Models\Subscribers\Oz\AbTesting\AbExperiment;
use App\Models\Subscribers\Oz\AbTesting\AbExperimentCycle;
use App\Models\Subscribers\Oz\AbTesting\AbExperimentPhoto;
use App\Models\Subscribers\Oz\AbTesting\AbProduct;
use App\Models\Subscribers\Oz\OzCabinet;
use App\Services\Ozon\OzonApiService;
use App\Services\Ozon\OzonPerformanceApiService;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
    ) {
    }

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
            $errors[] = 'По этому товару уже запущен эксперимент «'.$running->name.'».';
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
                $errors[] = 'Эта кампания уже используется в запущенном эксперименте «'.$busy->name
                    .'». Дождитесь завершения или остановите его.';
            }
        }

        foreach ($photos as $photo) {
            $disk = (string) ($photo->disk ?: self::PHOTO_DISK);
            $path = (string) $photo->path;
            if ($path === '' || ! Storage::disk($disk)->exists($path)) {
                $errors[] = 'Файл фотографии #'.((int) $photo->sort_order + 1).' недоступен на диске.';
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
            return [
                'success' => false,
                'messages' => ['Товар не входит в рекламную кампанию. Добавьте его перед запуском.'],
            ];
        }

        $photos = $experiment->photos->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values();
        /** @var AbExperimentPhoto $firstPhoto */
        $firstPhoto = $photos->first();

        $campaignStarted = false;

        try {
            $snapshotGallery = $this->captureGallerySnapshot($cabinet, $ozProductId);
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
                ['photo_id' => $firstPhoto->id, 'sort_order' => $firstPhoto->sort_order],
            );

            $stats = $this->fetchStatsSnapshot($token, $campaignId, $sku);

            $experiment = DB::transaction(function () use ($experiment, $firstPhoto, $stats) {
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
                    'views_start' => $stats['views'],
                    'clicks_start' => $stats['clicks'],
                    'spend_start' => $stats['spend'],
                    'orders_start' => $stats['orders'],
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
                'Открыт цикл №'.$cycleSeq.' эксперимента.',
                ['cycle_id' => $openedCycle?->id, 'photo_id' => $firstPhoto->id, 'sequence' => $cycleSeq],
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
                'messages' => ['Не удалось запустить эксперимент: '.$e->getMessage()],
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
                $snapshot = $this->fetchStatsSnapshot($token, $campaignId, $sku);
            } catch (Throwable) {
                // zeros
            }
        }

        DB::transaction(function () use ($experiment, $snapshot) {
            /** @var AbExperiment $locked */
            $locked = AbExperiment::query()->whereKey($experiment->id)->lockForUpdate()->firstOrFail();
            $cycle = $locked->resolveOpenCycle();
            if ($cycle) {
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
            ->map(fn (AbExperiment $experiment) => (int) $experiment->oz_campaign_id)
            ->filter(fn (int $id) => $id > 0)
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
            $snapshot = $this->snapshotFromIndex($index, $campaignId, $sku);
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
            $snapshot = $prefetchedSnapshot ?? $this->fetchStatsSnapshot($token, $campaignId, $sku);
        } catch (Throwable $e) {
            return $this->handleTransientFailure($experiment, $e->getMessage());
        }

        try {
            return DB::transaction(function () use ($experiment, $snapshot, $cabinet, $token, $campaignId) {
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

                $settings = $this->settingsOf($locked);
                $deltaViews = $cycle->deltaViews($snapshot['views']);
                $elapsedMinutes = $cycle->started_at
                    ? $cycle->started_at->diffInMinutes(now())
                    : 0;

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
                            'Не удалось сменить фотографию: '.($switchResult['message'] ?? 'ошибка'),
                            ['failures' => $locked->consecutive_failures],
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
     * @return array{success: bool, message?: string}
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
            fn (AbExperimentPhoto $p) => (int) $p->id === (int) $cycle->ab_experiment_photo_id,
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
            'Цикл №'.$cycle->sequence.' закрыт.',
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
            ['photo_id' => $nextPhoto->id, 'sequence' => $nextSequence],
        );
        $this->journal->log(
            $experiment,
            OzAbExperimentJournal::TYPE_CYCLE_OPENED,
            'Открыт цикл №'.$nextSequence.' эксперимента.',
            ['photo_id' => $nextPhoto->id, 'sequence' => $nextSequence],
        );

        return ['success' => true];
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
                    .($upload['message'] ?? 'ошибка');
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
     *
     * @param  list<int>  $campaignIds
     * @return array<int, array<int, array{views:int,clicks:int,spend:float,orders:int}>>
     */
    public function fetchCabinetStatsSnapshots(string $accessToken, array $campaignIds): array
    {
        $campaignIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $campaignIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($campaignIds === []) {
            return [];
        }

        $today = now('Europe/Moscow')->toDateString();
        $yesterday = now('Europe/Moscow')->subDay()->toDateString();
        $index = [];

        foreach (array_chunk($campaignIds, self::STATS_CAMPAIGN_CHUNK) as $chunk) {
            $response = $this->performanceApi->getProductSkuStatistics($accessToken, [
                'campaignIds' => array_map(static fn (int $id): string => (string) $id, $chunk),
                'dateFrom' => $yesterday,
                'dateTo' => $today,
            ]);
            if (! ($response['success'] ?? false)) {
                throw new \RuntimeException(
                    $this->apiMessage($response, 'Не удалось получить статистику кампаний'),
                );
            }
            $this->mergeIndexedSkuStats($index, $response['data'] ?? null, $chunk);
        }

        return $index;
    }

    /**
     * @return array{views:int,clicks:int,spend:float,orders:int}
     */
    public function fetchStatsSnapshot(string $accessToken, int $campaignId, int $sku): array
    {
        $index = $this->fetchCabinetStatsSnapshots($accessToken, [$campaignId]);

        return $this->snapshotFromIndex($index, $campaignId, $sku);
    }

    /**
     * @return array{views:int,clicks:int,spend:float,orders:int}|null
     */
    public function extractSkuStats(mixed $data, int $campaignId, int $sku): ?array
    {
        $rows = Arr::get($data, 'rows', $data);
        if (! is_array($rows)) {
            return null;
        }

        $index = [];
        $this->mergeIndexedSkuStats($index, $data, [$campaignId]);

        return $this->snapshotFromIndex($index, $campaignId, $sku);
    }

    /**
     * @param  array<int, array<int, array{views:int,clicks:int,spend:float,orders:int}>>  $index
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
            $rowSku = (int) ($row['sku'] ?? 0);
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

            if (! isset($index[$rowCampaign][$rowSku])) {
                $index[$rowCampaign][$rowSku] = $this->emptyStats();
            }
            $index[$rowCampaign][$rowSku]['views'] += (int) round((float) ($row['views'] ?? 0));
            $index[$rowCampaign][$rowSku]['clicks'] += (int) round((float) ($row['clicks'] ?? 0));
            $index[$rowCampaign][$rowSku]['spend'] += (float) ($row['expense'] ?? $row['moneySpent'] ?? 0);
            $index[$rowCampaign][$rowSku]['orders'] += (int) round((float) ($row['orders'] ?? 0));
        }
    }

    /**
     * @param  array<int, array<int, array{views:int,clicks:int,spend:float,orders:int}>>  $index
     * @return array{views:int,clicks:int,spend:float,orders:int}
     */
    private function snapshotFromIndex(array $index, int $campaignId, int $sku): array
    {
        $row = $index[$campaignId][$sku] ?? null;
        if ($row === null) {
            return $this->emptyStats();
        }

        return [
            'views' => (int) $row['views'],
            'clicks' => (int) $row['clicks'],
            'spend' => round((float) $row['spend'], 2),
            'orders' => (int) $row['orders'],
        ];
    }

    /**
     * @return array{views:int,clicks:int,spend:float,orders:int}
     */
    private function emptyStats(): array
    {
        return ['views' => 0, 'clicks' => 0, 'spend' => 0.0, 'orders' => 0];
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
            return ['success' => false, 'message' => 'Нет публичной ссылки на фотографию'];
        }

        $snapshot = is_array($experiment->gallery_snapshot) ? $experiment->gallery_snapshot : [];
        $otherImages = array_values(array_filter(
            $this->extractImageUrls($snapshot['images'] ?? []),
            static fn (string $item): bool => $item !== $url,
        ));

        $payload = [
            'product_id' => (int) $product->oz_product_id,
            'primary_image' => $url,
            'images' => $otherImages,
        ];
        $images360 = $this->extractImageUrls($snapshot['images360'] ?? []);
        if ($images360 !== []) {
            $payload['images360'] = $images360;
        }
        $color = $this->firstImageUrl($snapshot['color_image'] ?? null);
        if ($color !== '') {
            $payload['color_image'] = $color;
        }

        $response = $this->sellerApi->importProductPictures(
            (string) $cabinet->apikey,
            (string) $cabinet->client_id,
            $payload,
        );
        if (! ($response['success'] ?? false)) {
            return ['success' => false, 'message' => $this->apiMessage($response, 'Не удалось загрузить фотографию в карточку')];
        }

        $this->waitPicturesUploaded($cabinet, (int) $product->oz_product_id);

        return ['success' => true];
    }

    public function restoreGallery(OzCabinet $cabinet, AbExperiment $experiment): void
    {
        $snapshot = is_array($experiment->gallery_snapshot) ? $experiment->gallery_snapshot : [];
        $product = $experiment->relationLoaded('product')
            ? $experiment->product
            : AbProduct::query()->find($experiment->ab_product_id);
        if (! $product || $snapshot === []) {
            return;
        }

        $images = $this->extractImageUrls($snapshot['images'] ?? []);
        $primary = $this->firstImageUrl($snapshot['primary_image'] ?? ($images[0] ?? ''));
        if ($primary === '') {
            return;
        }

        $payload = [
            'product_id' => (int) $product->oz_product_id,
            'primary_image' => $primary,
            'images' => array_values(array_filter($images, static fn (string $item): bool => $item !== $primary)),
        ];
        $images360 = $this->extractImageUrls($snapshot['images360'] ?? []);
        if ($images360 !== []) {
            $payload['images360'] = $images360;
        }
        $color = $this->firstImageUrl($snapshot['color_image'] ?? null);
        if ($color !== '') {
            $payload['color_image'] = $color;
        }

        $this->sellerApi->importProductPictures(
            (string) $cabinet->apikey,
            (string) $cabinet->client_id,
            $payload,
        );
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

            return url($relative);
        }

        return url()->route('subscriber.oz.ab-testing.media.show', ['photo' => $photo->id]);
    }

    /**
     * @return array{impressions_per_photo:int,impressions_per_round:int,round_minutes:int,cpm:int}
     */
    public function settingsOf(AbExperiment $experiment): array
    {
        return [
            'impressions_per_photo' => (int) ($experiment->impressions_per_photo ?: 100000),
            'impressions_per_round' => (int) ($experiment->impressions_per_round ?: 10000),
            'round_minutes' => (int) ($experiment->round_minutes ?: 30),
            'cpm' => (int) ($experiment->cpm ?: 15),
        ];
    }

    public function areSettingsReady(AbExperiment $experiment): bool
    {
        return $experiment->impressions_per_photo !== null
            && $experiment->impressions_per_round !== null
            && $experiment->round_minutes !== null
            && (int) $experiment->round_minutes >= 30
            && $experiment->cpm !== null;
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

    private function waitPicturesUploaded(OzCabinet $cabinet, int $ozProductId): void
    {
        for ($i = 0; $i < 3; $i++) {
            if ($i > 0) {
                usleep(400_000);
            }
            $info = $this->sellerApi->getProductPicturesInfo(
                (string) $cabinet->apikey,
                (string) $cabinet->client_id,
                [$ozProductId],
            );
            $pictures = Arr::get($info, 'data.result.pictures', Arr::get($info, 'data.pictures', []));
            if (! is_array($pictures) || $pictures === []) {
                continue;
            }
            $pending = false;
            foreach ($pictures as $picture) {
                if (! is_array($picture)) {
                    continue;
                }
                $state = strtolower((string) ($picture['state'] ?? ''));
                if (in_array($state, ['pending', 'imported', 'processing'], true)) {
                    $pending = true;
                    break;
                }
            }
            if (! $pending) {
                return;
            }
        }
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
