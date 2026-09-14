<?php

namespace App\Jobs\Wb\StockHistory;

use App\Enums\WbHistoryLoadStatus;
use App\Models\Subscribers\Wb\StockHistory\WbStockHistorySetting;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Services\Wb\StockHistory\WbStockHistorySyncService;
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
 * Загрузка истории заказов WB за выбранный период.
 */
class ProcessWbOrderHistoryBackfillJob implements ShouldBeUnique, ShouldQueue
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
    ) {
        $this->onQueue('wb_stock_history');
    }

    public function uniqueId(): string
    {
        return 'wb-order-history-backfill-'.$this->cabinetId;
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
        $settings->orders_status = WbHistoryLoadStatus::Loading;
        $settings->orders_last_error = null;
        $settings->save();

        try {
            WbStockHistoryJobStatus::stage(
                $this->cabinetId,
                'orders',
                WbStockHistoryJobStatus::STAGE_HISTORY,
                'Собираем историю заказов',
                40,
            );
            $result = $syncService->importOrders($cabinet, $this->from, $this->to, false);
            if (! ($result['success'] ?? false)) {
                $message = $result['messages'][0] ?? 'Не удалось загрузить историю заказов.';
                $settings->orders_status = WbHistoryLoadStatus::Error;
                $settings->orders_last_error = $message;
                $settings->save();
                WbStockHistoryJobStatus::failed($this->cabinetId, 'orders', $message);

                return;
            }

            $settings->orders_tracking_enabled = true;
            $settings->orders_status = WbHistoryLoadStatus::Active;
            $settings->orders_last_error = null;
            $settings->save();
            WbStockHistoryJobStatus::done($this->cabinetId, 'orders', 'История заказов готова');
        } catch (Throwable $e) {
            $settings->orders_status = WbHistoryLoadStatus::Error;
            $settings->orders_last_error = 'Не удалось загрузить историю заказов. Попробуйте ещё раз.';
            $settings->save();
            WbStockHistoryJobStatus::failed($this->cabinetId, 'orders', $settings->orders_last_error);
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('[ProcessWbOrderHistoryBackfillJob] failed', [
            'cabinet_id' => $this->cabinetId,
            'message' => $exception->getMessage(),
        ]);

        $settings = WbStockHistorySetting::query()->where('cabinet_id', $this->cabinetId)->first();
        if (! $settings) {
            return;
        }
        $settings->orders_status = WbHistoryLoadStatus::Error;
        $settings->orders_last_error = 'Не удалось загрузить историю заказов. Попробуйте ещё раз.';
        $settings->save();
        WbStockHistoryJobStatus::failed($this->cabinetId, 'orders', $settings->orders_last_error);
    }
}
