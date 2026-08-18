<?php

namespace App\Services\Credits;

readonly class CreditSpendRequest
{
    /**
     * @param  array<string, mixed>  $operationParams
     */
    public function __construct(
        public int $amount,
        public string $serviceCode,
        public string $idempotencyKey,
        public array $operationParams = [],
        public ?string $userLabel = null,
        public ?string $description = null,
    ) {
    }
}
