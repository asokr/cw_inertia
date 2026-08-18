<?php

namespace App\Services\Subscriber\Concerns;

use App\Enums\AiTaskType;
use App\Enums\Credits\CreditServiceCode;
use App\Exceptions\Credits\CreditPriceNotFoundException;
use App\Exceptions\Credits\InsufficientCreditsException;
use App\Exceptions\Credits\InvalidCreditOperationException;
use App\Models\User;
use App\Services\Credits\CreditBillingService;
use App\Services\Credits\CreditPriceCalculator;
use App\Services\Credits\CreditQuote;
use App\Services\Credits\CreditSpendRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Списание кредитов за генерации в AI Инструментах через общий CreditBillingService.
 * Стоимость берётся из каталога credit_services.
 */
trait ChargesMarketplaceAiCredits
{
    protected function marketplaceCreditBilling(): CreditBillingService
    {
        return app(CreditBillingService::class);
    }

    protected function marketplaceCreditPrices(): CreditPriceCalculator
    {
        return app(CreditPriceCalculator::class);
    }

    protected function quoteMarketplaceText(): CreditQuote
    {
        return $this->marketplaceCreditPrices()->quote(CreditServiceCode::GenerateText->value);
    }

    protected function quoteMarketplaceImage(string $taskType, string $resolution): CreditQuote
    {
        return $this->marketplaceCreditPrices()->quote(
            $this->marketplaceImageServiceCode($taskType),
            ['resolution' => $resolution],
        );
    }

    protected function quoteMarketplaceVideo(string $resolution, int $duration): CreditQuote
    {
        return $this->marketplaceCreditPrices()->quote(CreditServiceCode::GenerateVideo->value, [
            'resolution' => $resolution,
            'duration' => max(1, $duration),
        ]);
    }

    protected function marketplaceImageServiceCode(string $taskType): string
    {
        return $taskType === AiTaskType::EDIT_IMAGE->value
            ? CreditServiceCode::EditImage->value
            : CreditServiceCode::GenerateImage->value;
    }

    protected function hasEnoughMarketplaceCredits(User $user, CreditQuote $quote): bool
    {
        return $this->marketplaceCreditBilling()->hasEnough($user, $quote->amount);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function spendMarketplaceCredits(User $user, CreditQuote $quote, string $key, array $params = []): void
    {
        $label = $this->marketplaceUserLabel($quote->serviceCode);

        $this->marketplaceCreditBilling()->spend($user, new CreditSpendRequest(
            amount: $quote->amount,
            serviceCode: $quote->serviceCode,
            idempotencyKey: $key,
            operationParams: array_merge($params, [
                'user_label' => $label,
                'resolution' => $quote->params['resolution'] ?? null,
                'duration' => $quote->params['duration'] ?? null,
            ]),
            userLabel: $label,
            description: $label,
        ));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function reserveMarketplaceCredits(User $user, CreditQuote $quote, string $key, array $params = []): void
    {
        $label = $this->marketplaceUserLabel($quote->serviceCode);

        $this->marketplaceCreditBilling()->reserve($user, new CreditSpendRequest(
            amount: $quote->amount,
            serviceCode: $quote->serviceCode,
            idempotencyKey: $key,
            operationParams: array_merge($params, [
                'user_label' => $label,
                'resolution' => $quote->params['resolution'] ?? null,
                'duration' => $quote->params['duration'] ?? null,
            ]),
            userLabel: $label,
            description: $label,
        ), now()->addHours(4));
    }

    protected function captureMarketplaceCredits(string $key): void
    {
        if ($key === '') {
            return;
        }

        $this->marketplaceCreditBilling()->captureOpenHold($key);
    }

    protected function releaseMarketplaceCredits(string $key): void
    {
        if ($key === '') {
            return;
        }

        $this->marketplaceCreditBilling()->releaseOpenHold($key);
    }

    protected function marketplaceTextKey(User $user): string
    {
        return CreditServiceCode::GenerateText->value.':user:'.$user->id.':'.Str::uuid();
    }

    protected function marketplaceImageKey(User $user, string $serviceCode): string
    {
        return $serviceCode.':user:'.$user->id.':'.Str::uuid();
    }

    protected function marketplaceVideoKey(): string
    {
        return CreditServiceCode::GenerateVideo->value.':task:'.Str::uuid();
    }

    protected function marketplaceUserLabel(string $serviceCode): string
    {
        return match ($serviceCode) {
            CreditServiceCode::GenerateImage->value => CreditServiceCode::GenerateImage->label(),
            CreditServiceCode::EditImage->value => CreditServiceCode::EditImage->label(),
            CreditServiceCode::GenerateVideo->value => CreditServiceCode::GenerateVideo->label(),
            default => CreditServiceCode::GenerateText->label(),
        };
    }

    protected function insufficientMarketplaceCreditsResponse(User $user, CreditQuote $quote): JsonResponse
    {
        $available = $this->marketplaceCreditBilling()->getBalance($user)->available();

        return response()->json([
            'success' => false,
            'messages' => [(new InsufficientCreditsException($quote->amount, $available))->userMessage()],
            'data' => [
                'credits' => $this->marketplaceCreditsPayload($user),
                'credits_cost' => $quote->amount,
            ],
        ], 200);
    }

    protected function priceNotFoundMarketplaceResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'messages' => ['Не удалось определить стоимость операции. Попробуйте позже.'],
        ], 200);
    }

    /**
     * @return array<string, int>
     */
    protected function marketplaceCreditsPayload(User $user): array
    {
        return $this->marketplaceCreditBilling()->getBalance($user)->toFrontendArray();
    }

    /**
     * @return array{quote: CreditQuote, key: string}|JsonResponse
     */
    protected function beginMarketplaceTextCharge(User $user): array|JsonResponse
    {
        try {
            $quote = $this->quoteMarketplaceText();
        } catch (CreditPriceNotFoundException) {
            return $this->priceNotFoundMarketplaceResponse();
        }

        if (! $this->hasEnoughMarketplaceCredits($user, $quote)) {
            return $this->insufficientMarketplaceCreditsResponse($user, $quote);
        }

        return [
            'quote' => $quote,
            'key' => $this->marketplaceTextKey($user),
        ];
    }

    /**
     * @return array{quote: CreditQuote, key: string}|JsonResponse
     */
    protected function beginMarketplaceImageCharge(User $user, string $taskType, string $resolution): array|JsonResponse
    {
        try {
            $quote = $this->quoteMarketplaceImage($taskType, $resolution);
        } catch (CreditPriceNotFoundException) {
            return $this->priceNotFoundMarketplaceResponse();
        }

        if (! $this->hasEnoughMarketplaceCredits($user, $quote)) {
            return $this->insufficientMarketplaceCreditsResponse($user, $quote);
        }

        return [
            'quote' => $quote,
            'key' => $this->marketplaceImageKey($user, $quote->serviceCode),
        ];
    }

    /**
     * @return array{quote: CreditQuote, key: string}|JsonResponse
     */
    protected function beginMarketplaceVideoReserve(User $user, string $resolution, int $duration): array|JsonResponse
    {
        try {
            $quote = $this->quoteMarketplaceVideo($resolution, $duration);
        } catch (CreditPriceNotFoundException) {
            return $this->priceNotFoundMarketplaceResponse();
        }

        $key = $this->marketplaceVideoKey();

        try {
            $this->reserveMarketplaceCredits($user, $quote, $key, [
                'resolution' => $resolution,
                'duration' => $duration,
            ]);
        } catch (InsufficientCreditsException $exception) {
            return response()->json([
                'success' => false,
                'messages' => [$exception->userMessage()],
                'data' => [
                    'credits' => $this->marketplaceCreditsPayload($user),
                    'credits_cost' => $quote->amount,
                ],
            ], 200);
        } catch (CreditPriceNotFoundException) {
            return $this->priceNotFoundMarketplaceResponse();
        } catch (InvalidCreditOperationException $exception) {
            return response()->json([
                'success' => false,
                'messages' => [$exception->getMessage()],
            ], 200);
        }

        return [
            'quote' => $quote,
            'key' => $key,
        ];
    }
}
