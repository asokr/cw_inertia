<?php

namespace App\Console\Commands;

use App\Models\Subscribers\Wb\StockHistory\WbStockHistorySetting;
use App\Services\Wb\StockHistory\WbStockHistorySyncService;
use Illuminate\Console\Command;

/**
 * Удаляет историю остатков и заказов WB старше срока хранения кабинета.
 */
class WbStockHistoryPruneCommand extends Command
{
    protected $signature = 'subscriber:wb-stock-history-prune';

    protected $description = 'Удалить историю остатков и заказов WB старше выбранного срока хранения';

    public function handle(WbStockHistorySyncService $syncService): int
    {
        $settings = WbStockHistorySetting::query()->orderBy('cabinet_id')->get();
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
