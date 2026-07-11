<?php

namespace App\Services\Subscriber;

use App\Enums\SubscriptionsControlActionEnum;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptionsControl;
use App\Models\User;
use Carbon\Carbon;

class PlansPageService
{
    public function __construct(
        private readonly SubscriptionManagementService $subscriptionService,
    ) {
    }

    /**
     * @return array{
     *     plans: array<int, array<string, mixed>>,
     *     subscription_data: ?array<string, mixed>,
     *     next_actions: array<int, array<string, mixed>>,
     *     pending_downgrade: ?array<string, mixed>,
     * }
     */
    public function forUser(User $user): array
    {
        $plans = SubscribersPlans::select([
            'id',
            'description',
            'duration',
            'name',
            'price',
            'limits_plan',
            'limits_month',
        ])
            ->where(['status' => 1, 'hidden' => 0])
            ->orderBy('price')
            ->get()
            ->toArray();

        $subscriptionData = $this->subscriptionService->getCurrent($user);
        $currentPlanId = $subscriptionData['subscription']->plan_id ?? null;
        $currentPlanPrice = $subscriptionData['plan']->price ?? null;
        $subscriberId = $user->subscriberId();

        $pendingDowngrade = $this->resolvePendingDowngrade($subscriptionData, $subscriberId);

        $visiblePlans = collect($plans);
        $recommendedId = $this->resolveRecommendedPlanId($visiblePlans);
        $pendingPlanId = $pendingDowngrade['plan_id'] ?? null;

        $enrichedPlans = $visiblePlans
            ->map(function (array $plan) use (
                $currentPlanId,
                $currentPlanPrice,
                $recommendedId,
                $pendingPlanId,
                $subscriberId,
            ) {
                $plan['is_current'] = $currentPlanId !== null && (int) $plan['id'] === (int) $currentPlanId;
                $plan['lower'] = $currentPlanPrice !== null && (float) $plan['price'] < (float) $currentPlanPrice;
                $plan['recommended'] = (int) $plan['id'] === $recommendedId;
                $plan['is_pending_downgrade'] = $pendingPlanId !== null && (int) $plan['id'] === (int) $pendingPlanId;
                $plan['downgrade_overages'] = $plan['lower'] && $subscriberId
                    ? $this->subscriptionService->previewPlanLimitOverages($subscriberId, $plan['limits_plan'] ?? [])
                    : [];

                return $plan;
            })
            ->values()
            ->all();

        $nextActions = [];

        if ($subscriptionData) {
            $nextActions = SubscribersSubscriptionsControl::select(['action', 'config'])
                ->where(['subscription_id' => $subscriptionData['subscription']->id])
                ->get()
                ->toArray();
        }

        return [
            'plans' => $enrichedPlans,
            'subscription_data' => $subscriptionData,
            'next_actions' => $nextActions,
            'pending_downgrade' => $pendingDowngrade,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $subscriptionData
     * @return array<string, mixed>|null
     */
    private function resolvePendingDowngrade(?array $subscriptionData, ?int $subscriberId): ?array
    {
        if (! $subscriptionData || ! $subscriberId) {
            return null;
        }

        $control = SubscribersSubscriptionsControl::query()
            ->select(['action', 'config'])
            ->where([
                'subscription_id' => $subscriptionData['subscription']->id,
                'action' => SubscriptionsControlActionEnum::LOWER,
            ])
            ->first();

        if (! $control) {
            return null;
        }

        $pendingPlanId = (int) ($control->config['plan_id'] ?? 0);
        $pendingPlan = $pendingPlanId > 0
            ? SubscribersPlans::query()->select(['id', 'name', 'limits_plan'])->find($pendingPlanId)
            : null;

        if (! $pendingPlan) {
            return null;
        }

        $endDate = Carbon::createFromDate($subscriptionData['subscription']->getRawOriginal('end_date'));

        return [
            'plan_id' => $pendingPlan->id,
            'plan_name' => $pendingPlan->name,
            'period_end' => $endDate->format('d.m.Y'),
            'limit_overages' => $this->subscriptionService->previewPlanLimitOverages(
                $subscriberId,
                $pendingPlan->limits_plan ?? [],
            ),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $plans
     */
    private function resolveRecommendedPlanId($plans): ?int
    {
        if ($plans->isEmpty()) {
            return null;
        }

        $middleIndex = (int) floor(($plans->count() - 1) / 2);

        return (int) $plans->values()->get($middleIndex)['id'];
    }
}