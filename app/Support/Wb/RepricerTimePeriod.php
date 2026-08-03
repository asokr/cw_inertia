<?php

namespace App\Support\Wb;

use Carbon\Carbon;
use Closure;

/**
 * Периоды TIME-стратегии репрайсера:
 * - ежедневно: "H:i"
 * - разовый:   "Y-m-d H:i:s" / "Y-m-d H:i" / "Y-m-dTH:i"
 */
class RepricerTimePeriod
{
    public static function isDailyTime(string $value): bool
    {
        return (bool) preg_match('/^\d{2}:\d{2}$/', trim($value));
    }

    public static function isAbsoluteDateTime(string $value): bool
    {
        return (bool) preg_match(
            '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/',
            trim($value)
        );
    }

    public static function isValidBoundary(mixed $value): bool
    {
        if (! is_string($value) || trim($value) === '') {
            return false;
        }

        $value = trim($value);

        return self::isDailyTime($value) || self::isAbsoluteDateTime($value);
    }

    /**
     * Нормализует boundary к каноническому виду для хранения.
     * H:i остаётся; datetime → Y-m-d H:i:s (МСК, как в UI).
     */
    public static function normalizeBoundary(string $value): string
    {
        $value = trim($value);

        if (self::isDailyTime($value)) {
            return $value;
        }

        return Carbon::parse($value, 'Europe/Moscow')->format('Y-m-d H:i:s');
    }

    /**
     * @param  list<array{start?: mixed, end?: mixed, value?: mixed}>  $terms
     * @return list<array{start: string, end: string, value: float|int|string}>
     */
    public static function normalizeTerms(array $terms): array
    {
        $normalized = [];

        foreach ($terms as $term) {
            if (! is_array($term)) {
                continue;
            }

            $start = isset($term['start']) ? trim((string) $term['start']) : '';
            $end = isset($term['end']) ? trim((string) $term['end']) : '';

            if ($start === '' || $end === '') {
                continue;
            }

            $normalized[] = [
                'start' => self::normalizeBoundary($start),
                'end' => self::normalizeBoundary($end),
                'value' => $term['value'] ?? 0,
            ];
        }

        return $normalized;
    }

    /**
     * Правило валидации для terms.*.start / terms.*.end
     */
    public static function validationRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! self::isValidBoundary($value)) {
                $fail('Неверный формат периода. Используйте время (11:25) или дату и время (2026-08-01 11:25).');
            }
        };
    }

    /**
     * Проверка пересечений. Daily (H:i) и absolute datetime сравниваются раздельно.
     *
     * @param  list<array{start: string, end: string}>  $terms
     */
    public static function validateNoOverlap(array $terms): ?string
    {
        $daily = [];
        $absolute = [];

        foreach ($terms as $index => $term) {
            $start = trim((string) ($term['start'] ?? ''));
            $end = trim((string) ($term['end'] ?? ''));

            if (self::isDailyTime($start) && self::isDailyTime($end)) {
                $daily[] = ['index' => $index, 'start' => $start, 'end' => $end];
            } elseif (self::isAbsoluteDateTime($start) && self::isAbsoluteDateTime($end)) {
                $absolute[] = ['index' => $index, 'start' => $start, 'end' => $end];
            }
        }

        $dailyError = self::validateDailyOverlap($daily);
        if ($dailyError !== null) {
            return $dailyError;
        }

        return self::validateAbsoluteOverlap($absolute);
    }

    /**
     * @param  list<array{index: int, start: string, end: string}>  $periods
     */
    private static function validateDailyOverlap(array $periods): ?string
    {
        $count = count($periods);
        $expand = function (string $start, string $end): array {
            $s = strtotime($start);
            $e = strtotime($end);
            if ($e > $s) {
                return [[$s, $e]];
            }

            return [[$s, strtotime('24:00')], [strtotime('00:00'), $e]];
        };

        for ($i = 0; $i < $count; $i++) {
            $a = $periods[$i];
            $aRanges = $expand($a['start'], $a['end']);
            for ($j = $i + 1; $j < $count; $j++) {
                $b = $periods[$j];
                $bRanges = $expand($b['start'], $b['end']);
                foreach ($aRanges as [$as, $ae]) {
                    foreach ($bRanges as [$bs, $be]) {
                        if ($as < $be && $bs < $ae) {
                            return 'Периоды #'.($a['index'] + 1).' и #'.($b['index'] + 1).' пересекаются';
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array{index: int, start: string, end: string}>  $periods
     */
    private static function validateAbsoluteOverlap(array $periods): ?string
    {
        $count = count($periods);

        for ($i = 0; $i < $count; $i++) {
            $a = $periods[$i];
            try {
                $as = Carbon::parse($a['start'], 'Europe/Moscow');
                $ae = Carbon::parse($a['end'], 'Europe/Moscow');
            } catch (\Throwable) {
                continue;
            }

            if ($ae->lessThanOrEqualTo($as)) {
                return 'Период #'.($a['index'] + 1).': окончание должно быть позже начала';
            }

            for ($j = $i + 1; $j < $count; $j++) {
                $b = $periods[$j];
                try {
                    $bs = Carbon::parse($b['start'], 'Europe/Moscow');
                    $be = Carbon::parse($b['end'], 'Europe/Moscow');
                } catch (\Throwable) {
                    continue;
                }

                if ($as->lessThan($be) && $bs->lessThan($ae)) {
                    return 'Периоды #'.($a['index'] + 1).' и #'.($b['index'] + 1).' пересекаются';
                }
            }
        }

        return null;
    }
}
