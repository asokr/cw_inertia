<?php

namespace App\Jobs\Wb\StockHistory;

use App\Enums\WbHistoryLoadStatus;
use App\Models\Subscribers\Wb\StockHistory\WbStockHistorySetting;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Services\Wb\StockHistory\WbStockHistorySyncService;
use App\Support\Wb\WbStockHistoryCalendar;
use App\Support\Wb\WbStockHistoryJobStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Загрузка истории остатков WB за выбранный период.
 */
class ProcessWbStockHistoryBackfillJob implements ShouldBeUnique, ShouldQueue
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
        public readonly string $from,
        public readonly string $to,
        public readonly bool $force = false,
        public readonly bool $enableTracking = true,
    ) {
        $this->onQueue('wb_stock_history');
    }

    public function uniqueId(): string
    {
        return 'wb-stock-history-backfill-'.$this->cabinetId;
    }

    public function handle(WbStockHistorySyncService $syncService): void
    {
        $cabinet = WbCabinet::query()->find($this->cabinetId);
        if (! $cabinet) {
            return;
        }

        $settings = WbStockHistorySetting::query()->firstOrCreate(
            ['cabinet_id' => $this->cabinetId],
            ['retention_days' => WbStockHistorySetting::DEFAULT_RETENTION_DAYS],
        );
        $settings->stocks_status = WbHistoryLoadStatus::Loading;
        $settings->stocks_last_error = null;
        $settings->save();

        try {
            WbStockHistoryJobStatus::stage(
                $this->cabinetId,
                'stocks',
                WbStockHistoryJobStatus::STAGE_PRODUCTS,
                'Собираем товары',
                20,
            );
            $syncService->syncProducts($cabinet);

            WbStockHistoryJobStatus::stage(
                $this->cabinetId,
                'stocks',
                WbStockHistoryJobStatus::STAGE_HISTORY,
                'Собираем историю остатков',
                45,
            );
            $result = $syncService->importStockHistory($cabinet, $this->from, $this->to, $this->force);
            if (! ($result['success'] ?? false)) {
                $message = $result['messages'][0] ?? 'Не удалось загрузить историю остатков.';
                $settings->stocks_status = WbHistoryLoadStatus::Error;
                $settings->stocks_last_error = $message;
                $settings->save();
                WbStockHistoryJobStatus::failed($this->cabinetId, 'stocks', $message);

                return;
            }

            WbStockHistoryJobStatus::stage(
                $this->cabinetId,
                'stocks',
                WbStockHistoryJobStatus::STAGE_WAREHOUSES,
                'Уточняем текущие остатки',
                75,
            );
            $warehouses = $syncService->importWarehouseSnapshot($cabinet, WbStockHistoryCalendar::todayDate());
            $warehouseWarning = ($warehouses['success'] ?? false)
                ? null
                : ($warehouses['messages'][0] ?? null);

            WbStockHistoryJobStatus::stage(
                $this->cabinetId,
                'stocks',
                WbStockHistoryJobStatus::STAGE_SAVING,
                'Сохраняем данные',
                90,
            );

            if ($this->enableTracking) {
                $settings->stocks_tracking_enabled = true;
            }
            $settings->stocks_status = $settings->stocks_tracking_enabled
                ? WbHistoryLoadStatus::Active
                : WbHistoryLoadStatus::Idle;
            $settings->stocks_last_error = $warehouseWarning;
            $settings->save();
            WbStockHistoryJobStatus::done(
                $this->cabinetId,
                'stocks',
                $this->force ? 'История остатков обновлена' : 'История остатков готова',
            );
        } catch (Throwable $e) {
            $settings->stocks_status = WbHistoryLoadStatus::Error;
            $settings->stocks_last_error = 'Не удалось загрузить историю остатков. Попробуйте ещё раз.';
            $settings->save();
            WbStockHistoryJobStatus::failed($this->cabinetId, 'stocks', $settings->stocks_last_error);
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('[ProcessWbStockHistoryBackfillJob] failed', [
            'cabinet_id' => $this->cabinetId,
            'message' => $exception->getMessage(),
        ]);

        $settings = WbStockHistorySetting::query()->where('cabinet_id', $this->cabinetId)->first();
        if ($settings) {
            $settings->stocks_status = WbHistoryLoadStatus::Error;
            $settings->stocks_last_error = 'Не удалось загрузить историю остатков. Попробуйте ещё раз.';
            $settings->save();
        }
        WbStockHistoryJobStatus::failed($this->cabinetId, 'stocks', 'Не удалось загрузить историю остатков. Попробуйте ещё раз.');
    }
}
