<?php

namespace App\Services\Subscriber;

use App\Models\PaymentsTransaction;
use App\Models\Subscribers\Oz\Feedbacks\FeedbacksClients as OzFeedbacksClients;
use App\Models\Subscribers\Oz\PriceCalc\OzPriceCalcCabinet;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\Feedbacks\WbFeedbacksSettings;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\PlanLimitPresenter;
use Illuminate\Support\Facades\Schema;

class PanelDashboardService
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
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
            'remaining_limits_display' => PlanLimitPresenter::displayEntries($remainingLimits, null),
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

        foreach ($this->monthlyLimitKeys() as $key) {
            $remaining = $subscription->getMonthLimit($key);
            if ($remaining !== false) {
                $limits[$key] = (int) $remaining;
            }
        }

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

        foreach (['oz_feedbacks_clients', 'oz_price_calc_clients', 'repricer_nmid'] as $key) {
            if (array_key_exists($key, $planLimits)) {
                $limits[$key] = (int) $planLimits[$key];
            }
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

        $ozFeedbacksCount = $this->countCabinets(
            OzFeedbacksClients::class,
            fn ($query) => $query->where('user_id', $userId)
        );

        $ozPriceCalcCount = $this->countCabinets(
            OzPriceCalcCabinet::class,
            fn ($query) => $query->where('user_id', $userId)
        );

        // Unified WB cabinets are shared across tools — report the same count per WB tool,
        // but do not multiply them in the total.
        $cabinetsByTool = [
            'wb_feedbacks' => $wbCabinetsCount,
            'wb_profitability' => $wbCabinetsCount,
            'wb_price_calc' => $wbCabinetsCount,
            'wb_repricer' => $wbCabinetsCount,
            'wb_ai_cabinet_analyzer' => $wbCabinetsCount,
            'oz_feedbacks' => $ozFeedbacksCount,
            'oz_price_calc' => $ozPriceCalcCount,
        ];

        $activeBots = $this->countCabinets(
            WbFeedbacksSettings::class,
            fn ($query) => $query
                ->where('bot_status', true)
                ->whereHas('cabinet', fn ($q) => $q->where('user_id', $userId))
        )
            + $this->countCabinets(
                OzFeedbacksClients::class,
                fn ($query) => $query
                    ->where('user_id', $userId)
                    ->where('bot_status', 1)
            );

        return [
            'cabinets_total' => $wbCabinetsCount + $ozFeedbacksCount + $ozPriceCalcCount,
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

    /**
     * @return array<int, string>
     */
    private function monthlyLimitKeys(): array
    {
        return [
            'ai_text_query',
            'ai_image_query',
            'ai_video_query',
            'feedbacks_gpt_query',
        ];
    }

}