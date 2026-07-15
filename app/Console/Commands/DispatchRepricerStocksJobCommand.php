<?php

namespace App\Console\Commands;

use App\Jobs\UpdateRepricerStocksJob;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;
use App\Models\Subscribers\Wb\Repricer\RepricerStocks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class DispatchRepricerStocksJobCommand extends Command
{
    protected $signature = 'subscriber:dispatch-wb-stocks-jobs';

    protected $description = 'Отправляет задачи обновления остатков для кабинетов Wildberries';

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

            static $jobIndex = 0;

            foreach ($cabinets as $cabinet) {
                $hasActiveStocks = RepricerStocks::where('cabinet_id', $cabinet->id)
                    ->where('status', 1)
                    ->exists();

                if (! $hasActiveStocks) {
                    $this->line("Пропускаем кабинет {$cabinet->id}: нет активных номенклатур");
                    continue;
                }

                $uniqueKey = sprintf(
                    'laravel_unique_job:%s:%s',
                    UpdateRepricerStocksJob::class,
                    'repricer-stocks-' . $cabinet->id
                );
                $scheduleKey = UpdateRepricerStocksJob::scheduleCacheKeyFor($cabinet->id);

                if (Cache::has($uniqueKey) || Cache::has($scheduleKey)) {
                    $this->line("Пропускаем кабинет {$cabinet->id}: задача уже в очереди");
                    continue;
                }

                $delaySeconds = $jobIndex * 2; // 2s interval between jobs
                $jobIndex++;

                UpdateRepricerStocksJob::dispatch($cabinet->id, $subscription->id)->delay(now()->addSeconds($delaySeconds));
                Cache::put($scheduleKey, true, now()->addMinutes(40));
                $this->info("Запущена задача обновления остатков для кабинета {$cabinet->id} (запуск через {$delaySeconds} секунд)");
            }
        }

        return Command::SUCCESS;
    }
}
