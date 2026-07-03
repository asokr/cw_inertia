<?php

namespace App\Console\Commands;

use App\Models\WbApiRequestLog;
use Illuminate\Console\Command;

class CleanupWbApiRequestLogs extends Command
{
    protected $signature = 'wb:cleanup-request-logs {--days=7 : Количество дней для хранения логов}';
    protected $description = 'Удаляет старые записи логов API запросов WB';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('Количество дней должно быть больше 0');
            return self::FAILURE;
        }

        $cutoffDate = now()->subDays($days);

        $deleted = WbApiRequestLog::where('created_at', '<', $cutoffDate)->delete();

        $this->info("Удалено {$deleted} записей старше {$days} дней");

        return self::SUCCESS;
    }
}
