<?php

namespace App\Services\Subscriber;

use App\Models\PaymentsTransaction;
use App\Models\Subscribers\Oz\Feedbacks\FeedbacksClients as OzFeedbacksClients;
use App\Models\Subscribers\Oz\PriceCalc\OzPriceCalcCabinet;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerCabinet;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients as WbFeedbacksClients;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationCabinets;
use App\Models\Subscribers\Wb\Profitability\ProfitabilityCabinet;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;
use App\Models\User;
use App\Services\SubscriptionService;
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

        return [
            'plan_name' => $plan?->name,
            'end_date' => $subscription->end_date,
            'status' => (int) $subscription->status,
            'remaining_limits' => $this->buildRemainingLimits($limitsSubscription),
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
        foreach ($this->planLimitKeys() as $key) {
            if (array_key_exists($key, $planLimits)) {
                $limits[$key] = (int) $planLimits[$key];
            }
        }

        return $limits;
    }

    /**
     * @return array<string, int>
     */
    private function buildStats(User $user, ?int $subscriberId): array
    {
        $userId = $user->id;

        $cabinetsByTool = [
            'wb_feedbacks' => $subscriberId
                ? $this->countCabinets(
                    WbFeedbacksClients::class,
                    fn ($query) => $query->where('subscriber_id', $subscriberId)
                )
                : 0,
            'wb_profitability' => $this->countCabinets(
                ProfitabilityCabinet::class,
                fn ($query) => $query->where('user_id', $userId)
            ),
            'wb_price_calc' => $this->countCabinets(
                PriceCalculationCabinets::class,
                fn ($query) => $query->where('user_id', $userId)
            ),
            'wb_repricer' => $this->countCabinets(
                RepricerCabinets::class,
                fn ($query) => $query->where('user_id', $userId)
            ),
            'wb_ai_cabinet_analyzer' => $this->countCabinets(
                AiCabinetAnalyzerCabinet::class,
                fn ($query) => $query->where('user_id', $userId)
            ),
            'oz_feedbacks' => $this->countCabinets(
                OzFeedbacksClients::class,
                fn ($query) => $query->where('user_id', $userId)
            ),
            'oz_price_calc' => $this->countCabinets(
                OzPriceCalcCabinet::class,
                fn ($query) => $query->where('user_id', $userId)
            ),
        ];

        $activeBots = ($subscriberId
            ? $this->countCabinets(
                WbFeedbacksClients::class,
                fn ($query) => $query
                    ->where('subscriber_id', $subscriberId)
                    ->where('bot_status', 1)
            )
            : 0)
            + $this->countCabinets(
                OzFeedbacksClients::class,
                fn ($query) => $query
                    ->where('user_id', $userId)
                    ->where('bot_status', 1)
            );

        return [
            'cabinets_total' => array_sum($cabinetsByTool),
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

    /**
     * @return array<int, string>
     */
    private function planLimitKeys(): array
    {
        return [
            'feedbacks_clients',
            'oz_feedbacks_clients',
            'price_calc_clients',
            'oz_price_calc_clients',
            'repricer_nmid',
            'adverts_clients',
        ];
    }
}