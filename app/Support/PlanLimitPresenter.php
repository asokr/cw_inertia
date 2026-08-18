<?php

namespace App\Support;

/**
 * Форматирует лимиты тарифа для витрины и кабинета: кабинеты WB/Ozon и репрайсер.
 */
class PlanLimitPresenter
{
    /** Старые ключи кабинетов WB сворачиваются в wb_cabinets. */
    private const LEGACY_WB_CABINET_KEYS = [
        'feedbacks_clients',
        'price_calc_clients',
        'adverts_clients',
    ];

    /**
     * Удалённые ключи кабинетов Ozon — не показываем (только oz_cabinets).
     *
     * @var list<string>
     */
    private const DROPPED_OZ_CABINET_KEYS = [
        'oz_price_calc_clients',
        'oz_feedbacks_clients',
    ];

    /**
     * @var array<string, string>
     */
    private const STRUCTURAL_LABELS = [
        'credits' => 'Кредиты',
        'wb_cabinets' => 'Единый кабинет Wildberries',
        'oz_cabinets' => 'Единый кабинет Ozon',
        'repricer_nmid' => 'Номенклатуры в репрайсере',
    ];

    /** Предпочтительный порядок известных ключей. */
    private const KEY_ORDER = [
        'credits',
        'wb_cabinets',
        'oz_cabinets',
        'repricer_nmid',
    ];

    /**
     * @param  array<string, mixed>|null  $limitsPlan
     * @return array<int, array{key: string, label: string, value: int|string, hint: ?string}>
     */
    public static function displayEntries(?array $limitsPlan): array
    {
        $merged = self::normalizePlanLimits($limitsPlan ?? []);

        $entries = [];
        foreach ($merged as $key => $value) {
            $key = (string) $key;
            $entries[] = [
                'key' => $key,
                'label' => self::label($key),
                'value' => is_numeric($value) ? (0 + $value) : $value,
                'hint' => self::hint($key),
            ];
        }

        usort($entries, function (array $a, array $b) {
            $orderA = array_search($a['key'], self::KEY_ORDER, true);
            $orderB = array_search($b['key'], self::KEY_ORDER, true);
            $orderA = $orderA === false ? 1000 : $orderA;
            $orderB = $orderB === false ? 1000 : $orderB;
            if ($orderA === $orderB) {
                return strcmp($a['label'], $b['label']);
            }

            return $orderA <=> $orderB;
        });

        return $entries;
    }

    /**
     * @param  array<int, array{key: string, label: string, value: int|string, hint: ?string}>  $entries
     * @return array<int, array{key: string, label: string, value: int|string, hint: ?string}>
     */
    public static function prependCredits(array $entries, int $credits): array
    {
        if ($credits <= 0) {
            return $entries;
        }

        array_unshift($entries, [
            'key' => 'credits',
            'label' => 'Кредиты',
            'value' => $credits,
            'hint' => 'На период тарифа',
        ]);

        return $entries;
    }

    /**
     * Сворачивает старые счётчики кабинетов WB в один wb_cabinets.
     *
     * @param  array<string, mixed>  $limits
     * @return array<string, mixed>
     */
    public static function normalizePlanLimits(array $limits): array
    {
        $out = [];
        $legacyValues = [];

        foreach ($limits as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $key = (string) $key;

            if (in_array($key, self::LEGACY_WB_CABINET_KEYS, true)) {
                $legacyValues[] = (int) $value;

                continue;
            }

            if (in_array($key, self::DROPPED_OZ_CABINET_KEYS, true)) {
                continue;
            }

            $out[$key] = $value;
        }

        if (! array_key_exists('wb_cabinets', $out) && $legacyValues !== []) {
            $out['wb_cabinets'] = max($legacyValues);
        }

        foreach (self::LEGACY_WB_CABINET_KEYS as $legacyKey) {
            unset($out[$legacyKey]);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $limits
     * @return array<string, int|float|string>
     */
    public static function normalizeRemainingMap(array $limits): array
    {
        $normalized = self::normalizePlanLimits($limits);
        $out = [];
        foreach ($normalized as $key => $value) {
            $out[(string) $key] = is_numeric($value) ? (0 + $value) : $value;
        }

        return $out;
    }

    public static function label(string $key): string
    {
        if ($key === 'wb_cabinets' || in_array($key, self::LEGACY_WB_CABINET_KEYS, true)) {
            return self::STRUCTURAL_LABELS['wb_cabinets'];
        }

        if ($key === 'oz_cabinets') {
            return self::STRUCTURAL_LABELS['oz_cabinets'];
        }

        return self::STRUCTURAL_LABELS[$key] ?? SubscriberLimitLabels::label($key);
    }

    public static function hint(string $key): ?string
    {
        if ($key === 'wb_cabinets') {
            return 'Кабинет на все услуги для маркетплейса Wildberries';
        }

        if ($key === 'oz_cabinets') {
            return 'Кабинет на все услуги для маркетплейса Ozon';
        }

        if ($key === 'credits') {
            return 'На период тарифа';
        }

        return null;
    }

    /**
     * Карточка тарифа: кабинеты / репрайсер + кредиты.
     *
     * @param  array<string, mixed>|null  $limitsPlan
     * @return array<int, array{key: string, label: string, value: int|string, hint: ?string}>
     */
    public static function displayTariffEntries(?array $limitsPlan, int $credits = 0): array
    {
        return self::prependCredits(
            self::displayEntries($limitsPlan ?? []),
            $credits,
        );
    }

    /**
     * Строки для статичных блоков («Подпись: значение»).
     *
     * @param  array<string, mixed>|null  $limitsPlan
     * @return array{plan: array<int, string>}
     */
    public static function displayLines(?array $limitsPlan): array
    {
        $planEntries = self::displayEntries($limitsPlan);

        return [
            'plan' => array_map(
                fn (array $e) => $e['label'].': '.$e['value'],
                $planEntries,
            ),
        ];
    }
}
