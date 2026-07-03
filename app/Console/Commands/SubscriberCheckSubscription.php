<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SubscriptionService;
use App\Models\Subscribers\SubscribersSubscriptions;

class SubscriberCheckSubscription extends Command
{

    protected $subscriptionService;


    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriber:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Проверка подписок юзера';


    public function __construct(SubscriptionService $subscriptionService)
    {
        parent::__construct();
        $this->subscriptionService = $subscriptionService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $subscriptions = SubscribersSubscriptions::where('status', 1)->get();

        if (!$subscriptions)
            return false;

        foreach ($subscriptions as $subscription) {
            $this->subscriptionService->setSubscription($subscription);
            $this->subscriptionService->checkAndManageSubscription();
        }
    }
}
