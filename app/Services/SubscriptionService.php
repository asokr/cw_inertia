<?php

namespace App\Services;


use Carbon\Carbon;
use App\Http\Traits\SubscriptionsTrait;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersPlans;
use App\Enums\SubscriptionsControlActionEnum;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\SubscribersSubscriptionsControl;

class SubscriptionService
{

    use SubscriptionsTrait;
    protected $test_plan_id = 2;
    protected $free_perrmissions = ['subscriber', 'subscriber wb price calculator', 'subscriber wb promo calculator'];
    protected $subscriber;
    protected $subscription;
    protected $plan;

    protected $command;


    public function setSubscription(SubscribersSubscriptions $subscription)
    {
        $this->subscription = $subscription;
        $this->subscriber = Subscribers::find($subscription->subscribers_id);
        $this->plan = SubscribersPlans::find($subscription->plan_id);
    }

    public function checkAndManageSubscription()
    {

        // Проверка подписки
        if (Carbon::now()->lt($this->subscription->end_date)) {


            // Подписка активна
            return true;
        }

        // Проверка тестовой подписки
        if ($this->deactivateTestSubscription()) {
            return true;
        }

        // Отключение или перевод на более низкий тариф
        if ($this->controlSubscription()) {
            return true;
        }

        // Попытка продлить подписку
        if ($this->attemptToRenew()) {
            return true;
        }

        // Деактивация подписки при недостатке средств
        $this->deactivateSubscription();

        return false;
    }

    protected function attemptToRenew()
    {
        try {
            charge($this->plan->price, 'RUB')->from($this->subscriber->user)->meta([
                'description' => "Продление подписки",
            ])->commit();
        } catch (\Throwable $th) {
            return false;
        }
        $this->subscription->start_date = Carbon::now();
        $this->subscription->end_date = Carbon::now()->addDays($this->plan->duration);
        // Добавим лимиты по месячному тарифу
        $limits = $this->subscription->limits_month;
        foreach ($this->plan->limits_month as $limit => $value) {
            $limits[$limit] = $value;
        }
        $this->subscription->limits_month = $limits;
        $this->subscription->save();

        return true;
    }

    protected function deactivateTestSubscription()
    {
        if ($this->subscription->status == 1 && $this->subscription->plan_id == $this->test_plan_id) {

            $this->subscription->status = 0;
            $this->subscription->save();
            $this->subscriber->user->syncPermissions($this->free_perrmissions);
            return true;
        }

        return false;
    }

    protected function deactivateSubscription()
    {
        // Отключим подписку
        $this->subscription->status = 0;
        $this->subscription->save();
        // Уберём платные разрешения у юзера
        $this->subscriber->user->syncPermissions($this->free_perrmissions);
        return true;
    }

    protected function controlSubscription()
    {
        $control = SubscribersSubscriptionsControl::where('subscription_id', $this->subscription->id)->first();

        if (!$control) {
            return false;
        }

        switch ($control->action) {
            case SubscriptionsControlActionEnum::STOP:
                $this->subscription->status = 0;
                $this->subscription->save();
                break;
            case SubscriptionsControlActionEnum::LOWER:
                $this->lowerPlan($control->config);
                break;

            default:
                # code...
                break;
        }

        $control->delete();

        return true;
    }

    private function lowerPlan($config)
    {
        $plan = SubscribersPlans::find($config['plan_id']);

        $remainingPlanLimits = [];
        // Пересчитаем лимиты по тарифу
        foreach ($plan->limits_plan as $key => $value) {
            $planCount = $this->getUsedLimits($this->subscription->subscribers_id, $key);
            if ($planCount) {
                $remainingPlanLimits[$key] = (int) $value - (int) $planCount;
                // Если превысили лимит при понижении тарифа
                // удалим лишнее.
                // Поставим остаток ноль в лимит
                if ($remainingPlanLimits[$key] < 0) {
                    $toDelete = $remainingPlanLimits[$key] * -1;
                    $this->deleteOverLimits($this->subscription->subscribers_id, $key, $toDelete);
                    $remainingPlanLimits[$key] = 0;
                }
            } else {
                $remainingPlanLimits[$key] = (int) $value;
            }
        }

        $this->subscription->plan_id = $plan->id;
        $this->subscription->limits_plan = $remainingPlanLimits;
        $this->subscription->limits_month = $plan->limits_month;

        // Если есть баланс
        if ($this->subscriber->user->isEnoughFunds($plan->price, 'RUB')) {
            $this->subscription->start_date = Carbon::now();
            $this->subscription->end_date = Carbon::now()->addDays($plan->duration);
            $this->subscription->status = 1;
            // Снимим средства с баланса
            charge($plan->price, 'RUB')->from($this->subscriber->user)->commit();
            $this->subscriber->user->syncPermissions($plan->permissions);
        } else {
            $this->subscription->status = 0;
            $this->subscriber->user->syncPermissions($this->free_perrmissions);
        }

        $this->subscription->save();
    }
}
