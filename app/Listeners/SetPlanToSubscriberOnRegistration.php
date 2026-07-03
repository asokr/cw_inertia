<?php

namespace App\Listeners;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Coupon;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;

class SetPlanToSubscriberOnRegistration
{
    /**
     * Create the event listener.
     */
    public function __construct(User $user)
    {
        $this->user = $user;
    }

    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        $user = $event->user;
        $plan_id = 2; // ID Тестового плана

        if ($user->plan_id) {
            $plan_id = $user->plan_id;
        }

        $model = SubscribersPlans::find($plan_id);
        $end_date = Carbon::now()->addDays($model->duration);

        $user->givePermissionTo($model->permissions);
        SubscribersSubscriptions::create([
            'subscribers_id' => $user->subscriber->id,
            'plan_id' => $plan_id,
            'limits_month' => $model->limits_month,
            'limits_plan' => $model->limits_plan,
            'end_date' => $end_date,
            'status' => 1
        ]);
    }
}
