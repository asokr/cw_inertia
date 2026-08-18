<?php

namespace App\Enums\Credits;

enum CreditBillingMode: string
{
    case Fixed = 'fixed';
    case ByResolution = 'by_resolution';
    case PerSecondByResolution = 'per_second_by_resolution';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Фиксированная',
            self::ByResolution => 'По разрешению',
            self::PerSecondByResolution => 'За секунду по разрешению',
        };
    }
}
