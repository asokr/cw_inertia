<?php

namespace App\Services\Credits;

use App\Exceptions\Credits\CreditPriceNotFoundException;
use App\Models\Credits\AiCabinetAnalyzerCreditTariff;
use Illuminate\Support\Facades\Schema;

class AiCabinetAnalyzerCreditCalculator
{
    public const RESERVE_MULTIPLIER = 1.3;

    public function isReady(): bool
    {
        return Schema::hasTable('ai_cabinet_analyzer_credit_tariffs');
    }

    /**
     * Стоимость по фактическим вызовам ИИ (без запаса на резерв).
     *
     * @param  list<array{provider?: string, model?: string, input_tokens?: int, output_tokens?: int}>  $calls
     */
    public function quoteCalls(array $calls): AiCabinetAnalyzerCreditQuote
    {
        return $this->quote($calls, 1.0, false);
    }

    /**
     * Оценка для резерва: те же вызовы × запас, чтобы не блокировать анализ из‑за небольшого расхождения.
     *
     * @param  list<array{provider?: string, model?: string, input_tokens?: int, output_tokens?: int}>  $calls
     */
    public function quoteReserve(array $calls): AiCabinetAnalyzerCreditQuote
    {
        return $this->quote($calls, self::RESERVE_MULTIPLIER, true);
    }

    /**
     * @param  list<array{provider?: string, model?: string, input_tokens?: int, output_tokens?: int}>  $calls
     */
    private function quote(array $calls, float $multiplier, bool $forReserve): AiCabinetAnalyzerCreditQuote
    {
        if (! $this->isReady()) {
            throw new CreditPriceNotFoundException('Не настроена стоимость ИИ-анализа кабинета');
        }

        $pricedCalls = [];
        $rawTotal = 0.0;

        foreach ($calls as $call) {
            $provider = $this->normalizeProvider((string) ($call['provider'] ?? ''));
            $model = trim((string) ($call['model'] ?? ''));
            $inputTokens = max(0, (int) ($call['input_tokens'] ?? 0));
            $outputTokens = max(0, (int) ($call['output_tokens'] ?? 0));

            if ($provider === '' || $model === '') {
                continue;
            }

            if ($inputTokens === 0 && $outputTokens === 0) {
                continue;
            }

            $tariff = $this->findTariff($provider, $model);
            $coefficient = (float) $tariff->coefficient;
            if ($coefficient <= 0) {
                $coefficient = 1.0;
            }

            $inputRate = (float) $tariff->input_credits_per_1k;
            $outputRate = (float) $tariff->output_credits_per_1k;
            $raw = (($inputTokens / 1000) * $inputRate + ($outputTokens / 1000) * $outputRate) * $coefficient;
            $rawTotal += $raw;

            $pricedCalls[] = [
                'provider' => $provider,
                'model' => (string) $tariff->model,
                'requested_model' => $model,
                'matched_default' => mb_strtolower((string) $tariff->model) !== mb_strtolower($model),
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'input_credits_per_1k' => $this->formatRate((string) $tariff->input_credits_per_1k, 6),
                'output_credits_per_1k' => $this->formatRate((string) $tariff->output_credits_per_1k, 6),
                'coefficient' => $this->formatRate((string) $tariff->coefficient, 4),
                'raw_credits' => round($raw, 6),
            ];
        }

        if ($pricedCalls === []) {
            throw new CreditPriceNotFoundException('Не удалось рассчитать стоимость ИИ-анализа кабинета');
        }

        $scaled = $rawTotal * $multiplier;
        $amount = max(1, (int) ceil($scaled));

        $providers = array_values(array_unique(array_column($pricedCalls, 'provider')));
        $models = array_values(array_unique(array_column($pricedCalls, 'model')));

        return new AiCabinetAnalyzerCreditQuote(
            amount: $amount,
            snapshot: [
                'calls' => $pricedCalls,
                'raw_total' => round($rawTotal, 6),
                'multiplier' => $forReserve ? $multiplier : 1.0,
                'scaled_total' => round($scaled, 6),
                'credits_charged' => $amount,
                'rounding' => 'ceil',
                'for_reserve' => $forReserve,
                'provider' => count($providers) === 1 ? $providers[0] : 'mixed',
                'model' => count($models) === 1 ? $models[0] : 'mixed',
            ],
        );
    }

    public function findTariff(string $provider, string $model): AiCabinetAnalyzerCreditTariff
    {
        $provider = $this->normalizeProvider($provider);
        $model = trim($model);

        if ($provider === '' || $model === '') {
            throw new CreditPriceNotFoundException('Не заданы провайдер или модель для расчёта стоимости');
        }

        $exact = AiCabinetAnalyzerCreditTariff::query()
            ->active()
            ->where('provider', $provider)
            ->where('model', $model)
            ->first();

        if ($exact) {
            return $exact;
        }

        $default = AiCabinetAnalyzerCreditTariff::query()
            ->active()
            ->where('provider', $provider)
            ->where('is_default', true)
            ->first();

        if ($default) {
            return $default;
        }

        throw new CreditPriceNotFoundException(
            'Не задана стоимость ИИ-анализа кабинета. Напишите в поддержку.'
        );
    }

    public function normalizeProvider(string $provider): string
    {
        $normalized = mb_strtolower(trim($provider));

        return match ($normalized) {
            'openai', 'chatgpt' => AiCabinetAnalyzerCreditTariff::PROVIDER_GPT,
            'google' => AiCabinetAnalyzerCreditTariff::PROVIDER_GEMINI,
            default => $normalized,
        };
    }

    private function formatRate(string $value, int $decimals): string
    {
        return number_format((float) $value, $decimals, '.', '');
    }
}
