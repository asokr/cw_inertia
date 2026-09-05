<?php

namespace App\Console\Commands;

use App\Jobs\Oz\StockHistory\ProcessOzStockHistorySnapshotJob;
use App\Models\Subscribers\Oz\StockHistory\OzStockHistorySetting;
use Illuminate\Console\Command;

/**
 * Ставит дневной снимок остатков только для кабинетов с включённым отслеживанием.
 */
class OzStockHistorySnapshotCommand extends Command
{
    protected $signature = 'subscriber:oz-stock-history-snapshot {--force : Перезаписать уже сохранённый день}';

    protected $description = 'Поставить в очередь дневной снимок истории остатков Ozon';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $cabinetIds = OzStockHistorySetting::query()
            ->where('tracking_enabled', true)
            ->orderBy('cabinet_id')
            ->pluck('cabinet_id');

        $count = 0;
        foreach ($cabinetIds as $cabinetId) {
            ProcessOzStockHistorySnapshotJob::dispatch((int) $cabinetId, null, $force, false);
            $count++;
        }

        if ($count > 0) {
            $this->info("Поставлено задач снимка остатков: {$count}.");
        }

        return self::SUCCESS;
    }
}
