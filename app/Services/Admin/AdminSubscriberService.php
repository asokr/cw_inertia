<?php

namespace App\Services\Admin;

use App\Http\Traits\SubscriptionsTrait;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use O21\LaravelWallet\Exception\InsufficientFundsException;
use O21\LaravelWallet\Models\Transaction;
use O21\Numeric\Numeric as NumericValue;

class AdminSubscriberService
{
    use SubscriptionsTrait;

    public function paginate(array $filters): LengthAwarePaginator
    {
        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = ($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $perPage = (int) ($filters['per_page'] ?? 15);

        $query = Subscribers::select(['id', 'user_id', 'status'])
            ->with([
                'user' => function ($query) {
                    $query->select(['id', 'name', 'email', 'phone', 'vk_id', 'yandex_id'])
                        ->with(['balances' => function ($query) {
                            $query->select(['payable_id', 'value', 'value_pending', 'value_on_hold']);
                        }]);
                },
            ])
            ->with([
                'subscriptions' => function ($query) {
                    $query->select([
                        'subscribers_id', 'plan_id', 'limits_plan', 'limits_month',
                        'extra_limits_plan', 'extra_limits_month', 'start_date', 'end_date', 'status',
                    ])
                        ->with(['plan' => function ($query) {
                            $query->select(['id', 'name', 'limits_plan', 'limits_month']);
                        }])
                        ->where('status', 1);
                },
            ]);

        if (! empty($filters['plan_id'])) {
            $planId = (int) $filters['plan_id'];
            $query->whereHas('subscriptions', function ($subQuery) use ($planId) {
                $subQuery->where('status', 1)->where('plan_id', $planId);
            });
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->whereHas('user', function ($userQuery) use ($search) {
                $userQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('surname', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return $query->orderBy($sortField, $sortOrder)->paginate($perPage);
    }

    public function search(string $query): Collection
    {
        return Subscribers::select(['id', 'user_id', 'status'])
            ->with([
                'user' => function ($query) {
                    $query->select(['id', 'name', 'email', 'phone', 'vk_id', 'yandex_id'])
                        ->with(['balances' => function ($query) {
                            $query->select(['payable_id', 'value', 'value_pending', 'value_on_hold']);
                        }]);
                },
            ])
            ->with([
                'subscriptions' => function ($query) {
                    $query->select([
                        'subscribers_id', 'plan_id', 'limits_plan', 'limits_month',
                        'extra_limits_plan', 'extra_limits_month', 'start_date', 'end_date', 'status',
                    ])
                        ->with(['plan' => function ($query) {
                            $query->select(['id', 'name', 'limits_plan', 'limits_month']);
                        }])
                        ->where('status', 1);
                },
            ])
            ->whereHas('user', function ($userQuery) use ($query) {
                $userQuery->where('name', 'like', "%{$query}%")
                    ->orWhere('surname', 'like', "%{$query}%")
                    ->orWhere('email', 'like', "%{$query}%");
            })
            ->get();
    }

    public function getDetail(Subscribers $subscriber): array
    {
        $subscriber->load([
            'user' => function ($query) {
                $query->select(['id', 'name', 'email', 'phone'])
                    ->with(['balances' => function ($query) {
                        $query->select(['payable_id', 'value', 'value_pending', 'value_on_hold']);
                    }]);
            },
            'subscriptions' => function ($query) {
                $query->select([
                    'id', 'subscribers_id', 'plan_id', 'limits_plan', 'extra_limits_plan',
                    'limits_month', 'extra_limits_month', 'start_date', 'end_date', 'status',
                ])
                    ->with(['plan' => function ($query) {
                        $query->select(['id', 'name', 'price', 'duration']);
                    }]);
            },
        ]);

        $user = $subscriber->user;

        $transactions = Transaction::query()
            ->relatedTo($user)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(function (Transaction $transaction) use ($user) {
                $isIncoming = $transaction->to_id === $user->id
                    && $transaction->to_type === $user->getMorphClass();

                $amount = (float) ($isIncoming ? $transaction->received : $transaction->amount);
                if (! $isIncoming) {
                    $amount = -$amount;
                }

                return [
                    'id' => $transaction->id,
                    'created_at' => optional($transaction->created_at)->format('d.m.Y H:i'),
                    'amount' => $amount,
                    'type' => $isIncoming ? 'deposit' : 'withdrawal',
                    'status' => $transaction->status,
                    'description' => data_get($transaction->meta, 'description'),
                    'transaction_id' => data_get($transaction->meta, 'transaction_id'),
                    'meta' => $transaction->meta,
                ];
            })->values();

        $totalDepositsRaw = Transaction::query()
            ->to($user)
            ->accountable()
            ->where('currency', 'RUB')
            ->sum('received');

        return [
            'subscriber' => $subscriber,
            'payments' => $transactions,
            'total_deposits' => $this->normalizeNumeric($totalDepositsRaw),
        ];
    }

    public function updateProfile(Subscribers $subscriber, array $data): void
    {
        $user = User::findOrFail($data['user_id']);
        $user->name = $data['user']['name'];
        $user->email = $data['user']['email'];
        if (array_key_exists('phone', $data['user'])) {
            $user->phone = $data['user']['phone'];
        }
        $user->save();

        if (array_key_exists('status', $data)) {
            $subscriber->status = $data['status'];
            $subscriber->save();
        }

        if (is_array($data['subscriptions'] ?? null)) {
            foreach ($data['subscriptions'] as $subscriptionPayload) {
                $subscriptionId = data_get($subscriptionPayload, 'id');
                $extraLimits = data_get($subscriptionPayload, 'extra_limits_month');

                if (! $subscriptionId || ! is_array($extraLimits)) {
                    continue;
                }

                $subscription = SubscribersSubscriptions::query()
                    ->where('subscribers_id', $subscriber->id)
                    ->where('id', $subscriptionId)
                    ->first();

                if (! $subscription) {
                    continue;
                }

                $normalized = [];
                foreach ($extraLimits as $limitName => $limitValue) {
                    if ($limitName === null || $limitName === '') {
                        continue;
                    }

                    if (is_array($limitValue)) {
                        $limitValue = reset($limitValue);
                    }

                    $normalized[$limitName] = max(0, (int) ($limitValue ?? 0));
                }

                if (($subscription->extra_limits_month ?? []) !== $normalized) {
                    $subscription->extra_limits_month = $normalized;
                    $subscription->save();
                }
            }
        }

        if (! empty($data['plan_id'])) {
            $this->changePlan($subscriber, (int) $data['plan_id']);
        }
    }

    public function deposit(Subscribers $subscriber, float $amount, ?User $adminUser): float
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Сумма пополнения должна быть больше нуля');
        }

        $description = sprintf('Пополнение из админ панели (%s)', $adminUser?->email ?? 'system');

        $transaction = deposit($amount, 'RUB')
            ->to($subscriber->user)
            ->overcharge()
            ->meta([
                'description' => $description,
                'admin_user_id' => $adminUser?->id,
                'admin_user_email' => $adminUser?->email,
                'operation' => 'admin_manual_deposit',
            ])
            ->commit();

        if ($transaction === false) {
            throw new \RuntimeException('Не удалось создать транзакцию пополнения.');
        }

        $subscriber->user->refresh();

        return $this->normalizeNumeric(data_get($subscriber->user->balances, 'value'));
    }

    public function withdraw(Subscribers $subscriber, float $amount, string $comment, ?User $adminUser): float
    {
        $amount = round(abs($amount), 2);

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Сумма списания должна быть больше нуля');
        }

        $transaction = charge($amount, 'RUB')
            ->from($subscriber->user)
            ->meta([
                'description' => $comment,
                'admin_user_id' => $adminUser?->id,
                'admin_user_email' => $adminUser?->email,
                'operation' => 'admin_manual_withdrawal',
            ])
            ->commit();

        if ($transaction === false) {
            throw new \RuntimeException('Не удалось создать транзакцию списания.');
        }

        $subscriber->user->refresh();

        return $this->normalizeNumeric(data_get($subscriber->user->balances, 'value'));
    }

    public function reverseTransaction(Subscribers $subscriber, string $transactionId, string $comment, ?User $adminUser): float
    {
        $user = $subscriber->user;

        $originalTransaction = Transaction::query()
            ->relatedTo($user)
            ->whereKey($transactionId)
            ->first();

        if (! $originalTransaction) {
            throw new \InvalidArgumentException('Операция не найдена');
        }

        $originalMeta = (array) ($originalTransaction->meta ?? []);
        $operation = data_get($originalMeta, 'operation');

        $isIncoming = $originalTransaction->to_id === $user->id
            && $originalTransaction->to_type === $user->getMorphClass();

        if (! empty($originalMeta['reversal_transaction_id']) || $operation === 'admin_manual_reversal') {
            throw new \InvalidArgumentException('Для этой операции уже создана отмена');
        }

        if ($isIncoming && $operation !== 'admin_manual_deposit') {
            throw new \InvalidArgumentException('Отменить можно только ручные пополнения');
        }

        $amount = abs(round($this->normalizeNumeric(
            $isIncoming ? $originalTransaction->received : $originalTransaction->amount
        ), 2));

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Сумма операции недоступна для отмены');
        }

        DB::transaction(function () use ($isIncoming, $amount, $user, $comment, $adminUser, $originalTransaction, &$originalMeta) {
            if ($isIncoming) {
                $newTransaction = charge($amount, 'RUB')
                    ->from($user)
                    ->meta([
                        'description' => $comment,
                        'admin_user_id' => $adminUser?->id,
                        'admin_user_email' => $adminUser?->email,
                        'operation' => 'admin_manual_reversal',
                        'reversed_transaction_id' => $originalTransaction->id,
                        'reversal_of' => 'deposit',
                    ])
                    ->commit();
            } else {
                $newTransaction = deposit($amount, 'RUB')
                    ->to($user)
                    ->overcharge()
                    ->meta([
                        'description' => $comment,
                        'admin_user_id' => $adminUser?->id,
                        'admin_user_email' => $adminUser?->email,
                        'operation' => 'admin_manual_reversal',
                        'reversed_transaction_id' => $originalTransaction->id,
                        'reversal_of' => 'withdrawal',
                    ])
                    ->commit();
            }

            if ($newTransaction === false) {
                throw new \RuntimeException('Не удалось создать транзакцию отмены.');
            }

            $originalMeta['reversal_transaction_id'] = $newTransaction->id;
            $originalMeta['reversal_comment'] = $comment;
            $originalMeta['reversal_admin_user_id'] = $adminUser?->id;
            $originalMeta['reversal_admin_user_email'] = $adminUser?->email;
            $originalTransaction->meta = $originalMeta;
            $originalTransaction->save();
        });

        $user->refresh();

        return $this->normalizeNumeric(data_get($user->balances, 'value'));
    }

    private function changePlan(Subscribers $subscriber, int $planId): void
    {
        $plan = SubscribersPlans::find($planId);
        if (! $plan) {
            return;
        }

        $subscription = SubscribersSubscriptions::where('subscribers_id', $subscriber->id)->first();
        $user = $subscriber->user;

        if (! $subscription) {
            $endDate = Carbon::now()->addDays($plan->duration);
            $permissions = array_merge($plan->permissions, ['subscriber']);
            $user->syncPermissions($permissions);

            SubscribersSubscriptions::create([
                'subscribers_id' => $subscriber->id,
                'plan_id' => $planId,
                'limits_month' => $plan->limits_month,
                'limits_plan' => $plan->limits_plan,
                'end_date' => $endDate,
                'status' => 1,
            ]);

            foreach ($plan->limits_plan as $limitName => $limitCount) {
                $this->syncLimits($subscriber->id, $limitName);
            }

            return;
        }

        if ($plan->price > $subscription->plan->price) {
            $endDate = Carbon::createFromDate($subscription->end_date);
            $remainingDays = $endDate->diffInDays(Carbon::now());
            $newDayCost = $plan->price / $plan->duration;
            $oldDayCost = $subscription->plan->price / $subscription->plan->duration;
            $oldRemainingValue = $remainingDays * $oldDayCost;
            $addDaysToPlan = round($oldRemainingValue / $newDayCost);

            $remainingMonthLimits = [];
            foreach ($plan->limits_month as $key => $value) {
                $remainingMonthLimits[$key] = (int) $value + (int) ($subscription->limits_month[$key] ?? 0);
            }

            $remainingPlanLimits = [];
            foreach ($plan->limits_plan as $key => $value) {
                $planCount = $this->getUsedLimits($subscriber->id, $key);
                if ($planCount) {
                    $remainingPlanLimits[$key] = (int) $value - (int) $planCount;
                    if ($remainingPlanLimits[$key] < 0) {
                        throw new \InvalidArgumentException('Не хватает лимита: ' . $key);
                    }
                } else {
                    $remainingPlanLimits[$key] = (int) $value;
                }
            }

            $subscription->plan_id = $plan->id;
            $subscription->limits_plan = $remainingPlanLimits;
            $subscription->limits_month = $remainingMonthLimits;
            $subscription->start_date = Carbon::now();
            $subscription->end_date = Carbon::now()->addDays($plan->duration + $addDaysToPlan);
            $subscription->save();
        }
    }

    private function normalizeNumeric(mixed $value): float
    {
        if ($value instanceof NumericValue) {
            return (float) $value->get();
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            return (float) $value;
        }

        return 0.0;
    }
}