<?php

namespace App\Support;

class CreditsFormatter
{
    public static function word(int $count): string
    {
        $abs = abs($count);
        $mod100 = $abs % 100;
        $mod10 = $abs % 10;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return 'кредитов';
        }

        return match ($mod10) {
            1 => 'кредит',
            2, 3, 4 => 'кредита',
            default => 'кредитов',
        };
    }

    public static function amount(int $count): string
    {
        return $count.' '.self::word($count);
    }

    public static function remaining(int $count): string
    {
        return 'Осталось '.self::amount($count);
    }
}
