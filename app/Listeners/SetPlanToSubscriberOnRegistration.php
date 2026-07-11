<?php

namespace App\Listeners;

use Carbon\Carbon;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Services\Subscriber\RegistrationPlanService;

class SetPlanToSubscriberOnRegistration
{
    public function __construct(
        private readonly RegistrationPlanService $registrationPlanService,
    ) {
    }

    public function handle(object $event): void
    {
        $user = $event->user;
        $user->loadMissing('subscriber');
        $planId = $this->registrationPlanService->resolveForUser($user);

        $model = SubscribersPlans::find($planId);

        if (! $model || ! $user->subscriber) {
            return;
        }

        $endDate = Carbon::now()->addDays($model->duration);

        $user->givePermissionTo($model->permissions);

        SubscribersSubscriptions::create([
            'subscribers_id' => $user->subscriber->id,
            'plan_id' => $planId,
            'limits_month' => $model->limits_month,
            'limits_plan' => $model->limits_plan,
            'end_date' => $endDate,
            'status' => 1,
        ]);
    }
}