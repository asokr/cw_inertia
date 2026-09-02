<?php

namespace App\Services\Credits;

readonly class AiCabinetAnalyzerCreditQuote
{
    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function __construct(
        public int $amount,
        public array $snapshot,
    ) {
    }
}
