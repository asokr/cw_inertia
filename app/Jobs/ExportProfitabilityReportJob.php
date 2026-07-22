<?php

namespace App\Jobs;

use App\Exports\Wb\ProfitabilityExport;
use App\Services\Subscriber\Wb\WbProfitabilityReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExportProfitabilityReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public int $cabinetId,
        public int $userId,
        public int $reportId,
    ) {
        $this->onQueue('profitability');
    }

    public function handle(WbProfitabilityReportService $service): void
    {
        try {
            $service->runExportJob($this->cabinetId, $this->userId, $this->reportId);
        } catch (Throwable $exception) {
            Log::error('[ProfitabilityExport] Job failed', [
                'cabinet_id' => $this->cabinetId,
                'report_id' => $this->reportId,
                'message' => $exception->getMessage(),
            ]);

            $service->markExportFailed(
                $this->cabinetId,
                $this->reportId,
                $exception->getMessage()
            );

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(WbProfitabilityReportService::class)->markExportFailed(
            $this->cabinetId,
            $this->reportId,
            $exception?->getMessage() ?? 'Ошибка формирования файла'
        );
    }
}
