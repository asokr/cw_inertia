<?php

namespace App\Services\Credits;

use App\Enums\Credits\CreditHoldStatus;
use App\Enums\Credits\CreditLedgerDirection;
use App\Enums\Credits\CreditLedgerType;
use App\Exceptions\Credits\InsufficientCreditsException;
use App\Exceptions\Credits\InvalidCreditOperationException;
use App\Models\Credits\CreditAccount;
use App\Models\Credits\CreditHold;
use App\Models\Credits\CreditLedger;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\User;
use App\Notifications\SubscriptionCreditsEndedNotification;
use App\Support\CreditPeriod;
use App\Support\CreditsFormatter;
use App\Support\ToolLimits;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CreditBillingService
{
    public function isReady(): bool
    {
        return Schema::hasTable('credit_accounts')
            && Schema::hasTable('credit_ledger')
            && Schema::hasTable('credit_holds');
    }

    public function getBalance(User $user): CreditBalance
    {
        if (! $this->isReady()) {
            return new CreditBalance(0, 0, 0, 0, $this->planCreditsPerPeriod($user));
        }

        $account = CreditAccount::query()->where('user_id', $user->id)->first();

        if (! $account) {
            return new CreditBalance(0, 0, 0, 0, $this->planCreditsPerPeriod($user));
        }

        return new CreditBalance(
            $account->subscription_balance,
            $account->purchased_balance,
            $account->subscription_held,
            $account->purchased_held,
            $this->planCreditsPerPeriod($user),
        );
    }

    public function hasEnough(User $user, int $amount): bool
    {
        if ($amount <= 0 || $this->bypassesCharges($user)) {
            return true;
        }

        return $this->getBalance($user)->available() >= $amount;
    }

    public function grantPeriod(
        User $user,
        SubscribersSubscriptions $subscription,
        SubscribersPlans $plan,
    ): ?CreditLedger {
        if (! $this->isReady()) {
            return null;
        }

        $periodKey = CreditPeriod::key($subscription);
        $idempotencyKey = 'grant:'.$periodKey;
        $amount = $this->planCreditsValue($plan);

        return DB::transaction(function () use ($user, $subscription, $periodKey, $idempotencyKey, $amount) {
            $existing = $this->findByIdempotency($idempotencyKey);
            if ($existing) {
                return $existing;
            }

            $account = $this->lockAccount($user);

            if ($account->last_granted_period_key === $periodKey) {
                return $this->latestGrantForPeriod($user, $periodKey);
            }

            // Доступный пакет периода = credits_per_period; холды текущего периода сохраняем.
            $newSubscriptionBalance = $amount + $account->subscription_held;
            $subscriptionDelta = $newSubscriptionBalance - $account->subscription_balance;

            $account->subscription_balance = $newSubscriptionBalance;
            $account->last_granted_period_key = $periodKey;
            $account->save();

            return $this->writeLedger($account, [
                'type' => CreditLedgerType::GrantSubscription,
                'direction' => CreditLedgerDirection::Credit,
                'amount' => $amount,
                'subscription_delta' => $subscriptionDelta,
                'purchased_delta' => 0,
                'idempotency_key' => $idempotencyKey,
                'period_key' => $periodKey,
                'description' => 'Начисление кредитов за период подписки',
                'user_label' => 'Начислено '.CreditsFormatter::amount($amount).' по тарифу',
                'related_type' => $subscription->getMorphClass(),
                'related_id' => $subscription->id,
            ]);
        });
    }

    /**
     * Переход на тариф выше: пакет нового тарифа складывается с остатком кредитов подписки.
     * Купленные не меняются. Повтор того же периода не удваивает начисление.
     */
    public function grantUpgrade(
        User $user,
        SubscribersSubscriptions $subscription,
        SubscribersPlans $plan,
    ): ?CreditLedger {
        if (! $this->isReady()) {
            return null;
        }

        $periodKey = CreditPeriod::key($subscription);
        $idempotencyKey = 'grant-upgrade:'.$periodKey;
        $amount = $this->planCreditsValue($plan);

        return DB::transaction(function () use ($user, $subscription, $periodKey, $idempotencyKey, $amount) {
            $existing = $this->findByIdempotency($idempotencyKey);
            if ($existing) {
                return $existing;
            }

            $account = $this->lockAccount($user);

            if ($account->last_granted_period_key === $periodKey) {
                return $this->latestGrantForPeriod($user, $periodKey);
            }

            $account->subscription_balance += $amount;
            $account->last_granted_period_key = $periodKey;
            $account->save();

            return $this->writeLedger($account, [
                'type' => CreditLedgerType::GrantSubscription,
                'direction' => CreditLedgerDirection::Credit,
                'amount' => $amount,
                'subscription_delta' => $amount,
                'purchased_delta' => 0,
                'idempotency_key' => $idempotencyKey,
                'period_key' => $periodKey,
                'description' => 'Начисление кредитов при переходе на тариф выше',
                'user_label' => 'Начислено '.CreditsFormatter::amount($amount).' при смене тарифа',
                'related_type' => $subscription->getMorphClass(),
                'related_id' => $subscription->id,
            ]);
        });
    }

    /**
     * Зачисление кредитов тарифа (перенос месячных leftover).
     *
     * @param  array<string, mixed>  $context
     */
    public function addSubscription(
        User $user,
        int $amount,
        array $context = [],
    ): CreditLedger {
        if (! $this->isReady()) {
            throw new InvalidCreditOperationException('Система кредитов ещё не готова');
        }

        if ($amount < 1) {
            throw new InvalidCreditOperationException('Количество кредитов должно быть больше нуля');
        }

        $type = $context['type'] ?? CreditLedgerType::GrantSubscription;
        if (! $type instanceof CreditLedgerType) {
            $type = CreditLedgerType::from((string) $type);
        }

        $idempotencyKey = (string) ($context['idempotency_key'] ?? ('subscription:'.$user->id.':'.Str::uuid()));

        return DB::transaction(function () use ($user, $amount, $context, $type, $idempotencyKey) {
            $existing = $this->findByIdempotency($idempotencyKey);
            if ($existing) {
                return $existing;
            }

            $account = $this->lockAccount($user);
            $account->subscription_balance += $amount;
            $account->save();

            return $this->writeLedger($account, [
                'type' => $type,
                'direction' => CreditLedgerDirection::Credit,
                'amount' => $amount,
                'subscription_delta' => $amount,
                'purchased_delta' => 0,
                'idempotency_key' => $idempotencyKey,
                'description' => (string) ($context['description'] ?? 'Зачисление кредитов по тарифу'),
                'user_label' => (string) ($context['user_label'] ?? ('Зачислено '.CreditsFormatter::amount($amount))),
                'admin_user_id' => $context['admin_user_id'] ?? null,
                'related_type' => $context['related_type'] ?? null,
                'related_id' => $context['related_id'] ?? null,
            ]);
        });
    }

    /**
     * Зачисление купленных кредитов (админ, покупка, миграция extra).
     *
     * @param  array<string, mixed>  $context
     */
    public function addPurchased(
        User $user,
        int $amount,
        array $context = [],
    ): CreditLedger {
        if (! $this->isReady()) {
            throw new InvalidCreditOperationException('Система кредитов ещё не готова');
        }

        if ($amount < 1) {
            throw new InvalidCreditOperationException('Количество кредитов должно быть больше нуля');
        }

        $type = $context['type'] ?? CreditLedgerType::Purchase;
        if (! $type instanceof CreditLedgerType) {
            $type = CreditLedgerType::from((string) $type);
        }

        $idempotencyKey = (string) ($context['idempotency_key'] ?? ('purchase:'.$user->id.':'.Str::uuid()));

        return DB::transaction(function () use ($user, $amount, $context, $type, $idempotencyKey) {
            $existing = $this->findByIdempotency($idempotencyKey);
            if ($existing) {
                return $existing;
            }

            $account = $this->lockAccount($user);
            $account->purchased_balance += $amount;
            $account->save();

            return $this->writeLedger($account, [
                'type' => $type,
                'direction' => CreditLedgerDirection::Credit,
                'amount' => $amount,
                'subscription_delta' => 0,
                'purchased_delta' => $amount,
                'idempotency_key' => $idempotencyKey,
                'description' => (string) ($context['description'] ?? 'Зачисление купленных кредитов'),
                'user_label' => (string) ($context['user_label'] ?? ('Зачислено '.CreditsFormatter::amount($amount))),
                'admin_user_id' => $context['admin_user_id'] ?? null,
                'related_type' => $context['related_type'] ?? null,
                'related_id' => $context['related_id'] ?? null,
            ]);
        });
    }

    public function spend(User $user, CreditSpendRequest $request): CreditLedger
    {
        if (! $this->isReady()) {
            throw new InvalidCreditOperationException('Система кредитов ещё не готова');
        }

        if ($request->amount < 1) {
            throw new InvalidCreditOperationException('Сумма списания должна быть больше нуля');
        }

        if ($request->idempotencyKey === '') {
            throw new InvalidCreditOperationException('Нужен ключ идемпотентности');
        }

        if ($this->bypassesCharges($user)) {
            return $this->recordBypass($user, $request);
        }

        $didSpend = false;
        $ledger = DB::transaction(function () use ($user, $request, &$didSpend) {
            $existing = $this->findByIdempotency($request->idempotencyKey);
            if ($existing) {
                return $existing;
            }

            $account = $this->lockAccount($user);
            $available = $account->available();

            if ($available < $request->amount) {
                throw new InsufficientCreditsException($request->amount, $available);
            }

            $fromSubscription = min($account->availableSubscription(), $request->amount);
            $fromPurchased = $request->amount - $fromSubscription;

            $account->subscription_balance -= $fromSubscription;
            $account->purchased_balance -= $fromPurchased;
            $account->save();
            $didSpend = true;

            return $this->writeLedger($account, [
                'type' => CreditLedgerType::Spend,
                'direction' => CreditLedgerDirection::Debit,
                'amount' => $request->amount,
                'subscription_delta' => -$fromSubscription,
                'purchased_delta' => -$fromPurchased,
                'source_split' => [
                    'subscription' => $fromSubscription,
                    'purchased' => $fromPurchased,
                ],
                'idempotency_key' => $request->idempotencyKey,
                'service_code' => $request->serviceCode,
                'operation_params' => $request->operationParams,
                'description' => $request->description ?? 'Списание кредитов',
                'user_label' => $request->userLabel ?? ('Списано '.CreditsFormatter::amount($request->amount)),
            ]);
        });

        if ($didSpend) {
            $this->notifyIfCreditsEnded($user);
        }

        return $ledger;
    }

    public function reserve(
        User $user,
        CreditSpendRequest $request,
        ?\DateTimeInterface $expiresAt = null,
    ): CreditHold {
        if (! $this->isReady()) {
            throw new InvalidCreditOperationException('Система кредитов ещё не готова');
        }

        if ($request->amount < 1) {
            throw new InvalidCreditOperationException('Сумма резерва должна быть больше нуля');
        }

        if ($this->bypassesCharges($user)) {
            return $this->recordBypassHold($user, $request, $expiresAt);
        }

        return DB::transaction(function () use ($user, $request, $expiresAt) {
            $existingHold = CreditHold::query()
                ->where('idempotency_key', $request->idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingHold) {
                return $existingHold;
            }

            $account = $this->lockAccount($user);
            $available = $account->available();

            if ($available < $request->amount) {
                throw new InsufficientCreditsException($request->amount, $available);
            }

            $fromSubscription = min($account->availableSubscription(), $request->amount);
            $fromPurchased = $request->amount - $fromSubscription;

            $account->subscription_held += $fromSubscription;
            $account->purchased_held += $fromPurchased;
            $account->save();

            $hold = CreditHold::create([
                'user_id' => $user->id,
                'amount' => $request->amount,
                'subscription_reserved' => $fromSubscription,
                'purchased_reserved' => $fromPurchased,
                'status' => CreditHoldStatus::Held,
                'idempotency_key' => $request->idempotencyKey,
                'service_code' => $request->serviceCode,
                'operation_params' => $request->operationParams,
                'expires_at' => $expiresAt ?? now()->addHour(),
            ]);

            $this->writeLedger($account, [
                'type' => CreditLedgerType::Hold,
                'direction' => CreditLedgerDirection::Debit,
                'amount' => $request->amount,
                'subscription_delta' => 0,
                'purchased_delta' => 0,
                'source_split' => [
                    'subscription' => $fromSubscription,
                    'purchased' => $fromPurchased,
                ],
                'idempotency_key' => 'hold-ledger:'.$request->idempotencyKey,
                'service_code' => $request->serviceCode,
                'operation_params' => $request->operationParams,
                'description' => $request->description ?? 'Резерв кредитов',
                'user_label' => $request->userLabel ?? ('Зарезервировано '.CreditsFormatter::amount($request->amount)),
                'related_type' => $hold->getMorphClass(),
                'related_id' => $hold->id,
            ]);

            return $hold;
        });
    }

    public function capture(CreditHold $hold, ?string $idempotencyKey = null): CreditLedger
    {
        if (! $this->isReady()) {
            throw new InvalidCreditOperationException('Система кредитов ещё не готова');
        }

        $captureKey = $idempotencyKey ?? ('capture:'.$hold->id);

        $didCapture = false;
        $user = $hold->user;
        $ledger = DB::transaction(function () use ($hold, $captureKey, &$didCapture, &$user) {
            $existing = $this->findByIdempotency($captureKey);
            if ($existing) {
                return $existing;
            }

            /** @var CreditHold $locked */
            $locked = CreditHold::query()->lockForUpdate()->findOrFail($hold->id);

            if ($locked->status === CreditHoldStatus::Captured) {
                return $this->findByIdempotency($captureKey)
                    ?? throw new InvalidCreditOperationException('Резерв уже списан');
            }

            if (! $locked->isActive()) {
                throw new InvalidCreditOperationException('Резерв нельзя списать');
            }

            $user = $locked->user;
            $account = $this->lockAccount($user);
            $account->subscription_held -= $locked->subscription_reserved;
            $account->purchased_held -= $locked->purchased_reserved;
            $account->subscription_balance -= $locked->subscription_reserved;
            $account->purchased_balance -= $locked->purchased_reserved;
            $account->save();

            $locked->status = CreditHoldStatus::Captured;
            $locked->save();
            $didCapture = true;

            $params = is_array($locked->operation_params) ? $locked->operation_params : [];
            $userLabel = is_string($params['user_label'] ?? null) && $params['user_label'] !== ''
                ? (string) $params['user_label']
                : ('Списано '.CreditsFormatter::amount($locked->amount));

            return $this->writeLedger($account, [
                'type' => CreditLedgerType::Capture,
                'direction' => CreditLedgerDirection::Debit,
                'amount' => $locked->amount,
                'subscription_delta' => -$locked->subscription_reserved,
                'purchased_delta' => -$locked->purchased_reserved,
                'source_split' => [
                    'subscription' => $locked->subscription_reserved,
                    'purchased' => $locked->purchased_reserved,
                ],
                'idempotency_key' => $captureKey,
                'service_code' => $locked->service_code,
                'operation_params' => $locked->operation_params,
                'description' => $userLabel,
                'user_label' => $userLabel,
                'related_type' => $locked->getMorphClass(),
                'related_id' => $locked->id,
            ]);
        });

        if ($didCapture && $user instanceof User) {
            $this->notifyIfCreditsEnded($user);
        }

        return $ledger;
    }

    public function release(CreditHold $hold, CreditHoldStatus $status = CreditHoldStatus::Released): CreditLedger
    {
        if (! $this->isReady()) {
            throw new InvalidCreditOperationException('Система кредитов ещё не готова');
        }

        $releaseKey = $status->value.':'.$hold->id;

        return DB::transaction(function () use ($hold, $status, $releaseKey) {
            $existing = $this->findByIdempotency($releaseKey);
            if ($existing) {
                return $existing;
            }

            /** @var CreditHold $locked */
            $locked = CreditHold::query()->lockForUpdate()->findOrFail($hold->id);

            if (! $locked->isActive()) {
                return $this->findByIdempotency($releaseKey)
                    ?? throw new InvalidCreditOperationException('Резерв уже закрыт');
            }

            $account = $this->lockAccount($locked->user);
            $account->subscription_held -= $locked->subscription_reserved;
            $account->purchased_held -= $locked->purchased_reserved;
            $account->save();

            $locked->status = $status;
            $locked->save();

            $label = $status === CreditHoldStatus::Expired
                ? 'Резерв истек, кредиты возвращены'
                : 'Резерв отменён, кредиты возвращены';

            return $this->writeLedger($account, [
                'type' => CreditLedgerType::Release,
                'direction' => CreditLedgerDirection::Credit,
                'amount' => $locked->amount,
                'subscription_delta' => 0,
                'purchased_delta' => 0,
                'source_split' => [
                    'subscription' => $locked->subscription_reserved,
                    'purchased' => $locked->purchased_reserved,
                ],
                'idempotency_key' => $releaseKey,
                'service_code' => $locked->service_code,
                'operation_params' => $locked->operation_params,
                'description' => $label,
                'user_label' => $label,
                'related_type' => $locked->getMorphClass(),
                'related_id' => $locked->id,
            ]);
        });
    }

    public function adjust(
        User $user,
        int $subscriptionDelta,
        int $purchasedDelta,
        string $reason,
        User $admin,
    ): CreditLedger {
        if (! $this->isReady()) {
            throw new InvalidCreditOperationException('Система кредитов ещё не готова');
        }

        if ($subscriptionDelta === 0 && $purchasedDelta === 0) {
            throw new InvalidCreditOperationException('Укажите изменение баланса');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidCreditOperationException('Укажите причину корректировки');
        }

        return DB::transaction(function () use ($user, $subscriptionDelta, $purchasedDelta, $reason, $admin) {
            $account = $this->lockAccount($user);

            $newSubscription = $account->subscription_balance + $subscriptionDelta;
            $newPurchased = $account->purchased_balance + $purchasedDelta;

            if ($newSubscription < $account->subscription_held || $newSubscription < 0) {
                throw new InvalidCreditOperationException('Нельзя уменьшить кредиты подписки ниже зарезервированных');
            }

            if ($newPurchased < $account->purchased_held || $newPurchased < 0) {
                throw new InvalidCreditOperationException('Нельзя уменьшить купленные кредиты ниже зарезервированных');
            }

            $account->subscription_balance = $newSubscription;
            $account->purchased_balance = $newPurchased;
            $account->save();

            $amount = abs($subscriptionDelta) + abs($purchasedDelta);
            $direction = ($subscriptionDelta + $purchasedDelta) >= 0
                ? CreditLedgerDirection::Credit
                : CreditLedgerDirection::Debit;

            return $this->writeLedger($account, [
                'type' => CreditLedgerType::AdminAdjust,
                'direction' => $direction,
                'amount' => $amount,
                'subscription_delta' => $subscriptionDelta,
                'purchased_delta' => $purchasedDelta,
                'idempotency_key' => 'adjust:'.$user->id.':'.Str::uuid(),
                'description' => $reason,
                'user_label' => 'Корректировка администратором',
                'admin_user_id' => $admin->id,
            ]);
        });
    }

    public function refund(CreditLedger $original, string $reason, ?User $admin = null): CreditLedger
    {
        if (! $this->isReady()) {
            throw new InvalidCreditOperationException('Система кредитов ещё не готова');
        }

        if (! in_array($original->type, [CreditLedgerType::Spend, CreditLedgerType::Capture], true)) {
            throw new InvalidCreditOperationException('Вернуть можно только списание');
        }

        $refundKey = 'refund:'.$original->id;

        return DB::transaction(function () use ($original, $reason, $admin, $refundKey) {
            $existing = $this->findByIdempotency($refundKey);
            if ($existing) {
                return $existing;
            }

            $split = $original->source_split ?? [];
            $toSubscription = max(0, (int) ($split['subscription'] ?? abs(min(0, $original->subscription_delta))));
            $toPurchased = max(0, (int) ($split['purchased'] ?? abs(min(0, $original->purchased_delta))));

            $account = $this->lockAccount($original->user);
            $account->subscription_balance += $toSubscription;
            $account->purchased_balance += $toPurchased;
            $account->save();

            return $this->writeLedger($account, [
                'type' => CreditLedgerType::Refund,
                'direction' => CreditLedgerDirection::Credit,
                'amount' => $toSubscription + $toPurchased,
                'subscription_delta' => $toSubscription,
                'purchased_delta' => $toPurchased,
                'source_split' => [
                    'subscription' => $toSubscription,
                    'purchased' => $toPurchased,
                ],
                'idempotency_key' => $refundKey,
                'service_code' => $original->service_code,
                'operation_params' => $original->operation_params,
                'description' => $reason,
                'user_label' => 'Возврат '.CreditsFormatter::amount($toSubscription + $toPurchased),
                'admin_user_id' => $admin?->id,
                'related_type' => $original->getMorphClass(),
                'related_id' => $original->id,
            ]);
        });
    }

    /**
     * @return Collection<int, CreditLedger>
     */
    public function history(User $user, int $limit = 50): Collection
    {
        if (! $this->isReady()) {
            return collect();
        }

        return CreditLedger::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function historyForFrontend(User $user, int $limit = 50): array
    {
        if (! $this->isReady()) {
            return [];
        }

        $openHoldIds = CreditHold::query()
            ->where('user_id', $user->id)
            ->where('status', CreditHoldStatus::Held)
            ->pluck('id');

        // Открытый резерв показываем как «Зарезервировано».
        // Закрытый hold скрываем — иначе после capture кажется, что списали дважды.
        return CreditLedger::query()
            ->where('user_id', $user->id)
            ->where(function ($query) use ($openHoldIds) {
                $query->where('type', '!=', CreditLedgerType::Hold->value);

                if ($openHoldIds->isNotEmpty()) {
                    $query->orWhere(function ($holds) use ($openHoldIds) {
                        $holds->where('type', CreditLedgerType::Hold->value)
                            ->whereIn('related_id', $openHoldIds);
                    });
                }
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (CreditLedger $entry) => $this->presentLedger($entry))
            ->values()
            ->all();
    }

    public function releaseExpiredHolds(): int
    {
        if (! $this->isReady()) {
            return 0;
        }

        $released = 0;

        CreditHold::query()
            ->where('status', CreditHoldStatus::Held)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->each(function (CreditHold $hold) use (&$released) {
                $this->release($hold, CreditHoldStatus::Expired);
                $released++;
            });

        return $released;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentLedger(CreditLedger $entry): array
    {
        return [
            'id' => $entry->id,
            'type' => $entry->type->value,
            'type_label' => $entry->type->userLabel(),
            'direction' => $entry->direction->value,
            'amount' => $entry->amount,
            'subscription_delta' => $entry->subscription_delta,
            'purchased_delta' => $entry->purchased_delta,
            'available_after' => $entry->available_after,
            'user_label' => $this->presentUserLabel($entry),
            'description' => $entry->description,
            'service_code' => $entry->service_code,
            'created_at' => optional($entry->created_at)->format('d.m.Y H:i'),
        ];
    }

    private function presentUserLabel(CreditLedger $entry): string
    {
        $label = trim((string) ($entry->user_label ?: $entry->description ?: $entry->type->userLabel()));

        if ($entry->type === CreditLedgerType::Hold && ! str_starts_with(mb_strtolower($label), 'зарезервировано')) {
            return 'Зарезервировано: '.$label;
        }

        return $label !== '' ? $label : $entry->type->userLabel();
    }

    private function planCreditsPerPeriod(User $user): int
    {
        $subscription = $user->getSubscriptions();
        if (! $subscription) {
            return 0;
        }

        $plan = $subscription->plan ?? $subscription->getPlan();

        return $plan ? $this->planCreditsValue($plan) : 0;
    }

    private function planCreditsValue(SubscribersPlans $plan): int
    {
        if (! Schema::hasColumn($plan->getTable(), 'credits_per_period')) {
            return 0;
        }

        return max(0, (int) ($plan->credits_per_period ?? 0));
    }

    private function lockAccount(User $user): CreditAccount
    {
        $account = CreditAccount::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();

        if ($account) {
            return $account;
        }

        CreditAccount::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'subscription_balance' => 0,
                'purchased_balance' => 0,
                'subscription_held' => 0,
                'purchased_held' => 0,
            ],
        );

        /** @var CreditAccount $locked */
        $locked = CreditAccount::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->firstOrFail();

        return $locked;
    }

    /**
     * Письмо один раз за период, когда доступный остаток (тариф + купленные) дошёл до нуля.
     */
    private function notifyIfCreditsEnded(User $user): void
    {
        if ($this->bypassesCharges($user)) {
            return;
        }

        if (
            ! Schema::hasTable('credit_accounts')
            || ! Schema::hasColumn('credit_accounts', 'subscription_exhausted_notified_period')
        ) {
            return;
        }

        $shouldNotify = false;

        DB::transaction(function () use ($user, &$shouldNotify): void {
            $account = $this->lockAccount($user);
            if ($account->available() > 0) {
                return;
            }

            $periodKey = (string) ($account->last_granted_period_key ?: 'none');
            if ($account->subscription_exhausted_notified_period === $periodKey) {
                return;
            }

            $account->subscription_exhausted_notified_period = $periodKey;
            $account->save();
            $shouldNotify = true;
        });

        if ($shouldNotify) {
            $user->notify(new SubscriptionCreditsEndedNotification());
        }
    }

    /**
     * Чистый админ может тестировать инструменты без списания.
     * Если у того же пользователя есть роль подписчика — это кабинет, кредиты списываем.
     */
    private function bypassesCharges(User $user): bool
    {
        if (! ToolLimits::bypassesFor($user)) {
            return false;
        }

        return ! $user->hasRole('Подписчик');
    }

    public function findHoldByIdempotency(string $key): ?CreditHold
    {
        if ($key === '') {
            return null;
        }

        return CreditHold::query()->where('idempotency_key', $key)->first();
    }

    /**
     * Списывает резерв на фактическую сумму: меньше — возвращает разницу,
     * больше — добирает с доступного остатка, если хватает.
     *
     * @param  array<string, mixed>  $extraParams
     */
    public function settleOpenHold(string $idempotencyKey, int $actualAmount, array $extraParams = []): ?CreditLedger
    {
        if ($idempotencyKey === '' || ! $this->isReady()) {
            return null;
        }

        $hold = $this->findHoldByIdempotency($idempotencyKey);
        if (! $hold) {
            return null;
        }

        if ($hold->status === CreditHoldStatus::Captured) {
            return $this->findByIdempotency('capture:'.$hold->id);
        }

        if (! $hold->isActive()) {
            return null;
        }

        if ($actualAmount < 1) {
            return $this->release($hold);
        }

        $user = $hold->user;
        if ($user instanceof User && ($this->bypassesCharges($user) || (int) $hold->amount === 0)) {
            $this->mergeHoldParams($hold, $extraParams);

            return $this->capture($hold);
        }

        return DB::transaction(function () use ($hold, $actualAmount, $extraParams) {
            /** @var CreditHold $locked */
            $locked = CreditHold::query()->lockForUpdate()->findOrFail($hold->id);

            if ($locked->status === CreditHoldStatus::Captured) {
                return $this->findByIdempotency('capture:'.$locked->id);
            }

            if (! $locked->isActive()) {
                return null;
            }

            $params = is_array($locked->operation_params) ? $locked->operation_params : [];
            $params = array_merge($params, $extraParams);

            $account = $this->lockAccount($locked->user);

            if ($actualAmount < (int) $locked->amount) {
                $this->shrinkHoldTo($account, $locked, $actualAmount);
            } elseif ($actualAmount > (int) $locked->amount) {
                $extra = $actualAmount - (int) $locked->amount;
                if ($account->available() < $extra) {
                    $params['undercharged'] = true;
                    $params['requested_credits'] = $actualAmount;
                } else {
                    $this->growHoldBy($account, $locked, $extra);
                }
            }

            $locked->operation_params = $params;
            $locked->save();

            return $this->capture($locked);
        });
    }

    /**
     * @param  array<string, mixed>  $extraParams
     */
    private function mergeHoldParams(CreditHold $hold, array $extraParams): void
    {
        if ($extraParams === []) {
            return;
        }

        $params = is_array($hold->operation_params) ? $hold->operation_params : [];
        $hold->operation_params = array_merge($params, $extraParams);
        $hold->save();
    }

    private function shrinkHoldTo(CreditAccount $account, CreditHold $hold, int $actualAmount): void
    {
        $reduce = (int) $hold->amount - $actualAmount;
        if ($reduce <= 0) {
            return;
        }

        $fromPurchased = min((int) $hold->purchased_reserved, $reduce);
        $fromSubscription = $reduce - $fromPurchased;

        $account->purchased_held -= $fromPurchased;
        $account->subscription_held -= $fromSubscription;
        $account->save();

        $hold->purchased_reserved -= $fromPurchased;
        $hold->subscription_reserved -= $fromSubscription;
        $hold->amount = $actualAmount;
    }

    private function growHoldBy(CreditAccount $account, CreditHold $hold, int $extra): void
    {
        if ($extra <= 0) {
            return;
        }

        $fromSubscription = min($account->availableSubscription(), $extra);
        $fromPurchased = $extra - $fromSubscription;

        $account->subscription_held += $fromSubscription;
        $account->purchased_held += $fromPurchased;
        $account->save();

        $hold->subscription_reserved += $fromSubscription;
        $hold->purchased_reserved += $fromPurchased;
        $hold->amount += $extra;
    }

    /**
     * Списывает активный резерв по ключу. Повторный вызов идемпотентен.
     */
    public function captureOpenHold(string $idempotencyKey): ?CreditLedger
    {
        if ($idempotencyKey === '' || ! $this->isReady()) {
            return null;
        }

        $hold = $this->findHoldByIdempotency($idempotencyKey);
        if (! $hold) {
            return null;
        }

        if ($hold->status === CreditHoldStatus::Captured) {
            return $this->findByIdempotency('capture:'.$hold->id);
        }

        if (! $hold->isActive()) {
            return null;
        }

        return $this->capture($hold);
    }

    /**
     * Возвращает активный резерв по ключу. Повторный вызов безопасен.
     */
    public function releaseOpenHold(string $idempotencyKey): ?CreditLedger
    {
        if ($idempotencyKey === '' || ! $this->isReady()) {
            return null;
        }

        $hold = $this->findHoldByIdempotency($idempotencyKey);
        if (! $hold || ! $hold->isActive()) {
            return null;
        }

        return $this->release($hold);
    }

    private function findByIdempotency(string $key): ?CreditLedger
    {
        return CreditLedger::query()->where('idempotency_key', $key)->first();
    }

    private function latestGrantForPeriod(User $user, string $periodKey): ?CreditLedger
    {
        return CreditLedger::query()
            ->where('user_id', $user->id)
            ->where('type', CreditLedgerType::GrantSubscription)
            ->where('period_key', $periodKey)
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeLedger(CreditAccount $account, array $payload): CreditLedger
    {
        $account->refresh();

        return CreditLedger::create([
            'user_id' => $account->user_id,
            'type' => $payload['type'],
            'direction' => $payload['direction'],
            'amount' => $payload['amount'],
            'subscription_delta' => $payload['subscription_delta'],
            'purchased_delta' => $payload['purchased_delta'],
            'subscription_balance_after' => $account->subscription_balance,
            'purchased_balance_after' => $account->purchased_balance,
            'available_after' => $account->available(),
            'source_split' => $payload['source_split'] ?? null,
            'idempotency_key' => $payload['idempotency_key'] ?? null,
            'period_key' => $payload['period_key'] ?? null,
            'service_code' => $payload['service_code'] ?? null,
            'operation_params' => $payload['operation_params'] ?? null,
            'description' => $payload['description'] ?? null,
            'user_label' => $payload['user_label'] ?? null,
            'admin_user_id' => $payload['admin_user_id'] ?? null,
            'related_type' => $payload['related_type'] ?? null,
            'related_id' => $payload['related_id'] ?? null,
        ]);
    }

    private function recordBypass(User $user, CreditSpendRequest $request): CreditLedger
    {
        $existing = $this->findByIdempotency($request->idempotencyKey);
        if ($existing) {
            return $existing;
        }

        $account = $this->lockAccount($user);

        return $this->writeLedger($account, [
            'type' => CreditLedgerType::Spend,
            'direction' => CreditLedgerDirection::Debit,
            'amount' => 0,
            'subscription_delta' => 0,
            'purchased_delta' => 0,
            'source_split' => ['subscription' => 0, 'purchased' => 0],
            'idempotency_key' => $request->idempotencyKey,
            'service_code' => $request->serviceCode,
            'operation_params' => $request->operationParams,
            'description' => 'Обход списания для администратора',
            'user_label' => $request->userLabel ?? 'Операция без списания кредитов',
        ]);
    }

    private function recordBypassHold(
        User $user,
        CreditSpendRequest $request,
        ?\DateTimeInterface $expiresAt,
    ): CreditHold {
        $existingHold = CreditHold::query()
            ->where('idempotency_key', $request->idempotencyKey)
            ->first();

        if ($existingHold) {
            return $existingHold;
        }

        return CreditHold::create([
            'user_id' => $user->id,
            'amount' => 0,
            'subscription_reserved' => 0,
            'purchased_reserved' => 0,
            'status' => CreditHoldStatus::Held,
            'idempotency_key' => $request->idempotencyKey,
            'service_code' => $request->serviceCode,
            'operation_params' => $request->operationParams,
            'expires_at' => $expiresAt ?? now()->addHour(),
        ]);
    }
}
