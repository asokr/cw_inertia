<?php

namespace App\Jobs\Wb\AiCabinetAnalyzer;

use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerReport;
use App\Services\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessAiCabinetAnalyzerReport implements ShouldQueue
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

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('wb_ai_cabinet_analyzer_report:'.$this->reportId))
                ->expireAfter(3600)
                ->dontRelease(),
        ];
    }

    public function handle(AiCabinetAnalyzerService $service): void
    {
        $report = AiCabinetAnalyzerReport::with('cabinet')->find($this->reportId);
        if (! $report || ! $report->cabinet || (int) $report->cabinet->user_id !== $this->userId) {
            Log::warning('[AiCabinetAnalyzer] Отчёт не найден или недоступен', [
                'report_id' => $this->reportId,
                'user_id' => $this->userId,
            ]);

            return;
        }

        if ((string) $report->status === AiCabinetAnalyzerReport::STATUS_DONE) {
            Log::info('[AiCabinetAnalyzer] Отчёт уже done, повторный запуск пропущен', [
                'report_id' => $report->id,
                'attempt' => $this->attempts(),
            ]);

            return;
        }

        $resultJson = is_array($report->result_json) ? $report->result_json : [];
        $period = data_get($resultJson, 'meta.period', []);
        $beginDate = (string) ($period['begin_date'] ?? '');
        $endDate = (string) ($period['end_date'] ?? '');

        if ($beginDate === '' || $endDate === '') {
            $this->markFailed($report, 'Не задан период анализа.');

            return;
        }

        try {
            if ((string) $report->status !== AiCabinetAnalyzerReport::STATUS_PROCESSING) {
                DB::transaction(function () use ($report): void {
                    $report->status = AiCabinetAnalyzerReport::STATUS_PROCESSING;
                    $report->save();
                });
            }

            Log::info('[AiCabinetAnalyzer] Запуск анализа', [
                'report_id' => $report->id,
                'cabinet_id' => $report->cabinet_id,
                'begin_date' => $beginDate,
                'end_date' => $endDate,
                'attempt' => $this->attempts(),
            ]);

            $snapshot = $service->collectReport(
                (string) $report->cabinet->apikey,
                $beginDate,
                $endDate,
                $report,
            );

            $report->refresh();
            if ((string) $report->status === AiCabinetAnalyzerReport::STATUS_DONE) {
                Log::info('[AiCabinetAnalyzer] Отчёт уже сохранён другим процессом', [
                    'report_id' => $report->id,
                ]);

                return;
            }

            DB::transaction(function () use ($report, $snapshot): void {
                $report->status = AiCabinetAnalyzerReport::STATUS_DONE;
                $report->result_json = $snapshot;
                $report->save();
            });

            Log::info('[AiCabinetAnalyzer] Анализ завершён', [
                'report_id' => $report->id,
            ]);
        } catch (Throwable $e) {
            Log::error('[AiCabinetAnalyzer] Ошибка анализа', [
                'report_id' => $report->id,
                'attempt' => $this->attempts(),
                'tries' => $this->tries,
                'message' => $e->getMessage(),
            ]);

            if ($this->isFinalAttempt()) {
                $this->markFailed($report, $e->getMessage());
            }

            throw $e;
        }
    }

    private function isFinalAttempt(): bool
    {
        $maxTries = $this->job?->maxTries() ?? $this->tries;

        return $this->attempts() >= (int) $maxTries;
    }

    private function markFailed(AiCabinetAnalyzerReport $report, string $error): void
    {
        $payload = is_array($report->result_json) ? $report->result_json : [];
        data_set($payload, 'meta.error', $error);

        DB::transaction(function () use ($report, $payload): void {
            $report->status = AiCabinetAnalyzerReport::STATUS_FAILED;
            $report->result_json = $payload;
            $report->save();
        });
    }
}
