<?php

namespace App\Services\Credits;

use App\Enums\Credits\CreditServiceCode;
use App\Exceptions\Credits\CreditPriceNotFoundException;
use App\Support\ToolLimits;

/**
 * Переводит leftover AI-лимитов в кредиты по актуальному каталогу стоимости.
 */
class LegacyAiLimitConverter
{
    /**
     * Старая единица → услуга каталога и параметры базовой единицы.
     *
     * @var array<string, array{code: CreditServiceCode, params: array<string, mixed>}>
     */
    private const KEY_QUOTES = [
        'feedbacks_gpt_query' => [
            'code' => CreditServiceCode::FeedbackAnswer,
            'params' => [],
        ],
        'ai_text_query' => [
            'code' => CreditServiceCode::GenerateText,
            'params' => [],
        ],
        'ai_image_query' => [
            'code' => CreditServiceCode::GenerateImage,
            'params' => ['resolution' => 'default'],
        ],
        'ai_video_query' => [
            'code' => CreditServiceCode::GenerateVideo,
            'params' => ['resolution' => '480p', 'duration' => 1],
        ],
    ];

    /** @var array<string, int>|null */
    private ?array $unitPrices = null;

    public function __construct(
        private readonly CreditPriceCalculator $calculator,
    ) {}

    public function isReady(): bool
    {
        if (! $this->calculator->isReady()) {
            return false;
        }

        try {
            $this->unitPrices();
        } catch (CreditPriceNotFoundException) {
            return false;
        }

        return true;
    }

    /**
     * Кредиты за одну старую единицу каждого AI-ключа.
     *
     * @return array<string, int>
     */
    public function unitPrices(): array
    {
        if ($this->unitPrices !== null) {
            return $this->unitPrices;
        }

        $prices = [];
        foreach (self::KEY_QUOTES as $key => $spec) {
            $prices[$key] = $this->calculator->quote($spec['code']->value, $spec['params'])->amount;
        }

        return $this->unitPrices = $prices;
    }

    /**
     * @param  array<string, mixed>|null  $limits
     */
    public function convert(?array $limits): LegacyAiLimitConversion
    {
        $source = is_array($limits) ? $limits : [];
        $unitPrices = $this->unitPrices();
        $units = [];
        $breakdown = [];
        $skippedUnlimited = [];
        $total = 0;

        foreach (array_keys(self::KEY_QUOTES) as $key) {
            $raw = max(0, (int) ($source[$key] ?? 0));
            if ($raw >= ToolLimits::UNLIMITED_VALUE) {
                $skippedUnlimited[] = $key;
                $units[$key] = 0;
                $breakdown[$key] = 0;

                continue;
            }

            $price = $unitPrices[$key] ?? 0;
            $credits = $raw * $price;
            $units[$key] = $raw;
            $breakdown[$key] = $credits;
            $total += $credits;
        }

        return new LegacyAiLimitConversion(
            source: $source,
            total: $total,
            units: $units,
            unitPrices: $unitPrices,
            breakdown: $breakdown,
            skippedUnlimited: $skippedUnlimited,
        );
    }
}
