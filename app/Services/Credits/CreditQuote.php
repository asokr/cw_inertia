<?php

namespace App\Services\Credits;

readonly class CreditQuote
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public int $amount,
        public string $serviceCode,
        public string $billingMode,
        public array $params = [],
        public ?int $unitAmount = null,
    ) {
    }
}
