<?php

namespace App\Jobs\Oz\AiCabinetAnalyzer;

use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\Oz\AiCabinetAnalyzer\OzAiCabinetAnalyzerAiAnalysis;
use App\Services\Oz\AiCabinetAnalyzer\OzAiCabinetAnalyzerAiAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ProcessOzAiCabinetAnalyzerAiAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 3;

    public function __construct(
        public int $analysisId,
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
            (new WithoutOverlapping('oz_ai_cabinet_analyzer_ai:'.$this->analysisId))
                ->expireAfter(3600)
                ->dontRelease(),
        ];
    }

    public function handle(OzAiCabinetAnalyzerAiAnalysisService $service): void
    {
        $analysis = OzAiCabinetAnalyzerAiAnalysis::with(['report.cabinet', 'template'])->find($this->analysisId);

        if (! $analysis || ! $analysis->report || ! $analysis->report->cabinet || ! $analysis->template) {
            Log::warning('[OzAiCabinetAnalyzerAI] Анализ не найден', [
                'analysis_id' => $this->analysisId,
                'user_id' => $this->userId,
            ]);

            return;
        }

        if ((int) $analysis->report->cabinet->user_id !== $this->userId) {
            Log::warning('[OzAiCabinetAnalyzerAI] Нет доступа к анализу', [
                'analysis_id' => $this->analysisId,
                'user_id' => $this->userId,
            ]);

            return;
        }

        if ((string) $analysis->status === OzAiCabinetAnalyzerAiAnalysis::STATUS_DONE) {
            Log::info('[OzAiCabinetAnalyzerAI] Анализ уже в статусе done, повторный запуск пропущен', [
                'analysis_id' => $analysis->id,
                'attempt' => $this->attempts(),
            ]);

            return;
        }

        DB::transaction(function () use ($analysis): void {
            $analysis->status = OzAiCabinetAnalyzerAiAnalysis::STATUS_PROCESSING;
            $analysis->started_at = $analysis->started_at ?? now();
            $analysis->error_message = null;
            $analysis->finished_at = null;
            $analysis->save();
        });

        try {
            // Resolve subscriber for central AI logging (spending attribution)
            $subscriberId = null;
            if ($analysis->report && $analysis->report->cabinet) {
                $subscriber = Subscribers::where('user_id', $this->userId)->first();
                $subscriberId = $subscriber?->id;
            }

            $result = $service->run(
                report: $analysis->report,
                template: $analysis->template,
                requestedModel: (string) ($analysis->model ?? ''),
                userId: $this->userId,
                subscriberId: $subscriberId,
            );

            $analysis->refresh();
            if ((string) $analysis->status === OzAiCabinetAnalyzerAiAnalysis::STATUS_DONE) {
                Log::info('[OzAiCabinetAnalyzerAI] Результат уже сохранён другим процессом, пропуск записи', [
                    'analysis_id' => $analysis->id,
                ]);

                return;
            }

            $analysisText = trim((string) ($result['analysis_text'] ?? ''));
            $analysisJson = (array) ($result['analysis_json'] ?? []);
            $analysisMarkdown = trim((string) ($result['analysis_markdown'] ?? ''));
            $analysisMarkdownLength = mb_strlen($analysisMarkdown);

            // For markdown we accept if markdown is non-empty even if text/json empty
            $isMarkdownResult = ! empty($analysisMarkdown);
            if (! $isMarkdownResult && $this->isEmptyAnalysis($analysisText, $analysisJson)) {
                throw new RuntimeException('AI вернул пустой анализ. Сохранение результата отменено.');
            }

            DB::transaction(function () use ($analysis, $result, $analysisText, $analysisJson, $analysisMarkdown): void {
                $analysis->status = OzAiCabinetAnalyzerAiAnalysis::STATUS_DONE;
                $analysis->model = (string) ($result['model'] ?? $analysis->model);
                $analysis->analysis_text = $analysisText ?: null;
                $analysis->analysis_json = $analysisJson ?: null;
                $analysis->analysis_markdown = $analysisMarkdown ?: null;
                $analysis->input_tokens = (int) ($result['input_tokens'] ?? 0);
                $analysis->output_tokens = (int) ($result['output_tokens'] ?? 0);
                $analysis->total_tokens = (int) ($result['total_tokens'] ?? 0);
                $analysis->error_message = null;
                $analysis->finished_at = now();
                $analysis->save();
            });

            if ($analysisMarkdownLength > 0) {
                $savedAnalysis = $analysis->fresh();
                $savedMarkdownLength = mb_strlen((string) ($savedAnalysis?->analysis_markdown ?? ''));

                $diagnosticContext = [
                    'analysis_id' => $analysis->id,
                    'provider_model' => (string) ($result['model'] ?? $analysis->model),
                    'output_tokens' => (int) ($result['output_tokens'] ?? 0),
                    'max_output_tokens' => (int) ($result['max_output_tokens'] ?? 0),
                    'markdown_length_before_save' => $analysisMarkdownLength,
                    'markdown_length_after_save' => $savedMarkdownLength,
                ];

                if ($savedMarkdownLength !== $analysisMarkdownLength) {
                    Log::warning('[OzAiCabinetAnalyzerAI] Обнаружено расхождение длины analysis_markdown после сохранения', $diagnosticContext);
                } else {
                    Log::info('[OzAiCabinetAnalyzerAI] Проверка длины analysis_markdown после сохранения пройдена', $diagnosticContext);
                }
            }
        } catch (Throwable $exception) {
            Log::error('[OzAiCabinetAnalyzerAI] Ошибка ИИ-анализа', [
                'analysis_id' => $analysis->id,
                'attempt' => $this->attempts(),
                'tries' => $this->tries,
                'message' => $exception->getMessage(),
            ]);

            if ($this->isFinalAttempt()) {
                DB::transaction(function () use ($analysis, $exception): void {
                    $analysis->status = OzAiCabinetAnalyzerAiAnalysis::STATUS_FAILED;
                    $analysis->error_message = mb_substr($exception->getMessage(), 0, 5000);
                    $analysis->finished_at = now();
                    $analysis->save();
                });
            }

            throw $exception;
        }
    }

    private function isFinalAttempt(): bool
    {
        $maxTries = $this->job?->maxTries() ?? $this->tries;

        return $this->attempts() >= (int) $maxTries;
    }

    private function isEmptyAnalysis(string $analysisText, array $analysisJson): bool
    {
        if ($analysisText !== '') {
            return false;
        }

        $summary = trim((string) ($analysisJson['summary'] ?? ''));
        $insights = (array) ($analysisJson['insights'] ?? []);
        $risks = (array) ($analysisJson['risks'] ?? []);
        $actions = (array) ($analysisJson['actions'] ?? []);
        $metrics = (array) ($analysisJson['metrics'] ?? []);

        return $summary === ''
            && empty($insights)
            && empty($risks)
            && empty($actions)
            && empty($metrics);
    }
}
