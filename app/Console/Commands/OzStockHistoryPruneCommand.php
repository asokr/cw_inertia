<?php

namespace App\Console\Commands;

use App\Models\Subscribers\Oz\StockHistory\OzStockHistorySetting;
use App\Services\Oz\StockHistory\OzStockHistorySyncService;
use Illuminate\Console\Command;

/**
 * Удаляет историю остатков старше срока хранения кабинета.
 */
class OzStockHistoryPruneCommand extends Command
{
    protected $signature = 'subscriber:oz-stock-history-prune';

    protected $description = 'Удалить историю остатков Ozon старше выбранного срока хранения';

    public function handle(OzStockHistorySyncService $syncService): int
    {
        $settings = OzStockHistorySetting::query()->orderBy('cabinet_id')->get();
        $deleted = 0;

        foreach ($settings as $setting) {
            $deleted += $syncService->pruneCabinet(
                (int) $setting->cabinet_id,
                (int) $setting->retention_days,
            );
        }

        if ($deleted > 0) {
            $this->info("Удалено записей истории: {$deleted}.");
        }

        return self::SUCCESS;
    }
}
