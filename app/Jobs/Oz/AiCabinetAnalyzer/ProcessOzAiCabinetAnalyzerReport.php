<?php

namespace App\Jobs\Oz\AiCabinetAnalyzer;

use App\Models\Subscribers\Oz\AiCabinetAnalyzer\OzAiCabinetAnalyzerReport;
use App\Services\Oz\AiCabinetAnalyzer\OzAiCabinetAnalyzerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessOzAiCabinetAnalyzerReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 3;

    public function __construct(
        public int $reportId,
        public int $userId,
    ) {}

    public function backoff(): array
    {
        return [20, 60, 120];
    }

    public function handle(OzAiCabinetAnalyzerService $service): void
    {
        $report = OzAiCabinetAnalyzerReport::with('cabinet')->find($this->reportId);
        if (! $report || ! $report->cabinet || (int) $report->cabinet->user_id !== $this->userId) {
            Log::warning('[OzAiCabinetAnalyzer] Отчёт не найден или недоступен', [
                'report_id' => $this->reportId,
                'user_id' => $this->userId,
            ]);

            return;
        }

        $resultJson = is_array($report->result_json) ? $report->result_json : [];
        $period = data_get($resultJson, 'meta.period', []);
        $beginDate = (string) ($period['begin_date'] ?? '');
        $endDate = (string) ($period['end_date'] ?? '');
        $defaultsApplied = (bool) data_get($resultJson, 'meta.defaults_applied', false);

        if ($beginDate === '' || $endDate === '') {
            $this->markFailed($report, 'Не задан период анализа.');

            return;
        }

        try {
            Log::info('[OzAiCabinetAnalyzer] Запуск сбора snapshot (каталог + аналитика)', [
                'report_id' => $report->id,
                'cabinet_id' => $report->cabinet_id,
            ]);

            $snapshot = $service->collectReport(
                (string) $report->cabinet->apikey,
                (string) $report->cabinet->client_id,
                $beginDate,
                $endDate,
                $defaultsApplied,
                $report,
                $report->cabinet->performance_client_id ?? null,
                $report->cabinet->performance_client_secret ?? null,
            );

            DB::transaction(function () use ($report, $snapshot): void {
                $report->status = OzAiCabinetAnalyzerReport::STATUS_DONE;
                $report->result_json = $snapshot;
                $report->save();
            });

            Log::info('[OzAiCabinetAnalyzer] Сбор snapshot завершён', [
                'report_id' => $report->id,
                'products_count' => count($snapshot['products'] ?? []),
                'sources' => data_get($snapshot, 'meta.sources_collected', []),
            ]);
        } catch (Throwable $e) {
            Log::error('[OzAiCabinetAnalyzer] Ошибка сбора', [
                'report_id' => $report->id,
                'message' => $e->getMessage(),
            ]);

            $this->markFailed($report, $e->getMessage());
            throw $e;
        }
    }

    private function markFailed(OzAiCabinetAnalyzerReport $report, string $error): void
    {
        $payload = is_array($report->result_json) ? $report->result_json : [];
        data_set($payload, 'meta.error', $error);

        DB::transaction(function () use ($report, $payload): void {
            $report->status = OzAiCabinetAnalyzerReport::STATUS_FAILED;
            $report->result_json = $payload;
            $report->save();
        });
    }
}
