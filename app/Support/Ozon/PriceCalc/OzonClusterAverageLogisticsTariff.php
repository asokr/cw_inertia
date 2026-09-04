<?php

namespace App\Support\Ozon\PriceCalc;

/**
 * Средний тариф прямой логистики Ozon (руб) от объёма в литрах.
 * Общий для FBO и FBS. Пороги — «до N л включительно».
 */
final class OzonClusterAverageLogisticsTariff
{
    /**
     * @var array<int, float>
     */
    private const TARIFFS = [
        1 => 88.54,
        2 => 110.10,
        4 => 128.85,
        6 => 170.02,
        8 => 188.61,
        10 => 199.89,
        13 => 210.10,
        14 => 226.90,
        15 => 245.97,
        17 => 267.34,
        20 => 298.49,
        25 => 348.20,
        30 => 406.70,
        35 => 466.82,
        40 => 527.92,
        45 => 588.96,
        50 => 653.76,
        60 => 705.99,
        70 => 822.61,
        80 => 930.30,
        90 => 1070.52,
        100 => 1177.49,
        125 => 1371.07,
        150 => 1636.36,
        175 => 1904.23,
        200 => 2195.72,
        400 => 3231.82,
        600 => 5049.96,
        800 => 6588.77,
    ];

    private const OVER_MAX = 7856.33;

    public static function forVolume(?float $volumeLiters): ?float
    {
        if ($volumeLiters === null || $volumeLiters <= 0) {
            return null;
        }

        foreach (self::TARIFFS as $maxVolume => $value) {
            if ($volumeLiters <= $maxVolume) {
                return $value;
            }
        }

        return self::OVER_MAX;
    }
}
