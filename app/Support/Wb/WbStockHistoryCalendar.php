<?php

namespace App\Support\Wb;

use Carbon\Carbon;

/**
 * Даты истории WB — всегда московский календарь.
 */
class WbStockHistoryCalendar
{
    public const TIMEZONE = 'Europe/Moscow';

    public const DEFAULT_PERIOD_DAYS = 30;

    /** Максимум загрузки истории: два календарных месяца по сегодня. */
    public const MAX_LOAD_MONTHS = 2;

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

    /**
     * Самая ранняя дата загрузки: сегодня минус два календарных месяца.
     */
    public static function earliestLoadDate(): string
    {
        return self::today()->subMonths(self::MAX_LOAD_MONTHS)->toDateString();
    }

    /**
     * Сколько дней в текущем окне загрузки (включая сегодня).
     */
    public static function maxLoadDays(): int
    {
        $today = self::today();

        return (int) $today->copy()->subMonths(self::MAX_LOAD_MONTHS)->diffInDays($today, true) + 1;
    }
}
