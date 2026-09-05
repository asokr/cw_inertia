<?php

namespace App\Jobs\Oz\StockHistory;

use App\Enums\OzStockHistoryTrackingStatus;
use App\Models\Subscribers\Oz\OzCabinet;
use App\Models\Subscribers\Oz\StockHistory\OzStockHistorySetting;
use App\Services\Oz\StockHistory\OzStockHistorySyncService;
use App\Support\Oz\OzStockHistoryCalendar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Дневной снимок остатков FBO за завершённый московский день.
 */
class ProcessOzStockHistorySnapshotJob implements ShouldQueue, ShouldBeUnique
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
        public readonly ?string $stockDate = null,
        public readonly bool $force = false,
        public readonly bool $fromStart = false,
    ) {
        $this->onQueue('oz_stock_history');
    }

    public function uniqueId(): string
    {
        $date = $this->stockDate ?: OzStockHistoryCalendar::yesterdayDate();

        return 'oz-stock-history-snapshot-'.$this->cabinetId.'-'.$date;
    }

    public function handle(OzStockHistorySyncService $syncService): void
    {
        $cabinet = OzCabinet::query()->find($this->cabinetId);
        if (! $cabinet) {
            return;
        }

        $settings = OzStockHistorySetting::query()->where('cabinet_id', $this->cabinetId)->first();
        if (! $this->fromStart && (! $settings || ! $settings->tracking_enabled)) {
            return;
        }

        $stockDate = $this->stockDate ?: $syncService->yesterdayDate();

        try {
            $result = $syncService->snapshotStocks($cabinet, $stockDate, $this->force);
            $this->markActive($settings, $result['success'] ?? false, $result['messages'][0] ?? null);
        } catch (Throwable $e) {
            Log::error('[ProcessOzStockHistorySnapshotJob] snapshot failed', [
                'cabinet_id' => $this->cabinetId,
                'stock_date' => $stockDate,
                'message' => $e->getMessage(),
            ]);
            $this->markActive($settings, false, 'Не удалось обновить остатки. Попробуем снова вечером.');
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('[ProcessOzStockHistorySnapshotJob] failed', [
            'cabinet_id' => $this->cabinetId,
            'message' => $exception->getMessage(),
        ]);

        $settings = OzStockHistorySetting::query()->where('cabinet_id', $this->cabinetId)->first();
        $this->markActive($settings, false, 'Не удалось обновить остатки. Попробуем снова вечером.');
    }

    private function markActive(?OzStockHistorySetting $settings, bool $ok, ?string $message): void
    {
        if (! $settings) {
            return;
        }

        $settings->tracking_status = OzStockHistoryTrackingStatus::Active;
        if ($ok) {
            $settings->last_error = null;
        } elseif ($message) {
            $settings->last_error = $message;
        }
        $settings->save();
    }
}
