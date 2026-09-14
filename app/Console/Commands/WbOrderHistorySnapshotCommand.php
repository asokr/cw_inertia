<?php

namespace App\Console\Commands;

use App\Jobs\Wb\StockHistory\ProcessWbOrderHistorySnapshotJob;
use App\Models\Subscribers\Wb\StockHistory\WbStockHistorySetting;
use Illuminate\Console\Command;

/**
 * Ставит дневной снимок заказов WB только для кабинетов с включённым сбором.
 */
class WbOrderHistorySnapshotCommand extends Command
{
    protected $signature = 'subscriber:wb-order-history-snapshot';

    protected $description = 'Поставить в очередь дневной снимок истории заказов WB';

    public function handle(): int
    {
        $cabinetIds = WbStockHistorySetting::query()
            ->where('orders_tracking_enabled', true)
            ->orderBy('cabinet_id')
            ->pluck('cabinet_id');

        $count = 0;
        foreach ($cabinetIds as $cabinetId) {
            ProcessWbOrderHistorySnapshotJob::dispatch((int) $cabinetId);
            $count++;
        }

        if ($count > 0) {
            $this->info("Поставлено задач снимка заказов: {$count}.");
        }

        return self::SUCCESS;
    }
}
