<?php

namespace App\Support\Oz;

use Carbon\Carbon;

/**
 * Даты истории остатков — всегда московский календарь.
 */
class OzStockHistoryCalendar
{
    public const TIMEZONE = 'Europe/Moscow';

    public static function now(): Carbon
    {
        return now(self::TIMEZONE);
    }

    public static function today(): Carbon
    {
        return self::now()->startOfDay();
    }

    public static function yesterdayDate(): string
    {
        return self::now()->subDay()->toDateString();
    }

    public static function todayDate(): string
    {
        return self::today()->toDateString();
    }
}
