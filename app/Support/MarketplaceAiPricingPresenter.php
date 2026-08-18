<?php

namespace App\Support;

use App\Enums\Credits\CreditServiceCode;
use App\Exceptions\Credits\CreditPriceNotFoundException;
use App\Services\Credits\CreditPriceCalculator;

/**
 * Снимок стоимости AI Инструментов для фронта. Суммы считает каталог, не клиент.
 */
class MarketplaceAiPricingPresenter
{
    public const IMAGE_RESOLUTIONS = ['default', '1K', '2K', '4K'];

    public const VIDEO_RESOLUTIONS = ['480p', '720p'];

    public const VIDEO_MIN_DURATION = 3;

    public const VIDEO_MAX_DURATION = 15;

    public function __construct(
        private readonly CreditPriceCalculator $prices,
    ) {
    }

    /**
     * @return array{
     *     text: array{amount: int},
     *     image: array{amounts: array<string, int>},
     *     video: array{amounts: array<string, array<string, int>>}
     * }
     */
    public function forFrontend(): array
    {
        return [
            'text' => [
                'amount' => $this->safeQuoteAmount(CreditServiceCode::GenerateText->value),
            ],
            'image' => [
                'amounts' => $this->imageAmounts(),
            ],
            'video' => [
                'amounts' => $this->videoAmounts(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{amount: int, service: string, billing_mode: string, params: array<string, mixed>}
     */
    public function quoteFor(string $kind, array $params = []): array
    {
        $quote = match ($kind) {
            'image' => $this->prices->quote(
                (($params['task_type'] ?? '') === 'edit_image')
                    ? CreditServiceCode::EditImage->value
                    : CreditServiceCode::GenerateImage->value,
                ['resolution' => (string) ($params['resolution'] ?? 'default')],
            ),
            'video' => $this->prices->quote(CreditServiceCode::GenerateVideo->value, [
                'resolution' => (string) ($params['resolution'] ?? '480p'),
                'duration' => max(1, (int) ($params['duration'] ?? 1)),
            ]),
            default => $this->prices->quote(CreditServiceCode::GenerateText->value),
        };

        return [
            'amount' => $quote->amount,
            'service' => $quote->serviceCode,
            'billing_mode' => $quote->billingMode,
            'params' => $quote->params,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function imageAmounts(): array
    {
        $amounts = [];

        foreach (self::IMAGE_RESOLUTIONS as $resolution) {
            $amounts[$resolution] = $this->safeQuoteAmount(
                CreditServiceCode::GenerateImage->value,
                ['resolution' => $resolution],
            );
        }

        return $amounts;
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function videoAmounts(): array
    {
        $amounts = [];

        foreach (self::VIDEO_RESOLUTIONS as $resolution) {
            $amounts[$resolution] = [];
            for ($duration = self::VIDEO_MIN_DURATION; $duration <= self::VIDEO_MAX_DURATION; $duration++) {
                $amounts[$resolution][(string) $duration] = $this->safeQuoteAmount(
                    CreditServiceCode::GenerateVideo->value,
                    [
                        'resolution' => $resolution,
                        'duration' => $duration,
                    ],
                );
            }
        }

        return $amounts;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function safeQuoteAmount(string $code, array $params = []): int
    {
        try {
            return max(0, $this->prices->quote($code, $params)->amount);
        } catch (CreditPriceNotFoundException) {
            return 0;
        }
    }
}
