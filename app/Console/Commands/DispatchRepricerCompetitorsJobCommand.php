<?php

namespace App\Console\Commands;

use App\Jobs\ProcessRepricerCompetitorJob;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\Repricer\RepricerCompetitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchRepricerCompetitorsJobCommand extends Command
{
    protected $signature = 'subscriber:dispatch-wb-competitor-jobs';

    protected $description = 'Отправляет задачи конкурентного репрайсера Wildberries';

    public function handle(): int
    {
        $subscriptions = SubscribersSubscriptions::where('status', 1)->get();

        $dispatched = 0;

        // Получаем ID всех конкурентов, которые уже лежат в очереди (защита от очистки кеша)
        $queuedCompetitorIds = $this->getQueuedCompetitorIds();

        foreach ($subscriptions as $subscription) {
            $plan = SubscribersPlans::find($subscription->plan_id);

            if (! $plan || ! in_array('subscriber wb repricer', $plan->permissions, true)) {
                continue;
            }

            $user = $subscription->getUser();

            if (! $user) {
                continue;
            }

            RepricerCompetitor::query()
                ->where('status', true)
                ->whereHas('cabinet', function ($query) use ($user) {
                    $query->where('user_id', $user->id)
                        ->where(function ($subQuery) {
                            $subQuery->whereNull('error_code')
                                ->orWhereNotIn('error_code', RepricerCabinets::FATAL_ERROR_CODES);
                        });
                })
                ->orderBy('id')
                ->chunkById(100, function ($competitors) use (&$dispatched, &$queuedCompetitorIds) {
                    foreach ($competitors as $competitor) {
                        if (in_array($competitor->id, $queuedCompetitorIds, true)) {
                            $this->line("Пропускаем nmID {$competitor->nm_id}: задача уже в очереди (DB Check)");
                            continue;
                        }

                        // Добавляем в локальный список, чтобы не дублировать, если команда работает долго
                        $queuedCompetitorIds[] = $competitor->id;

                        ProcessRepricerCompetitorJob::dispatch($competitor->id);
                        $this->info("Поставлена задача конкурентного репрайсера для nmID {$competitor->nm_id}");
                        $dispatched++;
                    }
                });
        }

        if ($dispatched === 0) {
            $this->line('Новых задач конкурентного репрайсера не отправлено.');
        }

        return Command::SUCCESS;
    }

    /**
     * Получает список ID конкурентов, задачи по которым уже находятся в очереди.
     * Использует прямой доступ к БД, игнорируя Cache.
     */
    private function getQueuedCompetitorIds(): array
    {
        try {
            return DB::table('jobs')
                ->where('queue', 'repricer_competitors')
                ->get()
                ->map(function ($job) {
                    try {
                        $payload = json_decode($job->payload, true);
                        if (isset($payload['data']['command'])) {
                            $command = unserialize($payload['data']['command']);
                            if ($command instanceof ProcessRepricerCompetitorJob) {
                                return $command->recordId;
                            }
                        }
                    } catch (\Throwable $e) {
                        // ignore broken payload
                    }
                    return null;
                })
                ->filter()
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            return []; // Если таблицы jobs нет или другая ошибка, возвращаем пустой массив
        }
    }
}
