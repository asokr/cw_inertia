<?php

namespace App\Support;

use App\Models\ExtraLimits;
use Illuminate\Support\Facades\Schema;

/**
 * Formats plan limits for marketing/UI: unified WB cabinets + DB names by slug.
 */
class PlanLimitPresenter
{
    /** Legacy per-tool WB cabinet keys collapsed into wb_cabinets. */
    private const LEGACY_WB_CABINET_KEYS = [
        'feedbacks_clients',
        'price_calc_clients',
        'adverts_clients',
    ];

    /**
     * Static labels for structural / cabinet keys (not sold as unit extra limits).
     *
     * @var array<string, string>
     */
    private const STRUCTURAL_LABELS = [
        'wb_cabinets' => 'Единый кабинет Wildberries',
        'repricer_nmid' => 'Номенклатуры в репрайсере',
        'oz_feedbacks_clients' => 'Кабинеты отзывов Ozon',
        'oz_price_calc_clients' => 'Кабинеты ценообразования Ozon',
    ];

    /** Preferred display order for known keys. */
    private const KEY_ORDER = [
        'wb_cabinets',
        'oz_feedbacks_clients',
        'oz_price_calc_clients',
        'repricer_nmid',
        'feedbacks_gpt_query',
        'ai_text_query',
        'ai_image_query',
        'ai_video_query',
    ];

    /** @var array<string, string>|null */
    private static ?array $catalogNames = null;

    /**
     * @param  array<string, mixed>|null  $limitsPlan
     * @param  array<string, mixed>|null  $limitsMonth
     * @return array<int, array{key: string, label: string, value: int|string, hint: ?string}>
     */
    public static function displayEntries(?array $limitsPlan, ?array $limitsMonth = null): array
    {
        $merged = self::normalizePlanLimits($limitsPlan ?? []);
        foreach ($limitsMonth ?? [] as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $merged[(string) $key] = $value;
        }

        // Collapse again in case month map accidentally carried legacy keys.
        $merged = self::normalizePlanLimits($merged);

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
     * Collapse legacy WB per-service cabinet counters into a single wb_cabinets limit.
     * Safe for mixed plan/month remaining maps (only legacy cabinet keys are touched).
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

            $out[$key] = $value;
        }

        if (! array_key_exists('wb_cabinets', $out) && $legacyValues !== []) {
            $out['wb_cabinets'] = max($legacyValues);
        }

        // Drop legacy keys if unified is present (including when both existed).
        foreach (self::LEGACY_WB_CABINET_KEYS as $legacyKey) {
            unset($out[$legacyKey]);
        }

        return $out;
    }

    /**
     * Normalize a remaining/limits map (collapse legacy WB cabinets, cast numerics).
     *
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
        // Unified WB cabinet always uses product wording (not catalog override).
        if ($key === 'wb_cabinets' || in_array($key, self::LEGACY_WB_CABINET_KEYS, true)) {
            return self::STRUCTURAL_LABELS['wb_cabinets'];
        }

        $fromCatalog = self::catalogNames()[$key] ?? null;
        if (is_string($fromCatalog) && $fromCatalog !== '') {
            return $fromCatalog;
        }

        return self::STRUCTURAL_LABELS[$key] ?? SubscriberLimitLabels::label($key);
    }

    public static function hint(string $key): ?string
    {
        if ($key === 'wb_cabinets') {
            return 'Кабинет на все услуги для маркетплейса Wildberries';
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public static function catalogNames(): array
    {
        if (self::$catalogNames !== null) {
            return self::$catalogNames;
        }

        try {
            if (! Schema::hasTable('extra_limits') || ! Schema::hasColumn('extra_limits', 'slug')) {
                return self::$catalogNames = [];
            }

            self::$catalogNames = ExtraLimits::query()
                ->whereNotNull('slug')
                ->pluck('name', 'slug')
                ->all();
        } catch (\Throwable) {
            self::$catalogNames = [];
        }

        return self::$catalogNames;
    }

    /**
     * Human-readable lines for static-like UIs ("Label: value").
     *
     * @param  array<string, mixed>|null  $limitsPlan
     * @param  array<string, mixed>|null  $limitsMonth
     * @return array{plan: array<int, string>, month: array<int, string>}
     */
    public static function displayLines(?array $limitsPlan, ?array $limitsMonth = null): array
    {
        $planEntries = self::displayEntries($limitsPlan, null);
        $monthEntries = self::displayEntries(null, $limitsMonth);

        return [
            'plan' => array_map(
                fn (array $e) => $e['label'].': '.$e['value'],
                $planEntries,
            ),
            'month' => array_map(
                fn (array $e) => $e['label'].': '.$e['value'],
                $monthEntries,
            ),
        ];
    }
}
