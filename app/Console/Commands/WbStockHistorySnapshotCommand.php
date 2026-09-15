<?php

namespace App\Console\Commands;

use App\Jobs\Wb\StockHistory\ProcessWbStockHistorySnapshotJob;
use App\Models\Subscribers\Wb\StockHistory\WbStockHistorySetting;
use Illuminate\Console\Command;

/**
 * Ставит снимок остатков WB только для кабинетов с включённым сбором.
 */
class WbStockHistorySnapshotCommand extends Command
{
    protected $signature = 'subscriber:wb-stock-history-snapshot';

    protected $description = 'Поставить в очередь снимок истории остатков WB (вчера и сегодня)';

    public function handle(): int
    {
        $cabinetIds = WbStockHistorySetting::query()
            ->where('stocks_tracking_enabled', true)
            ->orderBy('cabinet_id')
            ->pluck('cabinet_id');

        $count = 0;
        foreach ($cabinetIds as $cabinetId) {
            ProcessWbStockHistorySnapshotJob::dispatch((int) $cabinetId);
            $count++;
        }

        if ($count > 0) {
            $this->info("Поставлено задач снимка остатков: {$count}.");
        }

        return self::SUCCESS;
    }
}
