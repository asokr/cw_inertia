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
 * Снимок остатков WB: вчера из CSV (если ещё нет) и сегодня из живых остатков.
 */
class ProcessWbStockHistorySnapshotJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Пока job в очереди или в работе, не ставить второй снимок за тот же день. Интервал расписания — 4 часа. */
    public int $uniqueFor = 14400;

    public int $tries = 2;

    public int $timeout = 1800;

    public function __construct(
        public readonly int $cabinetId,
        public readonly ?string $stockDate = null,
    ) {
        $this->onQueue('wb_stock_history');
    }

    public function uniqueId(): string
    {
        $date = $this->stockDate ?: WbStockHistoryCalendar::todayDate();

        return 'wb-stock-history-snapshot-'.$this->cabinetId.'-'.$date;
    }

    public function handle(WbStockHistorySyncService $syncService): void
    {
        $cabinet = WbCabinet::query()->find($this->cabinetId);
        if (! $cabinet) {
            return;
        }

        $settings = WbStockHistorySetting::query()->where('cabinet_id', $this->cabinetId)->first();
        if (! $settings || ! $settings->stocks_tracking_enabled) {
            return;
        }

        $historyDate = $this->stockDate ?: $syncService->yesterdayDate();

        try {
            $result = $syncService->importStockHistory($cabinet, $historyDate, $historyDate, false);
            $current = $syncService->importCurrentStocks($cabinet);
            $settings->stocks_status = WbHistoryLoadStatus::Active;
            $error = null;
            if (! ($result['success'] ?? false)) {
                $error = $result['messages'][0] ?? 'Не удалось обновить историю остатков.';
            } elseif (! ($current['success'] ?? false)) {
                $error = $current['messages'][0] ?? null;
            }
            $settings->stocks_last_error = $error;
            $settings->save();
        } catch (Throwable $e) {
            Log::error('[ProcessWbStockHistorySnapshotJob] snapshot failed', [
                'cabinet_id' => $this->cabinetId,
                'stock_date' => $historyDate,
                'message' => $e->getMessage(),
            ]);
            $settings->stocks_status = WbHistoryLoadStatus::Active;
            $settings->stocks_last_error = 'Не удалось обновить остатки. Попробуем снова позже.';
            $settings->save();
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('[ProcessWbStockHistorySnapshotJob] failed', [
            'cabinet_id' => $this->cabinetId,
            'message' => $exception->getMessage(),
        ]);

        $settings = WbStockHistorySetting::query()->where('cabinet_id', $this->cabinetId)->first();
        if (! $settings) {
            return;
        }
        $settings->stocks_status = WbHistoryLoadStatus::Active;
        $settings->stocks_last_error = 'Не удалось обновить остатки. Попробуем снова позже.';
        $settings->save();
    }
}
