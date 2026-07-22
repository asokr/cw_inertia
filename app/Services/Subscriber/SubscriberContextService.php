<?php

namespace App\Services\Subscriber;

use App\Enums\SubscriptionsControlActionEnum;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\SubscribersSubscriptionsControl;
use App\Models\User;
use App\Services\SubscriptionService;
use Carbon\Carbon;

class SubscriberContextService
{
    private const TEST_PLAN_ID = 2;

    private const SHOW_DAYS_THRESHOLD = 15;

    private const URGENT_DAYS_THRESHOLD = 5;

    public function __construct(
        private readonly SubscriptionService $subscriptionService,
    ) {
    }

    /**
     * @return array{
     *     balance: float|int,
     *     has_seen_tour: bool,
     *     promo_banner: ?array{
     *         variant: string,
     *         days_left: ?int,
     *         plan_name: ?string,
     *         message: string,
     *         cta_label: string,
     *         cta_href: string
     *     },
     *     subscription: ?array{id: int, status: int, plan_id: int, end_date: ?string},
     *     days_indicator: ?array{
     *         days_left: int,
     *         visible: bool,
     *         urgent: bool,
     *         shortfall: float|null
     *     }
     * }
     */
    public function forUser(User $user): array
    {
        $subscription = $user->getSubscriptions();

        if ($subscription && $subscription->status == 1) {
            $this->subscriptionService->setSubscription($subscription);
            $this->subscriptionService->checkAndManageSubscription();
            $subscription->refresh();
        }

        return [
            'balance' => $user->balance()->value->get(),
            'has_seen_tour' => (bool) $user->has_seen_tour,
            'promo_banner' => $this->buildPromoBanner($subscription),
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'plan_id' => $subscription->plan_id,
                'end_date' => $subscription->getRawOriginal('end_date'),
            ] : null,
            'days_indicator' => $this->buildDaysIndicator($user, $subscription),
        ];
    }

    /**
     * @return ?array{
     *     variant: string,
     *     days_left: ?int,
     *     plan_name: ?string,
     *     message: string,
     *     cta_label: string,
     *     cta_href: string
     * }
     */
    private function buildPromoBanner($subscription): ?array
    {
        $ctaHref = '/panel/plans';

        if (! $subscription) {
            return [
                'variant' => 'no_subscription',
                'days_left' => null,
                'plan_name' => null,
                'message' => 'Подключите тариф, чтобы начать работу с инструментами',
                'cta_label' => 'Выбрать тариф',
                'cta_href' => $ctaHref,
            ];
        }

        if ((int) $subscription->plan_id === self::TEST_PLAN_ID) {
            if ($subscription->status) {
                $daysLeft = $this->calculateDaysLeft($subscription);

                return [
                    'variant' => 'trial_active',
                    'days_left' => $daysLeft,
                    'plan_name' => null,
                    'message' => "Осталось {$daysLeft} дн. пробного периода — подключите тариф и снимите ограничения",
                    'cta_label' => 'Выбрать тариф',
                    'cta_href' => $ctaHref,
                ];
            }

            return [
                'variant' => 'trial_expired',
                'days_left' => null,
                'plan_name' => null,
                'message' => 'Пробный период завершён — выберите тариф, чтобы продолжить работу',
                'cta_label' => 'Выбрать тариф',
                'cta_href' => $ctaHref,
            ];
        }

        if (! $subscription->status) {
            $plan = SubscribersPlans::query()
                ->select(['name'])
                ->find($subscription->plan_id);

            $planName = $plan?->name ?? 'тариф';

            return [
                'variant' => 'subscription_expired',
                'days_left' => null,
                'plan_name' => $planName,
                'message' => "Ваш тариф «{$planName}» закончился — продлите подписку и верните доступ к инструментам",
                'cta_label' => 'Продлить тариф',
                'cta_href' => $ctaHref,
            ];
        }

        return null;
    }

    /**
     * @return ?array{
     *     days_left: int,
     *     visible: bool,
     *     urgent: bool,
     *     shortfall: float|null
     * }
     */
    private function buildDaysIndicator(User $user, $subscription): ?array
    {
        if (! $subscription || ! (int) $subscription->status) {
            return null;
        }

        if ((int) $subscription->plan_id === self::TEST_PLAN_ID) {
            return null;
        }

        $rawEndDate = $subscription->getRawOriginal('end_date');
        if (! $rawEndDate) {
            return null;
        }

        $daysLeft = $this->calculateDaysLeft($subscription);
        $visible = $daysLeft < self::SHOW_DAYS_THRESHOLD;

        $plan = SubscribersPlans::query()
            ->select(['id', 'price'])
            ->find($subscription->plan_id);

        $renewalPrice = (float) ($plan?->price ?? 0);
        $enoughForRenewal = $renewalPrice <= 0
            || $user->isEnoughFunds((string) $renewalPrice, 'RUB');

        $balance = (float) $user->balance()->value->get();
        $shortfall = $enoughForRenewal
            ? null
            : round(max(0, $renewalPrice - $balance), 2);

        $hasStop = false;
        if ($visible && ! $enoughForRenewal) {
            $hasStop = SubscribersSubscriptionsControl::query()
                ->where('subscription_id', $subscription->id)
                ->where('action', SubscriptionsControlActionEnum::STOP)
                ->exists();
        }

        $urgent = $visible
            && $daysLeft < self::URGENT_DAYS_THRESHOLD
            && ! $enoughForRenewal
            && ! $hasStop;

        return [
            'days_left' => $daysLeft,
            'visible' => $visible,
            'urgent' => $urgent,
            // shortfall всегда, когда на балансе не хватает на автопродление (для префилла пополнения)
            'shortfall' => $shortfall,
        ];
    }

    private function calculateDaysLeft(SubscribersSubscriptions $subscription): int
    {
        $rawEndDate = $subscription->getRawOriginal('end_date') ?? $subscription->end_date;
        $endDate = Carbon::parse($rawEndDate)->startOfDay();

        return max(0, (int) Carbon::now()->startOfDay()->diffInDays($endDate, false));
    }
}
