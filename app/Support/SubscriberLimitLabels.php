<?php

namespace App\Support;

class SubscriberLimitLabels
{
    /**
     * Подписи структурных лимитов тарифа (кабинеты и репрайсер).
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'wb_cabinets' => 'Единый кабинет Wildberries',
        'oz_cabinets' => 'Единый кабинет Ozon',
        'repricer_nmid' => 'Номенклатуры в репрайсере',
    ];

    /**
     * Старые ключи кабинетов WB → единая подпись.
     *
     * @var array<string, string>
     */
    private const LEGACY_WB_CABINET_KEYS = [
        'feedbacks_clients',
        'price_calc_clients',
        'adverts_clients',
    ];

    public static function label(string $key): string
    {
        if (in_array($key, self::LEGACY_WB_CABINET_KEYS, true)) {
            return self::LABELS['wb_cabinets'];
        }

        return self::LABELS[$key] ?? $key;
    }

    /**
     * Ключи, которые админ редактирует в тарифе.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::LABELS;
    }

    /**
     * @return array<string, string>
     */
    public static function structural(): array
    {
        return self::LABELS;
    }
}
