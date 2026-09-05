<?php

namespace App\Jobs\Oz\StockHistory;

use App\Enums\OzStockHistoryTrackingStatus;
use App\Models\Subscribers\Oz\OzCabinet;
use App\Models\Subscribers\Oz\StockHistory\OzStockHistorySetting;
use App\Services\Oz\StockHistory\OzStockHistorySyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Старт отслеживания: сначала каталог товаров, затем снимок вчерашних остатков.
 */
class ProcessOzStockHistoryStartJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 3600;

    public int $tries = 2;

    public int $timeout = 1800;

    public function __construct(
        public readonly int $cabinetId,
    ) {
        $this->onQueue('oz_stock_history');
    }

    public function uniqueId(): string
    {
        return 'oz-stock-history-start-'.$this->cabinetId;
    }

    public function handle(OzStockHistorySyncService $syncService): void
    {
        $cabinet = OzCabinet::query()->find($this->cabinetId);
        if (! $cabinet) {
            return;
        }

        $settings = OzStockHistorySetting::query()->firstOrCreate(
            ['cabinet_id' => $this->cabinetId],
            ['retention_days' => OzStockHistorySetting::DEFAULT_RETENTION_DAYS],
        );

        $settings->tracking_status = OzStockHistoryTrackingStatus::LoadingProducts;
        $settings->tracking_enabled = false;
        $settings->last_error = null;
        $settings->save();

        $catalog = $syncService->syncProducts($cabinet);
        if (! ($catalog['success'] ?? false)) {
            $settings->tracking_enabled = false;
            $settings->tracking_status = OzStockHistoryTrackingStatus::Error;
            $settings->last_error = $catalog['messages'][0] ?? 'Не удалось загрузить товары кабинета.';
            $settings->save();

            return;
        }

        $settings->tracking_enabled = true;
        $settings->tracking_status = OzStockHistoryTrackingStatus::LoadingStocks;
        $settings->last_error = null;
        $settings->save();

        try {
            ProcessOzStockHistorySnapshotJob::dispatch(
                $this->cabinetId,
                $syncService->yesterdayDate(),
                false,
                true,
            );
        } catch (Throwable $e) {
            Log::error('[ProcessOzStockHistoryStartJob] snapshot dispatch failed', [
                'cabinet_id' => $this->cabinetId,
                'message' => $e->getMessage(),
            ]);
            $settings->tracking_status = OzStockHistoryTrackingStatus::Active;
            $settings->last_error = 'Товары загружены, остатки за вчера не сохранились. Повторим вечером.';
            $settings->save();
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('[ProcessOzStockHistoryStartJob] failed', [
            'cabinet_id' => $this->cabinetId,
            'message' => $exception->getMessage(),
        ]);

        $settings = OzStockHistorySetting::query()->where('cabinet_id', $this->cabinetId)->first();
        if (! $settings) {
            return;
        }

        if ($settings->products_synced_at) {
            $settings->tracking_enabled = true;
            $settings->tracking_status = OzStockHistoryTrackingStatus::Active;
            $settings->last_error = 'Товары загружены, остатки за вчера не сохранились. Повторим вечером.';
        } else {
            $settings->tracking_enabled = false;
            $settings->tracking_status = OzStockHistoryTrackingStatus::Error;
            $settings->last_error = 'Не удалось загрузить товары кабинета.';
        }
        $settings->save();
    }
}
