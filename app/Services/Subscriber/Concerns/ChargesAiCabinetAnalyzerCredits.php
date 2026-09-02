<?php

namespace App\Services\Subscriber\Concerns;

use App\Enums\Credits\CreditServiceCode;
use App\Exceptions\Credits\InvalidCreditOperationException;
use App\Models\Credits\AiCabinetAnalyzerCreditCharge;
use App\Models\User;
use App\Services\Credits\AiCabinetAnalyzerCreditCalculator;
use App\Services\Credits\AiCabinetAnalyzerCreditQuote;
use App\Services\Credits\CreditBillingService;
use App\Services\Credits\CreditSpendRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Резерв при запуске AI-анализа и списание по фактическим токенам.
 * Сами проводки идут только через общий CreditBillingService.
 */
trait ChargesAiCabinetAnalyzerCredits
{
    abstract protected function creditBilling(): CreditBillingService;

    abstract protected function cabinetAnalyzerCreditCalculator(): AiCabinetAnalyzerCreditCalculator;

    /**
     * @param  object{id: int, name: string}  $template
     * @param  object{id: int, credit_idempotency_key?: ?string}  $analysis
     * @param  list<array{provider?: string, model?: string, input_tokens?: int, output_tokens?: int}>  $estimatedCalls
     */
    protected function reserveEstimatedCredits(
        User $user,
        object $template,
        object $analysis,
        string $marketplace,
        int $reportId,
        array $estimatedCalls,
    ): int {
        $serviceCode = $this->cabinetAnalyzerServiceCode($marketplace);
        $quote = $this->cabinetAnalyzerCreditCalculator()->quoteReserve($estimatedCalls);
        $key = $serviceCode.':analysis:'.$analysis->id.':gen:'.Str::uuid();
        $label = $this->cabinetAnalyzerUserLabel($marketplace, (string) $template->name);

        $this->creditBilling()->reserve($user, new CreditSpendRequest(
            amount: $quote->amount,
            serviceCode: $serviceCode,
            idempotencyKey: $key,
            operationParams: [
                'marketplace' => $marketplace,
                'template_id' => (int) $template->id,
                'template_name' => (string) $template->name,
                'analysis_id' => (int) $analysis->id,
                'report_id' => $reportId,
                'user_label' => $label,
                'credits_reserved' => $quote->amount,
                'reserve_snapshot' => $quote->snapshot,
            ],
            userLabel: $label,
            description: $label,
        ), now()->addHours(4));

        $analysis->credit_idempotency_key = $key;
        $analysis->save();

        return $quote->amount;
    }

    /**
     * Списание фактической стоимости и запись истории расчёта.
     *
     * @param  object{
     *     id: int,
     *     credit_idempotency_key?: ?string,
     *     provider?: ?string,
     *     model?: ?string,
     *     input_tokens?: int,
     *     output_tokens?: int,
     *     total_tokens?: int,
     *     credits_charged?: ?int,
     *     billing_snapshot?: mixed
     * }  $analysis
     */
    protected function settleAnalysisCredits(
        User $user,
        object $analysis,
        string $marketplace,
        AiCabinetAnalyzerCreditQuote $quote,
    ): void {
        $key = (string) ($analysis->credit_idempotency_key ?? '');
        $hold = $key !== '' ? $this->creditBilling()->findHoldByIdempotency($key) : null;
        $reserved = (int) ($hold?->amount ?? 0);

        $snapshot = $quote->snapshot;
        $snapshot['credits_reserved'] = $reserved;
        $snapshot['credits_charged'] = $quote->amount;

        $ledger = null;
        if ($key !== '') {
            $ledger = $this->creditBilling()->settleOpenHold($key, $quote->amount, [
                'billing' => $snapshot,
                'credits_charged' => $quote->amount,
            ]);
            $params = is_array($ledger?->operation_params) ? $ledger->operation_params : [];
            if (($params['undercharged'] ?? false) === true) {
                $snapshot['undercharged'] = true;
                $snapshot['requested_credits'] = (int) ($params['requested_credits'] ?? $quote->amount);
                $snapshot['credits_charged'] = $reserved;
            }
        }

        $charged = $ledger !== null
            ? (int) $ledger->amount
            : (int) ($snapshot['credits_charged'] ?? $quote->amount);
        $snapshot['credits_charged'] = $charged;

        $analysis->credits_charged = $charged;
        $analysis->billing_snapshot = $snapshot;
        $analysis->save();

        AiCabinetAnalyzerCreditCharge::query()->create([
            'marketplace' => $marketplace,
            'analysis_type' => $analysis->getMorphClass(),
            'analysis_id' => (int) $analysis->id,
            'user_id' => (int) $user->id,
            'provider' => (string) ($snapshot['provider'] ?? $analysis->provider ?? ''),
            'model' => (string) ($snapshot['model'] ?? $analysis->model ?? ''),
            'input_tokens' => (int) ($analysis->input_tokens ?? 0),
            'output_tokens' => (int) ($analysis->output_tokens ?? 0),
            'total_tokens' => (int) ($analysis->total_tokens ?? 0),
            'credits_reserved' => $reserved,
            'credits_charged' => $charged,
            'tariff_snapshot' => $snapshot,
            'credit_idempotency_key' => $key !== '' ? $key : null,
        ]);
    }

    /**
     * Списание зарезервированных кредитов в момент отдачи готового анализа на фронт.
     *
     * @param  object{status: string, credit_idempotency_key?: ?string}  $analysis
     */
    protected function captureDeliveredAnalysisCredits(object $analysis): void
    {
        if ((string) $analysis->status !== $analysis::STATUS_DONE) {
            return;
        }

        $key = (string) ($analysis->credit_idempotency_key ?? '');
        if ($key === '') {
            return;
        }

        try {
            $this->creditBilling()->captureOpenHold($key);
        } catch (InvalidCreditOperationException $exception) {
            Log::warning('[Credits] Не удалось списать резерв ИИ-анализа', [
                'key' => $key,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  object{credit_idempotency_key?: ?string}  $analysis
     */
    protected function releaseAnalysisCredits(object $analysis): void
    {
        $key = (string) ($analysis->credit_idempotency_key ?? '');
        if ($key === '') {
            return;
        }

        $this->creditBilling()->releaseOpenHold($key);
    }

    protected function cabinetAnalyzerServiceCode(string $marketplace): string
    {
        return $marketplace === 'ozon'
            ? CreditServiceCode::OzAiCabinetAnalyzer->value
            : CreditServiceCode::WbAiCabinetAnalyzer->value;
    }

    protected function cabinetAnalyzerUserLabel(string $marketplace, string $templateName): string
    {
        $prefix = $marketplace === 'ozon'
            ? 'ИИ-анализ кабинета Ozon'
            : 'ИИ-анализ кабинета WB';

        return $prefix.': '.$templateName;
    }
}
