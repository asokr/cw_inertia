<?php

namespace App\Services\Subscriber\Concerns;

use App\Enums\Credits\CreditServiceCode;
use App\Exceptions\Credits\InvalidCreditOperationException;
use App\Models\User;
use App\Services\Credits\CreditBillingService;
use App\Services\Credits\CreditSpendRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Резерв при запуске AI-анализа и списание при отдаче готового результата.
 * Сами проводки идут только через общий CreditBillingService.
 */
trait ChargesAiCabinetAnalyzerCredits
{
    abstract protected function creditBilling(): CreditBillingService;

    /**
     * @param  object{id: int, name: string}  $template
     * @param  object{id: int, credit_idempotency_key?: ?string}  $analysis
     */
    protected function reserveTemplateCredits(
        User $user,
        object $template,
        object $analysis,
        string $marketplace,
        int $reportId,
    ): void {
        $serviceCode = $this->cabinetAnalyzerServiceCode($marketplace);
        $amount = $template->creditsCost();
        $key = $serviceCode.':analysis:'.$analysis->id.':gen:'.Str::uuid();
        $label = $this->cabinetAnalyzerUserLabel($marketplace, (string) $template->name);

        $this->creditBilling()->reserve($user, new CreditSpendRequest(
            amount: $amount,
            serviceCode: $serviceCode,
            idempotencyKey: $key,
            operationParams: [
                'marketplace' => $marketplace,
                'template_id' => (int) $template->id,
                'template_name' => (string) $template->name,
                'analysis_id' => (int) $analysis->id,
                'report_id' => $reportId,
                'user_label' => $label,
            ],
            userLabel: $label,
            description: $label,
        ), now()->addHours(4));

        $analysis->credit_idempotency_key = $key;
        $analysis->save();
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
