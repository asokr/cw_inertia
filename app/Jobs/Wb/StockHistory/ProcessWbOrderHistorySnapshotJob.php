<?php

namespace App\Jobs\Wb\StockHistory;

use App\Enums\WbHistoryLoadStatus;
use App\Models\Subscribers\Wb\StockHistory\WbStockHistorySetting;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Services\Wb\StockHistory\WbStockHistorySyncService;
use App\Support\Wb\WbStockHistoryCalendar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ежедневный снимок заказов WB за вчера.
 */
class ProcessWbOrderHistorySnapshotJob implements ShouldBeUnique, ShouldQueue
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
        public readonly ?string $orderDate = null,
    ) {
        $this->onQueue('wb_stock_history');
    }

    public function uniqueId(): string
    {
        $date = $this->orderDate ?: WbStockHistoryCalendar::yesterdayDate();

        return 'wb-order-history-snapshot-'.$this->cabinetId.'-'.$date;
    }

    public function handle(WbStockHistorySyncService $syncService): void
    {
        $cabinet = WbCabinet::query()->find($this->cabinetId);
        if (! $cabinet) {
            return;
        }

        $settings = WbStockHistorySetting::query()->where('cabinet_id', $this->cabinetId)->first();
        if (! $settings || ! $settings->orders_tracking_enabled) {
            return;
        }

        $date = $this->orderDate ?: $syncService->yesterdayDate();

        try {
            $result = $syncService->importOrders($cabinet, $date, $date, false);
            $settings->orders_status = WbHistoryLoadStatus::Active;
            $settings->orders_last_error = ($result['success'] ?? false)
                ? null
                : ($result['messages'][0] ?? 'Не удалось обновить заказы за вчера.');
            $settings->save();
        } catch (Throwable $e) {
            Log::error('[ProcessWbOrderHistorySnapshotJob] snapshot failed', [
                'cabinet_id' => $this->cabinetId,
                'order_date' => $date,
                'message' => $e->getMessage(),
            ]);
            $settings->orders_status = WbHistoryLoadStatus::Active;
            $settings->orders_last_error = 'Не удалось обновить заказы за вчера. Попробуем снова завтра.';
            $settings->save();
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('[ProcessWbOrderHistorySnapshotJob] failed', [
            'cabinet_id' => $this->cabinetId,
            'message' => $exception->getMessage(),
        ]);

        $settings = WbStockHistorySetting::query()->where('cabinet_id', $this->cabinetId)->first();
        if (! $settings) {
            return;
        }
        $settings->orders_status = WbHistoryLoadStatus::Active;
        $settings->orders_last_error = 'Не удалось обновить заказы за вчера. Попробуем снова завтра.';
        $settings->save();
    }
}
