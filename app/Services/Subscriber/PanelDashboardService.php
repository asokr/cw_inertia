<?php

namespace App\Services\Subscriber;

use App\Models\PaymentsTransaction;
use App\Models\Subscribers\Oz\OzCabinet;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\Feedbacks\WbFeedbacksSettings;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Models\User;
use App\Services\Credits\CreditBillingService;
use App\Services\SubscriptionService;
use App\Support\PlanLimitPresenter;
use Illuminate\Support\Facades\Schema;

class PanelDashboardService
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly CreditBillingService $creditBilling,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(User $user): array
    {
        $subscriberId = $this->resolveSubscriberId($user);

        return [
            'subscription' => $this->formatSubscription($user, $subscriberId),
            'stats' => $this->buildStats($user, $subscriberId),
            'recent_payments' => $this->recentPayments($user),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function formatSubscription(User $user, ?int $subscriberId): ?array
    {
        $subscription = $this->resolveSubscription($user);

        if (! $subscription) {
            return null;
        }

        $plan = $subscription->plan ?? SubscribersPlans::query()->find($subscription->plan_id);
        $limitsSubscription = $this->resolveActiveSubscription($subscriberId) ?? $subscription;

        $remainingLimits = $this->buildRemainingLimits($limitsSubscription);

        return [
            'plan_name' => $plan?->name,
            'end_date' => $subscription->end_date,
            'status' => (int) $subscription->status,
            'remaining_limits' => $remainingLimits,
            'remaining_limits_display' => PlanLimitPresenter::displayEntries($remainingLimits),
            'credits' => $this->creditBilling->getBalance($user)->toFrontendArray(),
        ];
    }

    private function resolveSubscription(User $user): ?SubscribersSubscriptions
    {
        $subscription = $user->getSubscriptions();

        if (! $subscription) {
            return null;
        }

        if ((int) $subscription->status === 1) {
            $this->subscriptionService->setSubscription($subscription);
            $this->subscriptionService->checkAndManageSubscription();
            $subscription->refresh();
        }

        return $subscription;
    }

    private function resolveActiveSubscription(?int $subscriberId): ?SubscribersSubscriptions
    {
        if (! $subscriberId) {
            return null;
        }

        return SubscribersSubscriptions::query()
            ->where([
                'subscribers_id' => $subscriberId,
                'status' => 1,
            ])
            ->first();
    }

    private function resolveSubscriberId(User $user): ?int
    {
        return Subscribers::query()
            ->where('user_id', $user->id)
            ->value('id');
    }

    /**
     * @return array<string, int>
     */
    private function buildRemainingLimits(SubscribersSubscriptions $subscription): array
    {
        $limits = [];

        $planLimits = is_array($subscription->limits_plan) ? $subscription->limits_plan : [];

        // Prefer unified WB cabinets; fall back to max of legacy per-tool keys.
        if (array_key_exists('wb_cabinets', $planLimits)) {
            $limits['wb_cabinets'] = (int) $planLimits['wb_cabinets'];
        } else {
            $legacyValues = [];
            foreach (['feedbacks_clients', 'price_calc_clients', 'adverts_clients'] as $legacyKey) {
                if (array_key_exists($legacyKey, $planLimits)) {
                    $legacyValues[] = (int) $planLimits[$legacyKey];
                }
            }
            if ($legacyValues !== []) {
                $limits['wb_cabinets'] = max($legacyValues);
            }
        }

        if (array_key_exists('oz_cabinets', $planLimits)) {
            $limits['oz_cabinets'] = (int) $planLimits['oz_cabinets'];
        }

        if (array_key_exists('repricer_nmid', $planLimits)) {
            $limits['repricer_nmid'] = (int) $planLimits['repricer_nmid'];
        }

        return PlanLimitPresenter::normalizeRemainingMap($limits);
    }

    /**
     * @return array<string, int>
     */
    private function buildStats(User $user, ?int $subscriberId): array
    {
        $userId = $user->id;

        $wbCabinetsCount = $this->countCabinets(
            WbCabinet::class,
            fn ($query) => $query->where('user_id', $userId)
        );

        $ozCabinetsCount = $this->countCabinets(
            OzCabinet::class,
            fn ($query) => $query->where('user_id', $userId)
        );

        // Unified cabinets are shared across tools — report the same count per tool,
        // but do not multiply them in the total.
        $cabinetsByTool = [
            'wb_feedbacks' => $wbCabinetsCount,
            'wb_profitability' => $wbCabinetsCount,
            'wb_price_calc' => $wbCabinetsCount,
            'wb_repricer' => $wbCabinetsCount,
            'wb_ai_cabinet_analyzer' => $wbCabinetsCount,
            'oz_price_calc' => $ozCabinetsCount,
            'oz_ai_cabinet_analyzer' => $ozCabinetsCount,
        ];

        $activeBots = $this->countCabinets(
            WbFeedbacksSettings::class,
            fn ($query) => $query
                ->where('bot_status', true)
                ->whereHas('cabinet', fn ($q) => $q->where('user_id', $userId))
        );

        return [
            'cabinets_total' => $wbCabinetsCount + $ozCabinetsCount,
            'active_bots' => $activeBots,
            'cabinets_by_tool' => $cabinetsByTool,
        ];
    }

    /**
     * @param  class-string  $modelClass
     * @param  callable(\Illuminate\Database\Eloquent\Builder): \Illuminate\Database\Eloquent\Builder  $scope
     */
    private function countCabinets(string $modelClass, callable $scope): int
    {
        $model = new $modelClass;

        if (! Schema::hasTable($model->getTable())) {
            return 0;
        }

        return (int) $scope($modelClass::query())->count();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentPayments(User $user): array
    {
        if (! Schema::hasTable((new PaymentsTransaction)->getTable())) {
            return [];
        }

        return PaymentsTransaction::query()
            ->select([
                'id',
                'amount',
                'description',
                'status',
                'system',
                'created_at',
            ])
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(static fn (PaymentsTransaction $transaction) => [
                'id' => $transaction->id,
                'amount' => $transaction->amount,
                'description' => $transaction->description,
                'status' => $transaction->status,
                'system' => $transaction->system,
                'created_at' => $transaction->created_at,
            ])
            ->all();
    }

}