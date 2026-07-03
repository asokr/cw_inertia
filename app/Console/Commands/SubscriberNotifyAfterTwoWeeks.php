<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use App\Models\Subscribers\Subscribers;
use App\Notifications\AfterTwoWeeksWhenPeriodEnds;
use App\Models\Subscribers\SubscribersSubscriptions;

class SubscriberNotifyAfterTwoWeeks extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriber:notify-after-two-weeks';

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
        $subscriptions = SubscribersSubscriptions::where('status', 0)
            ->get();

        if (!$subscriptions)
            return false;

        foreach ($subscriptions as $subscription) {
            $subscriber = Subscribers::find($subscription->subscribers_id);

            // Вышлем уведомление после двух недель окончания тарифа
            $two_weeks_after = Carbon::parse($subscription->end_date)->addWeeks(2);

            if (Carbon::now()->isSameDay($two_weeks_after)) {
                $subscriber->user->notify(new AfterTwoWeeksWhenPeriodEnds());
            }
        }
    }
}
