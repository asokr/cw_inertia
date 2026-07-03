<?php

namespace App\Services\Subscriber;

use App\Enums\SubscriptionsControlActionEnum;
use App\Http\Traits\SubscriptionsTrait;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\SubscribersSubscriptionsControl;
use App\Models\User;
use Carbon\Carbon;

class SubscriptionManagementService
{
    use SubscriptionsTrait;

    /**
     * @return array<string, mixed>|null
     */
    public function getCurrent(User $user): ?array
    {
        $subscriberId = $user->subscriberId();

        if (! $subscriberId) {
            return null;
        }

        $subscription = SubscribersSubscriptions::select([
            'id',
            'subscribers_id',
            'plan_id',
            'status',
            'limits_plan',
            'limits_month',
            'extra_limits_month',
            'start_date',
            'end_date',
        ])
            ->where([
                'subscribers_id' => $subscriberId,
                'status' => 1,
            ])
            ->first();

        if (! $subscription) {
            return null;
        }

        $plan = SubscribersPlans::select([
            'id',
            'price',
            'description',
            'name',
            'limits_plan',
            'limits_month',
        ])
            ->where('id', $subscription->plan_id)
            ->first();

        $next = SubscribersSubscriptionsControl::select(['action'])
            ->where(['subscription_id' => $subscription->id])
            ->get()
            ->toArray();

        return [
            'subscription' => $subscription,
            'plan' => $plan,
            'next' => $next,
        ];
    }

    /**
     * @return array{success: bool, messages: array<int, string>}
     */
    public function unsubscribe(User $user, int $subscriptionId): array
    {
        $subscription = SubscribersSubscriptions::find($subscriptionId);

        if (! $subscription || $subscription->subscribers_id !== $user->subscriber->id) {
            return ['success' => false, 'messages' => ['Это не ваша подписка']];
        }

        $model = SubscribersSubscriptionsControl::create([
            'subscription_id' => $subscriptionId,
            'action' => SubscriptionsControlActionEnum::STOP,
        ]);

        if (! $model) {
            return ['success' => false, 'messages' => ['Не удалось отменить подписку']];
        }

        return ['success' => true, 'messages' => ['Подписка отменена']];
    }

    /**
     * @return array{success: bool, messages: array<int, string>}
     */
    public function resubscribe(User $user, int $subscriptionId): array
    {
        $subscription = SubscribersSubscriptions::find($subscriptionId);

        if (! $subscription || $subscription->subscribers_id !== $user->subscriber->id) {
            return ['success' => false, 'messages' => ['Это не ваша подписка']];
        }

        $deleted = SubscribersSubscriptionsControl::where([
            'subscription_id' => $subscriptionId,
            'action' => SubscriptionsControlActionEnum::STOP,
        ])->delete();

        if (! $deleted) {
            return ['success' => false, 'messages' => ['Не удалось возобновить подписку']];
        }

        return ['success' => true, 'messages' => ['Подписка возобновлена']];
    }

    /**
     * @return array{success: bool, messages: array<int, string>, data?: array<string, mixed>}
     */
    public function changePlan(User $user, int $planId): array
    {
        $plan = SubscribersPlans::find($planId);

        if (! $plan) {
            return ['success' => false, 'messages' => ['Такого тарифа не существует']];
        }

        $subscription = $user->getSubscriptions();
        $next = [];
        $messages = ['Вы перешли на новый тариф'];

        if (! $subscription) {
            return $this->activateNewSubscription($user, $plan, $messages);
        }

        if (! $subscription->status) {
            return $this->reactivateSubscription($user, $subscription, $plan);
        }

        if ($plan->price >= $subscription->plan->price) {
            return $this->upgradeSubscription($user, $subscription, $plan, $messages, $next);
        }

        return $this->scheduleDowngrade($user, $subscription, $plan, $next, $messages);
    }

    /**
     * @return array{success: bool, messages: array<int, string>}
     */
    private function activateNewSubscription(User $user, SubscribersPlans $plan, array $messages): array
    {
        if (! $user->isEnoughFunds($plan->price, 'RUB')) {
            return ['success' => false, 'messages' => ['Недостаточно средств для перехода']];
        }

        $endDate = Carbon::now()->addDays($plan->duration);
        $user->givePermissionTo($plan->permissions);

        $subscription = SubscribersSubscriptions::create([
            'subscribers_id' => $user->subscriber->id,
            'plan_id' => $plan->id,
            'limits_month' => $plan->limits_month,
            'limits_plan' => $plan->limits_plan,
            'end_date' => $endDate,
            'status' => 1,
        ]);

        if (! $subscription) {
            return ['success' => false, 'messages' => ['Что-то пошло не так']];
        }

        foreach ($plan->limits_plan as $limitName => $limitCount) {
            $this->syncLimits($user->subscriber->id, $limitName);
        }

        charge($plan->price, 'RUB')->from($user)->commit();

        return ['success' => true, 'messages' => ['Тариф выбран']];
    }

    /**
     * @return array{success: bool, messages: array<int, string>}
     */
    private function reactivateSubscription(User $user, SubscribersSubscriptions $subscription, SubscribersPlans $plan): array
    {
        if (! $user->isEnoughFunds($plan->price, 'RUB')) {
            return ['success' => false, 'messages' => ['Недостаточно средств для перехода']];
        }

        $endDate = Carbon::now()->addDays($plan->duration);
        $user->givePermissionTo($plan->permissions);

        $status = $subscription->update([
            'plan_id' => $plan->id,
            'limits_month' => $plan->limits_month,
            'limits_plan' => $plan->limits_plan,
            'end_date' => $endDate,
            'status' => 1,
        ]);

        if (! $status) {
            return ['success' => false, 'messages' => ['Что-то пошло не так']];
        }

        foreach ($plan->limits_plan as $limitName => $limitCount) {
            $this->syncLimits($user->subscriber->id, $limitName);
        }

        charge($plan->price, 'RUB')->from($user)->meta([
            'description' => "Активация подписки с тарифом {$plan->name}",
        ])->commit();

        return ['success' => true, 'messages' => ['Тариф активен']];
    }

    /**
     * @return array{success: bool, messages: array<int, string>, data?: array<string, mixed>}
     */
    private function upgradeSubscription(
        User $user,
        SubscribersSubscriptions $subscription,
        SubscribersPlans $plan,
        array $messages,
        array $next,
    ): array {
        if (! $user->isEnoughFunds($plan->price, 'RUB')) {
            return ['success' => false, 'messages' => ['Недостаточно средств']];
        }

        SubscribersSubscriptionsControl::where([
            'subscription_id' => $subscription->id,
            'action' => SubscriptionsControlActionEnum::LOWER,
        ])->delete();

        $endDate = Carbon::createFromDate($subscription->getRawOriginal('end_date'));
        $remainingDays = round(Carbon::now()->diffInDays($endDate));
        $newDayCost = $plan->price / $plan->duration;
        $oldDayCost = $subscription->plan->price / $subscription->plan->duration;
        $oldRemainingValue = $remainingDays * $oldDayCost;
        $addDaysToPlan = round($oldRemainingValue / $newDayCost);

        $remainingMonthLimits = [];
        foreach ($plan->limits_month as $key => $value) {
            $remainingMonthLimits[$key] = isset($subscription->limits_month[$key])
                ? (int) $value + (int) $subscription->limits_month[$key]
                : (int) $value;
        }

        $remainingPlanLimits = [];
        foreach ($plan->limits_plan as $key => $value) {
            $planCount = $this->getUsedLimits($user->subscriber->id, $key);
            if ($planCount) {
                $remainingPlanLimits[$key] = (int) $value - (int) $planCount;
                if ($remainingPlanLimits[$key] < 0) {
                    return ['success' => false, 'messages' => ['Не хватает лимита']];
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

        $user->syncPermissions($plan->permissions);
        charge($plan->price, 'RUB')->from($user)->commit();

        return [
            'success' => true,
            'messages' => $messages,
            'data' => [
                'subscription' => $subscription,
                'plan' => $plan,
                'next' => $next,
            ],
        ];
    }

    /**
     * @return array{success: bool, messages: array<int, string>, data?: array<string, mixed>}
     */
    private function scheduleDowngrade(
        User $user,
        SubscribersSubscriptions $subscription,
        SubscribersPlans $plan,
        array $next,
        array $messages,
    ): array {
        SubscribersSubscriptionsControl::where([
            'subscription_id' => $subscription->id,
            'action' => SubscriptionsControlActionEnum::LOWER,
        ])->delete();

        $model = SubscribersSubscriptionsControl::create([
            'subscription_id' => $subscription->id,
            'action' => SubscriptionsControlActionEnum::LOWER,
            'config' => ['plan_id' => $plan->id],
        ]);

        if (! $model) {
            return ['success' => false, 'messages' => ['Не удалось отменить подписку']];
        }

        $next = ['action' => SubscriptionsControlActionEnum::LOWER];
        $messages = ['Тариф сменится на более низкий по окончании текущего перода'];

        return [
            'success' => true,
            'messages' => $messages,
            'data' => [
                'subscription' => $subscription,
                'plan' => $plan,
                'next' => $next,
            ],
        ];
    }
}