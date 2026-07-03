<?php

namespace App\Console\Commands;

use App\Jobs\ProcessProfitabilityReport;
use App\Models\JobStatus;
use Illuminate\Console\Command;

class ResetStuckProfitabilityReportsCommand extends Command
{
    protected $signature = 'subscriber:fail-stuck-profitability-reports {--minutes=7 : Количество минут простоя перед переводом в failed}';

    protected $description = 'Переводит зависшие profitability-отчёты в статус failed.';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        if ($minutes < 1) {
            $minutes = 1;
        }

        $threshold = now()->subMinutes($minutes);

        $updated = JobStatus::where('job_name', ProcessProfitabilityReport::class)
            ->where('status', 'processing')
            ->where('updated_at', '<', $threshold)
            ->update([
                'status' => 'failed',
                'error' => 'Выполнение отчёта превысило лимит в ' . $minutes . ' минут(ы)',
                'updated_at' => now(),
            ]);

        $this->info('Обновлено записей: ' . $updated);

        return Command::SUCCESS;
    }
}
