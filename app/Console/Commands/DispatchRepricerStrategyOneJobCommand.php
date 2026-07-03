<?php

namespace App\Console\Commands;

use App\Jobs\ApplyRepricerStrategyOneJob;
use App\Jobs\UpdateRepricerStocksJob;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;
use App\Models\Subscribers\Wb\Repricer\RepricerStocks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class DispatchRepricerStrategyOneJobCommand extends Command
{
    protected $signature = 'subscriber:dispatch-wb-price-jobs';

    protected $description = 'Отправляет задачи изменения цены по стратегии "остатки"';

    public function handle(): int
    {
        $subscriptions = SubscribersSubscriptions::where('status', 1)->get();

        foreach ($subscriptions as $subscription) {
            $plan = SubscribersPlans::find($subscription->plan_id);

            if (! $plan || ! in_array('subscriber wb repricer', $plan->permissions, true)) {
                continue;
            }

            $user = $subscription->getUser();

            if (! $user) {
                continue;
            }

            $cabinets = RepricerCabinets::where('user_id', $user->id)
                ->where(function ($query) {
                    $query->whereNull('error_code')
                        ->orWhereNotIn('error_code', RepricerCabinets::FATAL_ERROR_CODES);
                })
                ->get();

            foreach ($cabinets as $cabinet) {
                $stocks = RepricerStocks::where('cabinet_id', $cabinet->id)
                    ->where('status', 1)
                    ->whereIn('strategy', [1, 2])
                    ->get();

                foreach ($stocks as $stock) {
                    $uniqueKey = sprintf(
                        'laravel_unique_job:%s:%s',
                        ApplyRepricerStrategyOneJob::class,
                        'repricer-price-' . $stock->id
                    );

                    $dispatchKey = 'repricer-price-dispatch-' . $stock->id;

                    if (Cache::has($uniqueKey)) {
                        $this->line("Пропускаем nmID {$stock->nmID}: задача уже в очереди");
                        continue;
                    }

                    if (! Cache::add($dispatchKey, true, now()->addMinutes(40))) {
                        $this->line("Пропускаем nmID {$stock->nmID}: задача уже запланирована");
                        continue;
                    }

                    ApplyRepricerStrategyOneJob::dispatch($stock->id);
                    $this->info("Запущена задача репрайсера по остаткам для nmID {$stock->nmID}");
                }
            }
        }

        return Command::SUCCESS;
    }
}
