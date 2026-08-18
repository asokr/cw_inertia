<?php

namespace App\Services\Credits;

/**
 * Результат конвертации старых AI-единиц в кредиты.
 */
final readonly class LegacyAiLimitConversion
{
    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, int>  $units
     * @param  array<string, int>  $unitPrices
     * @param  array<string, int>  $breakdown
     * @param  list<string>  $skippedUnlimited
     */
    public function __construct(
        public array $source,
        public int $total,
        public array $units,
        public array $unitPrices,
        public array $breakdown,
        public array $skippedUnlimited,
    ) {}
}
