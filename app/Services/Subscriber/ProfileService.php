<?php

namespace App\Services\Subscriber;

use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\SubscribersSubscriptionsControl;
use App\Models\User;

class ProfileService
{
    /**
     * @return array{success: bool, messages: array<int, string>}
     */
    public function updateName(User $user, string $name): array
    {
        $user->name = $name;
        $user->save();

        return [
            'success' => true,
            'messages' => ['Данные профиля обновлены'],
        ];
    }

    /**
     * @return array{plans: array<int, array<string, mixed>>, next: mixed}
     */
    public function getAvailablePlans(User $user): array
    {
        $plans = SubscribersPlans::select([
            'id',
            'description',
            'duration',
            'name',
            'price',
            'limits_plan',
            'limits_month',
        ])->where(['status' => 1, 'hidden' => 0])->get()->toArray();

        $subscriberId = $user->subscriberId();

        $subscription = $subscriberId
            ? SubscribersSubscriptions::where([
                'subscribers_id' => $subscriberId,
                'status' => 1,
            ])->first()
            : null;

        $next = [];

        if ($subscription) {
            foreach ($plans as $key => $plan) {
                $plans[$key]['lower'] = $plan['price'] < $subscription->plan->price;

                if ($plan['id'] === $subscription->plan_id) {
                    unset($plans[$key]);
                }
            }

            $next = SubscribersSubscriptionsControl::select(['action'])
                ->where(['subscription_id' => $subscription->id])
                ->get()
                ->toArray();
        }

        return [
            'plans' => array_values($plans),
            'next' => $next,
        ];
    }

    public function markTourSeen(User $user): void
    {
        $user->has_seen_tour = true;
        $user->save();
    }
}