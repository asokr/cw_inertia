<?php

namespace App\Services\Subscriber\Concerns;

use App\Enums\Credits\CreditServiceCode;
use App\Exceptions\Credits\CreditPriceNotFoundException;
use App\Models\User;
use App\Services\Credits\CreditBillingService;
use App\Services\Credits\CreditPriceCalculator;
use App\Services\Credits\CreditQuote;
use App\Services\Credits\CreditSpendRequest;
use Illuminate\Support\Str;

/**
 * Списание кредитов за ИИ-ответ на отзыв WB через общий CreditBillingService.
 * Стоимость берётся из каталога credit_services (code=feedback_answer).
 */
trait ChargesFeedbackAnswerCredits
{
    protected function feedbackAnswerBilling(): CreditBillingService
    {
        return app(CreditBillingService::class);
    }

    protected function feedbackAnswerPrices(): CreditPriceCalculator
    {
        return app(CreditPriceCalculator::class);
    }

    protected function quoteFeedbackAnswer(): CreditQuote
    {
        return $this->feedbackAnswerPrices()->quote(CreditServiceCode::FeedbackAnswer->value);
    }

    /**
     * Стоимость для UI. Если каталог ещё не готов — 0, страница не падает.
     */
    protected function feedbackAnswerCreditsCost(): int
    {
        try {
            return max(0, $this->quoteFeedbackAnswer()->amount);
        } catch (CreditPriceNotFoundException) {
            return 0;
        }
    }

    /**
     * Списывает кредиты за успешно выполненную генерацию.
     *
     * @param  array<string, mixed>  $params
     */
    protected function spendFeedbackAnswerCredits(User $user, array $params, ?string $idempotencyKey = null): void
    {
        $quote = $this->quoteFeedbackAnswer();
        $key = $idempotencyKey ?: $this->manualFeedbackAnswerKey($user);
        $label = $this->feedbackAnswerUserLabel();

        $this->feedbackAnswerBilling()->spend($user, new CreditSpendRequest(
            amount: $quote->amount,
            serviceCode: CreditServiceCode::FeedbackAnswer->value,
            idempotencyKey: $key,
            operationParams: array_merge($params, [
                'user_label' => $label,
            ]),
            userLabel: $label,
            description: $label,
        ));
    }

    protected function hasEnoughFeedbackAnswerCredits(User $user): bool
    {
        try {
            $quote = $this->quoteFeedbackAnswer();
        } catch (CreditPriceNotFoundException) {
            return false;
        }

        return $this->feedbackAnswerBilling()->hasEnough($user, $quote->amount);
    }

    protected function autoFeedbackAnswerKey(int $cabinetId, string $reviewId): string
    {
        return CreditServiceCode::FeedbackAnswer->value.':auto:'.$cabinetId.':'.$reviewId;
    }

    protected function manualFeedbackAnswerKey(User $user): string
    {
        return CreditServiceCode::FeedbackAnswer->value.':manual:'.$user->id.':'.Str::uuid();
    }

    protected function feedbackAnswerUserLabel(): string
    {
        return 'Ответ на отзыв Wildberries';
    }
}
