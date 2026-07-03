<?php

namespace App\Services\Subscriber;

use App\Models\User;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class SubscriberContextService
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
    ) {
    }

    /**
     * @return array{
     *     balance: float|int,
     *     has_seen_tour: bool,
     *     notify: ?array{title: string, text: string, type: string},
     *     subscription: ?array{id: int, status: int, plan_id: int, end_date: ?string}
     * }
     */
    public function forUser(User $user): array
    {
        $subscription = $user->getSubscriptions();
        $notify = null;

        if ($subscription && $subscription->status == 1) {
            $this->subscriptionService->setSubscription($subscription);
            $this->subscriptionService->checkAndManageSubscription();
            $subscription->refresh();
        } else {
            $notify = [
                'text' => '<a href="/panel/user/profile">Пополните баланс и продлите подписку</a>',
                'type' => 'info',
                'title' => 'Действие вашего тарифа окончено',
            ];
        }

        if ($subscription && $subscription->plan_id == 2) {
            if ($subscription->status) {
                $options = [
                    'join' => ' ',
                    'parts' => 2,
                    'syntax' => CarbonInterface::DIFF_ABSOLUTE,
                ];
                $endDate = Carbon::parse($subscription->end_date);
                $notify = [
                    'text' => 'Спасибо за регистрацию. Вам предоставлен пробный период. Осталось: '.$endDate->diffForHumans(Carbon::now(), $options),
                    'type' => 'info',
                    'title' => 'У Вас пробный период',
                ];
            } else {
                $notify = [
                    'text' => 'Для дальнейшей работы <a href="/panel/user/profile">выберите тариф</a>',
                    'type' => 'info',
                    'title' => 'Тестовый период завершен',
                ];
            }
        }

        return [
            'balance' => $user->balance()->value->get(),
            'has_seen_tour' => (bool) $user->has_seen_tour,
            'notify' => $notify,
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'plan_id' => $subscription->plan_id,
                'end_date' => $subscription->getRawOriginal('end_date'),
            ] : null,
        ];
    }
}