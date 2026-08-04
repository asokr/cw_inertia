<?php

namespace App\Services\Subscriber\Wb\AbTesting;

use App\Enums\WbAbTestStatus;
use App\Jobs\Wb\AbTesting\ProcessAbExperimentJob;
use App\Models\Subscribers\Wb\AbTesting\AbCampaign;
use App\Models\Subscribers\Wb\AbTesting\AbExperiment;
use App\Models\Subscribers\Wb\AbTesting\AbExperimentCycle;
use App\Models\Subscribers\Wb\AbTesting\AbExperimentPhoto;
use App\Models\Subscribers\Wb\AbTesting\AbProduct;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Services\Wb\WbAdvertApiClient;
use App\Services\Wb\WbContentMediaClient;
use App\Support\Wb\WbAdvertFullstatsGuard;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Background A/B experiment lifecycle: start, tick, switch photos, complete, stop.
 */
class WbAbExperimentEngine
{
    public const MAX_CONSECUTIVE_FAILURES = 5;

    public const PHOTO_DISK = 'private';

    /** WB statuses we can run with: ready, active, paused. */
    public const STARTABLE_ADVERT_STATUSES = [4, 9, 11];

    public function __construct(
        private readonly WbAdvertApiClient $advertApi,
        private readonly WbContentMediaClient $mediaApi,
        private readonly WbAbExperimentJournal $journal,
        private readonly WbAdvertFullstatsGuard $fullstatsGuard,
    ) {
    }

    /**
     * Pre-flight validation. Returns list of RU error messages (empty = ok).
     *
     * @return list<string>
     */
    public function validateReadyForStart(WbCabinet $cabinet, AbExperiment $experiment): array
    {
        $errors = [];

        $status = $this->resolveStatus($experiment);
        if ($status === null || ! $status->isStartable()) {
            $errors[] = 'Запустить можно эксперимент в статусе «Черновик», «Остановлен» или «Ошибка».';
        }

        if (empty($cabinet->apikey)) {
            $errors[] = 'У кабинета не указан API-ключ Wildberries.';
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

        if (! $experiment->wb_advert_id) {
            $errors[] = 'Привяжите рекламную кампанию к эксперименту.';
        }

        $product = $experiment->relationLoaded('product')
            ? $experiment->product
            : AbProduct::query()->find($experiment->ab_product_id);

        if (! $product) {
            $errors[] = 'Товар эксперимента не найден.';
        }

        $running = AbExperiment::query()
            ->where('cabinet_id', $experiment->cabinet_id)
            ->where('ab_product_id', $experiment->ab_product_id)
            ->where('status', WbAbTestStatus::Running->value)
            ->where('id', '!=', $experiment->id)
            ->first();

        if ($running) {
            $errors[] = 'По этому товару уже запущен эксперимент «'.$running->name.'».';
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
    public function start(WbCabinet $cabinet, AbExperiment $experiment): array
    {
        $experiment->loadMissing(['photos', 'product']);

        $errors = $this->validateReadyForStart($cabinet, $experiment);
        if ($errors !== []) {
            return ['success' => false, 'messages' => $errors];
        }

        // Live WB checks
        $apiKey = trim((string) $cabinet->apikey);
        $advertId = (int) $experiment->wb_advert_id;
        $product = $experiment->product;
        $nmId = (int) $product->nm_id;

        $advertsResult = $this->advertApi->getAdverts($apiKey, [$advertId]);
        if (! ($advertsResult['success'] ?? false)) {
            return [
                'success' => false,
                'messages' => [
                    $advertsResult['message'] ?? 'Не удалось получить данные рекламной кампании',
                ],
            ];
        }

        $advert = $this->findAdvertInPayload($advertsResult['data'] ?? null, $advertId);
        if ($advert === null) {
            return ['success' => false, 'messages' => ['Рекламная кампания не найдена в кабинете WB.']];
        }

        $advertStatus = (int) Arr::get($advert, 'status', 0);
        if (! in_array($advertStatus, self::STARTABLE_ADVERT_STATUSES, true)) {
            return [
                'success' => false,
                'messages' => [
                    'Кампания не готова к запуску (статус WB: '.$advertStatus
                    .'). Нужен статус «Готова к запуску», «Активна» или «Приостановлена».',
                ],
            ];
        }

        $nmIds = $this->extractAdvertNmIds($advert);
        if (! in_array($nmId, $nmIds, true)) {
            return [
                'success' => false,
                'messages' => [
                    'Товар nmID '.$nmId.' не входит в рекламную кампанию. Добавьте его перед запуском.',
                ],
            ];
        }

        // Preflight: zero budget → WB returns opaque gRPC "advert has no budget to start".
        $budgetResult = $this->advertApi->getBudget($apiKey, $advertId);
        if ($budgetResult['success'] ?? false) {
            $budgetTotal = $this->advertApi->extractBudgetTotal($budgetResult['data'] ?? null);
            if ($budgetTotal !== null && $budgetTotal < 1) {
                return [
                    'success' => false,
                    'messages' => [
                        'У рекламной кампании нет бюджета. Пополните бюджет в кабинете Wildberries '
                        .'(минимум 1000 ₽) или создайте кампанию с пополнением — иначе WB не запустит РК.',
                    ],
                ];
            }
        } else {
            Log::warning('WB A/B testing: getBudget failed before start', [
                'experiment_id' => $experiment->id,
                'advert_id' => $advertId,
                'message' => $budgetResult['message'] ?? null,
            ]);
            // Soft: do not block if budget API flaky — startAdvert will still fail with clearer map below.
        }

        $photos = $experiment->photos->sortBy([
            ['sort_order', 'asc'],
            ['id', 'asc'],
        ])->values();
        /** @var AbExperimentPhoto $firstPhoto */
        $firstPhoto = $photos->first();

        $campaignStarted = false;

        try {
            // 1) Start campaign if needed
            if ($advertStatus === 9) {
                $this->journal->log(
                    $experiment,
                    WbAbExperimentJournal::TYPE_CAMPAIGN_ALREADY_ACTIVE,
                    'Рекламная кампания уже активна на WB — запуск пропущен.',
                    ['advert_id' => $advertId, 'status' => $advertStatus],
                );
            } else {
                $startResult = $this->withRetries(
                    fn () => $this->advertApi->startAdvert($apiKey, $advertId),
                    'startAdvert',
                );
                if (! ($startResult['success'] ?? false)) {
                    return [
                        'success' => false,
                        'messages' => [
                            $this->humanizeStartAdvertError(
                                (string) ($startResult['message'] ?? 'Не удалось запустить рекламную кампанию'),
                            ),
                        ],
                    ];
                }
                $campaignStarted = true;
                $this->journal->log(
                    $experiment,
                    WbAbExperimentJournal::TYPE_CAMPAIGN_STARTED,
                    'Рекламная кампания запущена.',
                    ['advert_id' => $advertId],
                );
            }

            // 2) Set first photo as main
            $upload = $this->uploadPhotoAsMain($apiKey, $nmId, $firstPhoto);
            if (! ($upload['success'] ?? false)) {
                if ($campaignStarted) {
                    $this->safePauseAdvert($apiKey, $advertId);
                }

                return [
                    'success' => false,
                    'messages' => [
                        $upload['message'] ?? 'Не удалось установить главную фотографию в карточке WB',
                    ],
                ];
            }

            $this->journal->log(
                $experiment,
                WbAbExperimentJournal::TYPE_PHOTO_SET,
                'Установлена фотография №1 как главная в карточке WB.',
                ['photo_id' => $firstPhoto->id, 'sort_order' => $firstPhoto->sort_order],
            );

            // 3) Baseline stats
            $snapshot = $this->fetchStatsSnapshot($apiKey, $advertId, $nmId, now());

            // 4–6) DB transition (draft first start or re-start from stopped)
            $experiment = DB::transaction(function () use (
                $experiment,
                $firstPhoto,
                $snapshot,
            ) {
                /** @var AbExperiment $locked */
                $locked = AbExperiment::query()->whereKey($experiment->id)->lockForUpdate()->firstOrFail();

                $lockedStatus = $this->resolveStatus($locked);
                if ($lockedStatus === null || ! $lockedStatus->isStartable()) {
                    throw ValidationException::withMessages([
                        'experiment' => 'Запустить можно только «Черновик», «Остановлен» или «Ошибка».',
                    ]);
                }

                // Close any stray open cycle before re-start.
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
                    'views_start' => $snapshot['views'],
                    'clicks_start' => $snapshot['clicks'],
                    'spend_start' => $snapshot['spend'],
                    'orders_start' => $snapshot['orders'],
                ]);

                $isRestart = in_array($lockedStatus, [WbAbTestStatus::Stopped, WbAbTestStatus::Error], true);

                $locked->status = WbAbTestStatus::Running;
                $locked->started_at = now();
                $locked->finished_at = null;
                $locked->error_message = null;
                $locked->winner_photo_id = null;
                $locked->consecutive_failures = 0;
                $locked->last_processed_at = now();
                // Runtime progress is view-based (0% until impressions arrive).
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
                WbAbExperimentJournal::TYPE_CYCLE_OPENED,
                'Открыт цикл №'.$cycleSeq.' эксперимента.',
                ['cycle_id' => $openedCycle?->id, 'photo_id' => $firstPhoto->id, 'sequence' => $cycleSeq],
            );
            $this->journal->log(
                $experiment,
                WbAbExperimentJournal::TYPE_EXPERIMENT_STARTED,
                $isRestart
                    ? 'Эксперимент перезапущен. Счётчик ошибок сброшен, работа снова выполняется автоматически.'
                    : 'Эксперимент запущен. Дальнейшая работа выполняется автоматически.',
                ['advert_id' => $advertId, 'restart' => $isRestart],
            );

            // Primary loop: first process ASAP, then job self-reschedules every minute.
            ProcessAbExperimentJob::dispatchFor((int) $experiment->id);

            return [
                'success' => true,
                'experiment' => $experiment->fresh(['photos', 'product']),
                'messages' => ['Эксперимент запущен.'],
            ];
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('[WbAbExperimentEngine] start failed', [
                'experiment_id' => $experiment->id,
                'error' => $e->getMessage(),
            ]);

            if ($campaignStarted) {
                $this->safePauseAdvert($apiKey, $advertId);
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
    public function stop(WbCabinet $cabinet, AbExperiment $experiment): array
    {
        $status = $this->resolveStatus($experiment);
        if ($status !== WbAbTestStatus::Running) {
            return [
                'success' => false,
                'messages' => ['Остановить можно только эксперимент «В процессе».'],
            ];
        }

        $apiKey = trim((string) $cabinet->apikey);
        $advertId = (int) $experiment->wb_advert_id;
        $product = $experiment->relationLoaded('product')
            ? $experiment->product
            : AbProduct::query()->find($experiment->ab_product_id);
        $nmId = (int) ($product?->nm_id ?? 0);

        $snapshot = ['views' => 0, 'clicks' => 0, 'spend' => 0.0, 'orders' => 0];
        if ($apiKey !== '' && $advertId > 0) {
            try {
                $snapshot = $this->fetchStatsSnapshot($apiKey, $advertId, $nmId, $experiment->started_at ?? now());
            } catch (Throwable) {
                // Keep zeros; still close cycle.
            }
            $this->safePauseAdvert($apiKey, $advertId);
            $this->journal->log(
                $experiment,
                WbAbExperimentJournal::TYPE_CAMPAIGN_PAUSED,
                'Рекламная кампания приостановлена (остановка пользователем).',
                ['advert_id' => $advertId],
            );
        }

        $experiment = DB::transaction(function () use ($experiment, $snapshot) {
            /** @var AbExperiment $locked */
            $locked = AbExperiment::query()->whereKey($experiment->id)->lockForUpdate()->firstOrFail();
            if ($this->resolveStatus($locked) !== WbAbTestStatus::Running) {
                return $locked;
            }

            $this->closeOpenCycle($locked, $snapshot, AbExperimentCycle::END_STOPPED);

            $locked->status = WbAbTestStatus::Stopped;
            $locked->finished_at = now();
            $locked->save();

            return $locked;
        });

        $this->journal->log(
            $experiment,
            WbAbExperimentJournal::TYPE_EXPERIMENT_STOPPED,
            'Эксперимент остановлен пользователем. Накопленная статистика сохранена.',
        );

        return [
            'success' => true,
            'experiment' => $experiment->fresh(['photos', 'product']),
            'messages' => ['Эксперимент остановлен.'],
        ];
    }

    /**
     * One background tick for a running experiment.
     *
     * @return array{success: bool, action?: string, messages: list<string>}
     */
    public function process(AbExperiment $experiment): array
    {
        $cabinet = $experiment->relationLoaded('cabinet')
            ? $experiment->cabinet
            : WbCabinet::query()->find($experiment->cabinet_id);

        if (! $cabinet || empty($cabinet->apikey)) {
            $this->failExperiment($experiment, 'Нет API-ключа кабинета для обработки эксперимента.');

            return ['success' => false, 'action' => 'error', 'messages' => ['Нет API-ключа']];
        }

        if ($this->resolveStatus($experiment) !== WbAbTestStatus::Running) {
            return ['success' => true, 'action' => 'skipped', 'messages' => []];
        }

        $apiKey = trim((string) $cabinet->apikey);
        $advertId = (int) $experiment->wb_advert_id;
        $product = $experiment->relationLoaded('product')
            ? $experiment->product
            : AbProduct::query()->find($experiment->ab_product_id);
        $nmId = (int) ($product?->nm_id ?? 0);

        if ($advertId <= 0 || $nmId <= 0) {
            $this->failExperiment($experiment, 'Не задана кампания или nmID товара.');

            return ['success' => false, 'action' => 'error', 'messages' => ['Некорректные данные эксперимента']];
        }

        try {
            $snapshot = $this->fetchStatsSnapshot(
                $apiKey,
                $advertId,
                $nmId,
                $experiment->started_at ?? now(),
            );
        } catch (Throwable $e) {
            if ($this->isRateLimitThrowable($e)) {
                return $this->handleRateLimitFailure(
                    $experiment,
                    $e->getMessage(),
                    $this->retryAfterFromThrowable($e),
                );
            }

            return $this->handleTransientFailure($experiment, $e->getMessage(), $apiKey, $advertId);
        }

        try {
            return DB::transaction(function () use (
                $experiment,
                $snapshot,
                $apiKey,
                $advertId,
                $nmId,
            ) {
                /** @var AbExperiment $locked */
                $locked = AbExperiment::query()
                    ->whereKey($experiment->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($this->resolveStatus($locked) !== WbAbTestStatus::Running) {
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

                // Completion check uses closed cycles + current open delta.
                if ($this->allPhotosReachedTarget($locked, $cycle, $snapshot, $settings['impressions_per_photo'])) {
                    return $this->finalizeCompletedInTransaction(
                        $locked,
                        $cycle,
                        $snapshot,
                        $apiKey,
                        $advertId,
                        $nmId,
                    );
                }

                if ($shouldSwitch && $endReason !== null) {
                    $switchResult = $this->switchPhoto(
                        $locked,
                        $cycle,
                        $snapshot,
                        $endReason,
                        $apiKey,
                        $nmId,
                    );

                    if (! ($switchResult['success'] ?? false)) {
                        // Release lock before failure handling outside would re-enter;
                        // throw to outer catch via return failure after increment.
                        $locked->consecutive_failures = (int) $locked->consecutive_failures + 1;
                        $locked->save();

                        $this->journal->log(
                            $locked,
                            WbAbExperimentJournal::TYPE_API_RETRY,
                            'Не удалось сменить фотографию: '.($switchResult['message'] ?? 'ошибка'),
                            ['failures' => $locked->consecutive_failures],
                        );

                        if ($locked->consecutive_failures >= self::MAX_CONSECUTIVE_FAILURES) {
                            $this->finalizeErrorInTransaction(
                                $locked,
                                $cycle,
                                $snapshot,
                                $switchResult['message'] ?? 'Ошибка смены фотографии',
                                $apiKey,
                                $advertId,
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

                    // Re-check completion after switch (previous photo closed).
                    $locked->refresh();
                    if ($this->allPhotosReachedTarget(
                        $locked,
                        null,
                        $snapshot,
                        $settings['impressions_per_photo'],
                    )) {
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
                                $apiKey,
                                $advertId,
                                $nmId,
                            );
                        }
                    }

                    $locked->consecutive_failures = 0;
                    $locked->last_processed_at = now();
                    $locked->progress = $this->computeProgress($locked, $settings['impressions_per_photo']);
                    $locked->save();

                    return ['success' => true, 'action' => 'switched', 'messages' => []];
                }

                // Persist mid-flight snapshot so UI poll sees current round stats
                // without calling WB fullstats on every page load.
                $this->applyProvisionalCycleEnds($cycle, $snapshot);

                $locked->consecutive_failures = 0;
                $locked->last_processed_at = now();
                $locked->progress = $this->computeProgress($locked, $settings['impressions_per_photo'], $cycle, $snapshot);
                $locked->save();

                return ['success' => true, 'action' => 'updated', 'messages' => []];
            });
        } catch (Throwable $e) {
            Log::error('[WbAbExperimentEngine] process failed', [
                'experiment_id' => $experiment->id,
                'error' => $e->getMessage(),
            ]);

            return $this->handleTransientFailure($experiment, $e->getMessage(), $apiKey, $advertId);
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
        string $apiKey,
        int $nmId,
    ): array {
        $photos = $experiment->photos->sortBy([
            ['sort_order', 'asc'],
            ['id', 'asc'],
        ])->values();

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

        // Upload next photo BEFORE closing the current cycle so a failed upload
        // never leaves the experiment without an open cycle.
        $upload = $this->uploadPhotoAsMain($apiKey, $nmId, $nextPhoto);
        if (! ($upload['success'] ?? false)) {
            // Keep mid-flight provisional ends for UI; cycle stays open.
            $this->applyProvisionalCycleEnds($cycle, $snapshot);

            return [
                'success' => false,
                'message' => $upload['message'] ?? 'Не удалось загрузить следующую фотографию в WB',
            ];
        }

        $this->applyCycleEnd($cycle, $snapshot, $endReason);
        $this->journal->log(
            $experiment,
            WbAbExperimentJournal::TYPE_CYCLE_CLOSED,
            'Цикл №'.$cycle->sequence.' закрыт ('.$endReason.').',
            [
                'cycle_id' => $cycle->id,
                'end_reason' => $endReason,
                'delta_views' => $cycle->deltaViews(),
            ],
        );

        $nextSequence = (int) AbExperimentCycle::query()
            ->where('ab_experiment_id', $experiment->id)
            ->max('sequence') + 1;

        $newCycle = AbExperimentCycle::query()->create([
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
            WbAbExperimentJournal::TYPE_PHOTO_SWITCHED,
            'Переключение на фотографию №'.((int) $nextPhoto->sort_order + 1).'.',
            ['photo_id' => $nextPhoto->id, 'sequence' => $nextSequence],
        );
        $this->journal->log(
            $experiment,
            WbAbExperimentJournal::TYPE_PHOTO_SET,
            'Фотография установлена главной в карточке WB.',
            ['photo_id' => $nextPhoto->id],
        );
        $this->journal->log(
            $experiment,
            WbAbExperimentJournal::TYPE_CYCLE_OPENED,
            'Открыт цикл №'.$nextSequence.'.',
            ['cycle_id' => $newCycle->id, 'photo_id' => $nextPhoto->id],
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
        string $apiKey,
        int $advertId,
        int $nmId,
    ): array {
        $this->closeOpenCycle($experiment, $snapshot, AbExperimentCycle::END_COMPLETED);
        $this->safePauseAdvert($apiKey, $advertId);

        $winnerId = $this->resolveWinnerPhotoId($experiment);
        $winnerApplied = false;
        $winnerApplyMessage = null;

        if ($winnerId && $nmId > 0 && $apiKey !== '') {
            $experiment->loadMissing('photos');
            $winnerPhoto = $experiment->photos->first(
                fn (AbExperimentPhoto $p) => (int) $p->id === (int) $winnerId,
            );

            if ($winnerPhoto) {
                $upload = $this->uploadPhotoAsMain($apiKey, $nmId, $winnerPhoto);
                if ($upload['success'] ?? false) {
                    $winnerApplied = true;
                    $this->journal->log(
                        $experiment,
                        WbAbExperimentJournal::TYPE_PHOTO_SET,
                        'Главное фото карточки WB заменено на победителя (лучший CTR).',
                        [
                            'photo_id' => $winnerId,
                            'sort_order' => (int) $winnerPhoto->sort_order,
                            'nm_id' => $nmId,
                        ],
                    );
                } else {
                    $winnerApplyMessage = (string) ($upload['message']
                        ?? 'Не удалось установить фото победителя в карточке WB');
                    $this->journal->log(
                        $experiment,
                        WbAbExperimentJournal::TYPE_API_RETRY,
                        'Не удалось установить победителя главным фото: '.$winnerApplyMessage,
                        ['photo_id' => $winnerId, 'nm_id' => $nmId],
                    );
                    Log::warning('[WbAbExperimentEngine] winner photo upload failed', [
                        'experiment_id' => $experiment->id,
                        'winner_photo_id' => $winnerId,
                        'nm_id' => $nmId,
                        'message' => $winnerApplyMessage,
                    ]);
                }
            }
        }

        $experiment->status = WbAbTestStatus::Completed;
        $experiment->finished_at = now();
        $experiment->progress = 100;
        $experiment->winner_photo_id = $winnerId;
        $experiment->consecutive_failures = 0;
        $experiment->last_processed_at = now();
        // Soft note if winner file failed — experiment still completed with stats.
        $experiment->error_message = $winnerApplyMessage
            ? mb_substr('Победитель определён, но не установлен в карточке WB: '.$winnerApplyMessage, 0, 2000)
            : null;
        $experiment->save();

        $this->journal->log(
            $experiment,
            WbAbExperimentJournal::TYPE_CAMPAIGN_PAUSED,
            'Рекламная кампания приостановлена (эксперимент завершён).',
            ['advert_id' => $advertId],
        );

        if ($winnerId) {
            $this->journal->log(
                $experiment,
                WbAbExperimentJournal::TYPE_WINNER_SELECTED,
                $winnerApplied
                    ? 'Победитель по CTR выбран и установлен главным фото в карточке WB.'
                    : 'Победитель по CTR выбран'.($winnerApplyMessage ? ', но не установлен в карточке WB.' : '.'),
                [
                    'winner_photo_id' => $winnerId,
                    'applied_as_main' => $winnerApplied,
                    'apply_error' => $winnerApplyMessage,
                ],
            );
        }

        $this->journal->log(
            $experiment,
            WbAbExperimentJournal::TYPE_EXPERIMENT_COMPLETED,
            'Эксперимент завершён: каждая фотография набрала целевые показы.',
            [
                'winner_photo_id' => $winnerId,
                'winner_applied_as_main' => $winnerApplied,
            ],
        );

        return ['success' => true, 'action' => 'completed', 'messages' => []];
    }

    /**
     * @param  array{views:int,clicks:int,spend:float,orders:int}  $snapshot
     */
    private function finalizeErrorInTransaction(
        AbExperiment $experiment,
        ?AbExperimentCycle $cycle,
        array $snapshot,
        string $message,
        string $apiKey,
        int $advertId,
    ): void {
        if ($cycle && $cycle->isOpen()) {
            $this->applyCycleEnd($cycle, $snapshot, AbExperimentCycle::END_ERROR);
        }

        $this->safePauseAdvert($apiKey, $advertId);

        $experiment->status = WbAbTestStatus::Error;
        $experiment->finished_at = now();
        $experiment->error_message = mb_substr($message, 0, 2000);
        $experiment->last_processed_at = now();
        $experiment->save();

        $this->journal->log(
            $experiment,
            WbAbExperimentJournal::TYPE_EXPERIMENT_ERROR,
            'Эксперимент переведён в ошибку: '.$message,
        );
    }

    private function failExperiment(AbExperiment $experiment, string $message, bool $pause = true): void
    {
        $apiKey = '';
        $advertId = (int) $experiment->wb_advert_id;
        if ($pause && $advertId > 0) {
            $cabinet = WbCabinet::query()->find($experiment->cabinet_id);
            $apiKey = trim((string) ($cabinet?->apikey ?? ''));
            if ($apiKey !== '') {
                $this->safePauseAdvert($apiKey, $advertId);
            }
        }

        $snapshot = ['views' => 0, 'clicks' => 0, 'spend' => 0.0, 'orders' => 0];
        DB::transaction(function () use ($experiment, $snapshot, $message, $apiKey, $advertId) {
            $locked = AbExperiment::query()->whereKey($experiment->id)->lockForUpdate()->first();
            if (! $locked || $this->resolveStatus($locked) !== WbAbTestStatus::Running) {
                return;
            }
            $cycle = $locked->resolveOpenCycle();
            $this->finalizeErrorInTransaction(
                $locked,
                $cycle,
                $snapshot,
                $message,
                $apiKey,
                $advertId,
            );
        });
    }

    /**
     * @return array{success: bool, action: string, messages: list<string>}
     */
    private function handleTransientFailure(
        AbExperiment $experiment,
        string $message,
        string $apiKey,
        int $advertId,
    ): array {
        $experiment->refresh();
        if ($this->resolveStatus($experiment) !== WbAbTestStatus::Running) {
            return ['success' => false, 'action' => 'skipped', 'messages' => [$message]];
        }

        $experiment->consecutive_failures = (int) $experiment->consecutive_failures + 1;
        $experiment->last_processed_at = now();
        $experiment->save();

        $this->journal->log(
            $experiment,
            WbAbExperimentJournal::TYPE_API_RETRY,
            'Временная ошибка API: '.$message,
            ['failures' => $experiment->consecutive_failures],
        );

        if ($experiment->consecutive_failures >= self::MAX_CONSECUTIVE_FAILURES) {
            $this->failExperiment($experiment, 'Превышено число повторных попыток: '.$message);

            return ['success' => false, 'action' => 'error', 'messages' => [$message]];
        }

        return ['success' => false, 'action' => 'retry', 'messages' => [$message]];
    }

    /**
     * Rate-limit (429 / throttle) must not push the experiment into error status.
     *
     * @return array{success: bool, action: string, messages: list<string>, retry_after: int}
     */
    private function handleRateLimitFailure(
        AbExperiment $experiment,
        string $message,
        int $retryAfter,
    ): array {
        $experiment->refresh();
        if ($this->resolveStatus($experiment) !== WbAbTestStatus::Running) {
            return [
                'success' => false,
                'action' => 'skipped',
                'messages' => [$message],
                'retry_after' => $retryAfter,
            ];
        }

        $retryAfter = max(WbAdvertFullstatsGuard::DEFAULT_RETRY_AFTER_429, $retryAfter);
        $experiment->last_processed_at = now();
        $experiment->save();

        $this->journal->log(
            $experiment,
            WbAbExperimentJournal::TYPE_API_RATE_LIMITED,
            'Лимит запросов WB fullstats: '.$message.' Повтор через '.$retryAfter.' с.',
            ['retry_after' => $retryAfter, 'failures' => (int) $experiment->consecutive_failures],
        );

        return [
            'success' => false,
            'action' => 'rate_limited',
            'messages' => [$message],
            'retry_after' => $retryAfter,
        ];
    }

    private function isRateLimitThrowable(Throwable $e): bool
    {
        if ((int) $e->getCode() === 429) {
            return true;
        }

        $msg = mb_strtolower($e->getMessage());

        return str_contains($msg, 'rate_limited')
            || str_contains($msg, '429')
            || str_contains($msg, 'лимит запросов')
            || str_contains($msg, 'too many requests');
    }

    private function retryAfterFromThrowable(Throwable $e): int
    {
        if (preg_match('/retry_after=(\d+)/i', $e->getMessage(), $m)) {
            return max(1, (int) $m[1]);
        }

        return WbAdvertFullstatsGuard::DEFAULT_RETRY_AFTER_429;
    }

    /**
     * @param  array{views:int,clicks:int,spend:float,orders:int}  $snapshot
     */
    private function closeOpenCycle(AbExperiment $experiment, array $snapshot, string $endReason): void
    {
        $cycle = $experiment->resolveOpenCycle();

        if ($cycle && $cycle->isOpen()) {
            $this->applyCycleEnd($cycle, $snapshot, $endReason);
            $this->journal->log(
                $experiment,
                WbAbExperimentJournal::TYPE_CYCLE_CLOSED,
                'Цикл №'.$cycle->sequence.' закрыт ('.$endReason.').',
                ['cycle_id' => $cycle->id, 'end_reason' => $endReason],
            );
        }
    }

    /**
     * Mid-flight stats for open cycle (ended_at stays null).
     *
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
    private function applyCycleEnd(AbExperimentCycle $cycle, array $snapshot, string $endReason): void
    {
        $cycle->ended_at = now();
        $cycle->end_reason = $endReason;
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

        if ($photos->count() < 2) {
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
     * @return array<int, int> photo_id => views
     */
    public function photoViewTotals(
        AbExperiment $experiment,
        ?AbExperimentCycle $openCycle = null,
        ?array $openSnapshot = null,
    ): array {
        $totals = [];

        // Always load ALL cycles — never trust a truncated eager load (e.g. limit 100 for history).
        $cycles = $this->loadAllCycles($experiment);

        foreach ($cycles as $cycle) {
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
                // Provisional mid-flight ends written by process tick.
                $totals[$photoId] = ($totals[$photoId] ?? 0) + $cycle->deltaViews();
            }
        }

        return $totals;
    }

    /**
     * Per-photo totals from closed cycles + open cycle with provisional ends.
     *
     * @return array<int, array{views:int,clicks:int,ctr:float|null}>
     */
    public function photoAggregates(AbExperiment $experiment): array
    {
        $agg = [];
        // Always ALL cycles — workspace may eager-load cycles with limit(100) for history only.
        $cycles = $this->loadAllCycles($experiment);

        foreach ($cycles as $cycle) {
            // Closed cycles always count; open cycles only with provisional/final ends.
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
     * Full cycle history for aggregates/progress (never use limited UI relations).
     *
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
     * Progress 0–99 while running: bottleneck = min(views_i / impressions_per_photo).
     * Completed is always forced to 100 separately.
     *
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
     * @return array{
     *     progress: int,
     *     mode: string,
     *     target_per_photo: int,
     *     total_views: int,
     *     bottleneck_ratio: float,
     *     photos: list<array{id:int,sort_order:int,views:int,ratio:float}>
     * }
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

        // 0…99 while running; 100 is reserved for completed.
        $progress = $target > 0 && $photos->isNotEmpty()
            ? max(0, min(99, (int) floor($bottleneck * 100)))
            : 0;

        $mode = $totalViews <= 0 ? 'pending' : 'views';

        return [
            'progress' => $progress,
            'mode' => $mode,
            'target_per_photo' => $target,
            'total_views' => $totalViews,
            'bottleneck_ratio' => round($bottleneck, 4),
            'photos' => $photoRows,
        ];
    }

    /**
     * @return array{views:int,clicks:int,spend:float,orders:int}
     */
    private function fetchStatsSnapshot(
        string $apiKey,
        int $advertId,
        int $nmId,
        Carbon|string|null $startedAt,
    ): array {
        $wait = $this->fullstatsGuard->waitSeconds($apiKey);
        if ($wait > 0) {
            throw new \RuntimeException(
                'rate_limited: fullstats throttle, retry_after='.$wait,
                429,
            );
        }

        // WB fullstats max period is 31 days.
        $end = Carbon::now('Europe/Moscow')->startOfDay();
        $begin = Carbon::parse($startedAt ?? now(), 'Europe/Moscow')->startOfDay();
        if ($begin->diffInDays($end) > 30) {
            $begin = $end->copy()->subDays(30);
        }

        $this->fullstatsGuard->markAttempt($apiKey);

        $result = $this->withRetries(
            function () use ($apiKey, $advertId, $begin, $end) {
                $response = $this->advertApi->fullstats(
                    $apiKey,
                    [$advertId],
                    $begin->toDateString(),
                    $end->toDateString(),
                );
                // On 429 do not burn retries with sub-second sleeps — respect interval.
                if ((int) ($response['code'] ?? 0) === 429) {
                    $retryAfter = (int) ($response['retry_after']
                        ?? WbAdvertFullstatsGuard::DEFAULT_RETRY_AFTER_429);
                    $this->fullstatsGuard->setCooldownAfter429($apiKey, max(1, $retryAfter));

                    return $response;
                }

                return $response;
            },
            'fullstats',
            1, // single attempt: backoff is job-level via rate_limited reschedule
        );

        if (! ($result['success'] ?? false)) {
            $code = (int) ($result['code'] ?? 0);
            $message = (string) ($result['message'] ?? 'Ошибка получения статистики кампании');
            if ($code === 429) {
                $retryAfter = (int) ($result['retry_after']
                    ?? WbAdvertFullstatsGuard::DEFAULT_RETRY_AFTER_429);
                $this->fullstatsGuard->setCooldownAfter429($apiKey, max(1, $retryAfter));
                throw new \RuntimeException(
                    'rate_limited: '.$message.'; retry_after='.$retryAfter,
                    429,
                );
            }

            throw new \RuntimeException($message, $code > 0 ? $code : 0);
        }

        $stats = $this->advertApi->extractStatsForAdvert(
            $result['rows'] ?? [],
            $advertId,
            $nmId > 0 ? $nmId : null,
        );

        return [
            'views' => (int) $stats['views'],
            'clicks' => (int) $stats['clicks'],
            'spend' => (float) $stats['spend'],
            'orders' => (int) $stats['orders'],
        ];
    }

    /**
     * @return array{success: bool, message?: string}
     */
    private function uploadPhotoAsMain(string $apiKey, int $nmId, AbExperimentPhoto $photo): array
    {
        $disk = (string) ($photo->disk ?: self::PHOTO_DISK);
        $path = (string) $photo->path;

        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            return ['success' => false, 'message' => 'Файл фотографии не найден на диске'];
        }

        $binary = Storage::disk($disk)->get($path);
        if ($binary === null || $binary === '') {
            return ['success' => false, 'message' => 'Не удалось прочитать файл фотографии'];
        }

        $filename = $photo->original_name ?: basename($path);
        $mime = $photo->mime ?: 'image/jpeg';

        return $this->withRetries(
            fn () => $this->mediaApi->uploadMediaFile(
                $apiKey,
                $nmId,
                1,
                $binary,
                $filename,
                $mime,
            ),
            'uploadMediaFile',
        );
    }

    private function safePauseAdvert(string $apiKey, int $advertId): void
    {
        if ($apiKey === '' || $advertId <= 0) {
            return;
        }

        try {
            $this->withRetries(
                fn () => $this->advertApi->pauseAdvert($apiKey, $advertId),
                'pauseAdvert',
            );
        } catch (Throwable $e) {
            Log::warning('[WbAbExperimentEngine] pauseAdvert failed', [
                'advert_id' => $advertId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Map opaque WB startAdvert errors to actionable Russian messages.
     */
    private function humanizeStartAdvertError(string $raw): string
    {
        $lower = mb_strtolower($raw);
        if (
            str_contains($lower, 'no budget')
            || str_contains($lower, 'has no budget')
            || str_contains($lower, 'budget to start')
            || str_contains($lower, 'нет бюджета')
        ) {
            return 'У рекламной кампании нет бюджета. Пополните бюджет в кабинете Wildberries '
                .'(минимум 1000 ₽) или создайте кампанию с пополнением — иначе WB не запустит РК.';
        }

        if (str_contains($lower, 'status unchanged')) {
            return 'WB не изменил статус кампании: '.$raw;
        }

        return $raw !== '' ? $raw : 'Не удалось запустить рекламную кампанию';
    }

    /**
     * @param  callable(): array{success?: bool, message?: string, ...}  $callback
     * @return array<string, mixed>
     */
    private function withRetries(callable $callback, string $label, int $attempts = 3): array
    {
        $last = ['success' => false, 'message' => 'Неизвестная ошибка '.$label];

        for ($i = 1; $i <= $attempts; $i++) {
            $last = $callback();
            if ($last['success'] ?? false) {
                return $last;
            }

            $code = (int) ($last['code'] ?? 0);
            // 429: do not tight-loop (fullstats burst=1, interval 20s). Caller handles reschedule.
            if ($code === 429) {
                break;
            }

            $retryable = $code === 0 || $code >= 500;
            if (! $retryable || $i === $attempts) {
                break;
            }

            // Backoff in seconds (not ms) for 5xx / network.
            usleep(1_000_000 * $i);
        }

        return $last;
    }

    /**
     * @return array{impressions_per_photo:int,impressions_per_round:int,round_minutes:int,cpm:int}
     */
    private function settingsOf(AbExperiment $experiment): array
    {
        return [
            'impressions_per_photo' => (int) ($experiment->impressions_per_photo ?? 100000),
            'impressions_per_round' => (int) ($experiment->impressions_per_round ?? 10000),
            'round_minutes' => (int) ($experiment->round_minutes ?? 60),
            'cpm' => (int) ($experiment->cpm ?? 350),
        ];
    }

    private function areSettingsReady(AbExperiment $experiment): bool
    {
        $minBid = $this->minBidForExperiment($experiment);

        return $experiment->impressions_per_photo !== null
            && $experiment->impressions_per_round !== null
            && $experiment->round_minutes !== null
            && $experiment->cpm !== null
            && (int) $experiment->impressions_per_photo >= 1000
            && (int) $experiment->impressions_per_round >= 100
            && (int) $experiment->impressions_per_round <= (int) $experiment->impressions_per_photo
            && (int) $experiment->round_minutes >= 5
            && (int) $experiment->cpm >= $minBid;
    }

    /**
     * Bid (stored as cpm): CPM min 50 ₽, CPC min 1 ₽ — based on bound campaign payment type.
     */
    private function minBidForExperiment(AbExperiment $experiment): int
    {
        $advertId = (int) ($experiment->wb_advert_id ?? 0);
        if ($advertId <= 0) {
            return 50;
        }

        $paymentType = AbCampaign::query()
            ->where('cabinet_id', (int) $experiment->cabinet_id)
            ->where('wb_advert_id', $advertId)
            ->value('payment_type');

        return is_string($paymentType) && strtolower(trim($paymentType)) === 'cpc' ? 1 : 50;
    }

    private function resolveStatus(AbExperiment $experiment): WbAbTestStatus
    {
        if ($experiment->status instanceof WbAbTestStatus) {
            return $experiment->status;
        }

        return WbAbTestStatus::tryFrom((string) $experiment->status) ?? WbAbTestStatus::Draft;
    }

    /**
     * @return list<int>
     */
    private function extractAdvertNmIds(array $advert): array
    {
        $ids = [];
        foreach ((array) Arr::get($advert, 'nm_settings', []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (int) Arr::get($row, 'nm_id', Arr::get($row, 'nmId', 0));
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  mixed  $payload
     * @return array<string, mixed>|null
     */
    private function findAdvertInPayload(mixed $payload, int $advertId): ?array
    {
        $list = [];
        if (is_array($payload)) {
            if (isset($payload['adverts']) && is_array($payload['adverts'])) {
                $list = $payload['adverts'];
            } elseif (array_is_list($payload)) {
                $list = $payload;
            }
        }

        foreach ($list as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (int) Arr::get($row, 'id', Arr::get($row, 'advertId', 0));
            if ($id === $advertId) {
                return $row;
            }
        }

        return null;
    }
}
