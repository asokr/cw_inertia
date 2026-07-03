<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersPlans;
use App\Notifications\NotEnoughFundsNotification;
use App\Notifications\TestPeriodEndsNotification;
use App\Models\Subscribers\SubscribersSubscriptions;

class SubscriberNotifyNotEnoughFunds extends Command
{

    protected $test_plan_id = 2;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriber:notify';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $subscriptions = SubscribersSubscriptions::where('status', 1)->get();

        if (!$subscriptions)
            return false;

        foreach ($subscriptions as $subscription) {
            $subscriber = Subscribers::find($subscription->subscribers_id);
            $plan = SubscribersPlans::find($subscription->plan_id);

            // Вышлем уведомление за 3 дня до окончания тарифа
            $three_days_left = Carbon::parse($subscription->end_date)->subDays(3);
            if (Carbon::now()->isSameDay($three_days_left)) {
                // Если тестовый период, предложим выбрать тариф
                if ($subscription->plan_id != $this->test_plan_id && !$subscriber->user->isEnoughFunds($plan->price, 'RUB')) {
                    $not_enough = $plan->price - $subscriber->user->balance()->value->get();
                    Log::info('Выслали уведомление - ' . $subscriber->user->name . ', о том, что у него на счету не хватает ' . $not_enough . ' рублей');
                    $subscriber->user->notify(new NotEnoughFundsNotification($not_enough));
                }
            }
            // Вышлем уведомление за 2 дня до окончания тарифа
            $two_days_left = Carbon::parse($subscription->end_date)->subDays(2);
            if (Carbon::now()->isSameDay($two_days_left)) {

                // Если тестовый период, предложим выбрать тариф
                if ($subscription->plan_id == $this->test_plan_id) {
                    $subscriber->user->notify(new TestPeriodEndsNotification());
                    Log::info('Выслали уведомление - ' . $subscriber->user->name . ', о том, что у него заканчивается тестовый период');
                } else if (!$subscriber->user->isEnoughFunds($plan->price, 'RUB')) {
                    $not_enough = $plan->price - $subscriber->user->balance()->value->get();
                    Log::info('Выслали уведомление - ' . $subscriber->user->name . ', о том, что у него на счету не хватает ' . $not_enough . ' рублей');
                    $subscriber->user->notify(new NotEnoughFundsNotification($not_enough));
                }
            }
        }
    }
}
