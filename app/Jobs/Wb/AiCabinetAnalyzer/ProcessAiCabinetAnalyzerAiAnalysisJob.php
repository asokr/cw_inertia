<?php

namespace App\Jobs\Wb\AiCabinetAnalyzer;

use App\Exceptions\Credits\CreditPriceNotFoundException;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerAiAnalysis;
use App\Models\User;
use App\Services\Credits\AiCabinetAnalyzerCreditCalculator;
use App\Services\Credits\CreditBillingService;
use App\Services\Subscriber\Concerns\ChargesAiCabinetAnalyzerCredits;
use App\Services\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerAiAnalysisService;
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

class ProcessAiCabinetAnalyzerAiAnalysisJob implements ShouldQueue
{
    use ChargesAiCabinetAnalyzerCredits;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private CreditBillingService $creditBilling;

    private AiCabinetAnalyzerCreditCalculator $cabinetAnalyzerCreditCalculator;

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
     * Защита от параллельного прогона одного analysis_id
     * (повторная выдача job database-очередью при малом retry_after / несколько воркеров).
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('wb_ai_cabinet_analyzer_ai:'.$this->analysisId))
                ->expireAfter(3600)
                ->dontRelease(),
        ];
    }

    protected function creditBilling(): CreditBillingService
    {
        return $this->creditBilling;
    }

    protected function cabinetAnalyzerCreditCalculator(): AiCabinetAnalyzerCreditCalculator
    {
        return $this->cabinetAnalyzerCreditCalculator;
    }

    public function handle(
        AiCabinetAnalyzerAiAnalysisService $service,
        CreditBillingService $credits,
        AiCabinetAnalyzerCreditCalculator $calculator,
    ): void {
        $this->creditBilling = $credits;
        $this->cabinetAnalyzerCreditCalculator = $calculator;

        $analysis = AiCabinetAnalyzerAiAnalysis::with(['report.cabinet', 'template'])->find($this->analysisId);

        if (! $analysis || ! $analysis->report || ! $analysis->report->cabinet || ! $analysis->template) {
            Log::warning('[AiCabinetAnalyzerAI] Анализ не найден', [
                'analysis_id' => $this->analysisId,
                'user_id' => $this->userId,
            ]);

            return;
        }

        if ((int) $analysis->report->cabinet->user_id !== $this->userId) {
            Log::warning('[AiCabinetAnalyzerAI] Нет доступа к анализу', [
                'analysis_id' => $this->analysisId,
                'user_id' => $this->userId,
            ]);

            return;
        }

        // Уже успешно завершён (другая попытка/воркер) — не тратим токены повторно.
        if ((string) $analysis->status === AiCabinetAnalyzerAiAnalysis::STATUS_DONE) {
            Log::info('[AiCabinetAnalyzerAI] Анализ уже в статусе done, повторный запуск пропущен', [
                'analysis_id' => $analysis->id,
                'attempt' => $this->attempts(),
            ]);
            $credits->captureOpenHold((string) ($analysis->credit_idempotency_key ?? ''));

            return;
        }

        DB::transaction(function () use ($analysis): void {
            $analysis->status = AiCabinetAnalyzerAiAnalysis::STATUS_PROCESSING;
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

            // Пока ходили в ИИ, другой воркер мог уже сохранить done.
            $analysis->refresh();
            if ((string) $analysis->status === AiCabinetAnalyzerAiAnalysis::STATUS_DONE) {
                Log::info('[AiCabinetAnalyzerAI] Результат уже сохранён другим процессом, пропуск записи', [
                    'analysis_id' => $analysis->id,
                ]);
                $credits->captureOpenHold((string) ($analysis->credit_idempotency_key ?? ''));

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

            DB::transaction(function () use ($analysis, $result, $analysisText, $analysisJson, $analysisMarkdown, $credits): void {
                $analysis->status = AiCabinetAnalyzerAiAnalysis::STATUS_DONE;
                $analysis->model = (string) ($result['model'] ?? $analysis->model);
                $analysis->provider = (string) ($result['provider'] ?? $analysis->provider ?? '');
                $analysis->analysis_text = $analysisText ?: null;
                $analysis->analysis_json = $analysisJson ?: null;
                $analysis->analysis_markdown = $analysisMarkdown ?: null;
                $analysis->input_tokens = (int) ($result['input_tokens'] ?? 0);
                $analysis->output_tokens = (int) ($result['output_tokens'] ?? 0);
                $analysis->total_tokens = (int) ($result['total_tokens'] ?? 0);
                $analysis->error_message = null;
                $analysis->finished_at = now();
                $analysis->save();

                $this->settleSuccessfulAnalysis($analysis, $result, $credits);
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
                    Log::warning('[AiCabinetAnalyzerAI] Обнаружено расхождение длины analysis_markdown после сохранения', $diagnosticContext);
                } else {
                    Log::info('[AiCabinetAnalyzerAI] Проверка длины analysis_markdown после сохранения пройдена', $diagnosticContext);
                }
            }
        } catch (Throwable $exception) {
            Log::error('[AiCabinetAnalyzerAI] Ошибка ИИ-анализа', [
                'analysis_id' => $analysis->id,
                'attempt' => $this->attempts(),
                'tries' => $this->tries,
                'message' => $exception->getMessage(),
            ]);

            // Не помечаем failed до последней попытки: иначе UI/старт нового анализа
            // и параллельные ретраи накладываются, а в ИИ уходит полный отчёт несколько раз.
            if ($this->isFinalAttempt()) {
                DB::transaction(function () use ($analysis, $exception, $credits): void {
                    $analysis->status = AiCabinetAnalyzerAiAnalysis::STATUS_FAILED;
                    $analysis->error_message = mb_substr($exception->getMessage(), 0, 5000);
                    $analysis->finished_at = now();
                    $analysis->save();

                    $credits->releaseOpenHold((string) ($analysis->credit_idempotency_key ?? ''));
                });
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function settleSuccessfulAnalysis(
        AiCabinetAnalyzerAiAnalysis $analysis,
        array $result,
        CreditBillingService $credits,
    ): void {
        $user = User::query()->find($this->userId);
        if (! $user) {
            $credits->captureOpenHold((string) ($analysis->credit_idempotency_key ?? ''));

            return;
        }

        $calls = (array) ($result['calls'] ?? []);
        if ($calls === []) {
            $calls = [[
                'provider' => (string) ($result['provider'] ?? 'gemini'),
                'model' => (string) ($result['model'] ?? $analysis->model ?? ''),
                'input_tokens' => (int) ($result['input_tokens'] ?? 0),
                'output_tokens' => (int) ($result['output_tokens'] ?? 0),
            ]];
        }

        try {
            $quote = $this->cabinetAnalyzerCreditCalculator()->quoteCalls($calls);
        } catch (CreditPriceNotFoundException $exception) {
            Log::warning('[AiCabinetAnalyzerAI] Нет тарифа для списания, фиксируем резерв', [
                'analysis_id' => $analysis->id,
                'message' => $exception->getMessage(),
            ]);
            $credits->captureOpenHold((string) ($analysis->credit_idempotency_key ?? ''));

            return;
        }

        $this->settleAnalysisCredits($user, $analysis, 'wb', $quote);
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
