<?php

namespace App\Support;

use App\Models\ExtraLimits;
use Illuminate\Support\Facades\Schema;

class SubscriberLimitLabels
{
    /**
     * Structural / non-catalog labels for UI and admin key picker.
     * Purchasable monthly titles prefer extra_limits.name (by slug).
     * Legacy per-tool WB cabinet keys are not listed here — use wb_cabinets.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'wb_cabinets' => 'Единый кабинет Wildberries',
        'oz_feedbacks_clients' => 'Кабинеты отзывов Ozon',
        'oz_price_calc_clients' => 'Кабинеты ценообразования Ozon',
        'repricer_nmid' => 'Номенклатуры в репрайсере',
        // Soft fallbacks if slug not yet in extra_limits catalog
        'feedbacks_gpt_query' => 'Запросы к ИИ для отзывов',
        'ai_text_query' => 'Текстовые запросы к ИИ',
        'ai_image_query' => 'Генерация изображений ИИ',
        'ai_video_query' => 'Генерация видео ИИ',
    ];

    /**
     * Legacy per-service WB cabinet keys → unified product label.
     *
     * @var array<string, string>
     */
    private const LEGACY_WB_CABINET_KEYS = [
        'feedbacks_clients',
        'price_calc_clients',
        'adverts_clients',
    ];

    /** @var array<string, string>|null */
    private static ?array $catalogNames = null;

    public static function label(string $key): string
    {
        if (in_array($key, self::LEGACY_WB_CABINET_KEYS, true)) {
            return self::LABELS['wb_cabinets'];
        }

        $fromCatalog = self::catalogNames()[$key] ?? null;
        if ($fromCatalog !== null) {
            return $fromCatalog;
        }

        return self::LABELS[$key] ?? $key;
    }

    /**
     * Keys offered in admin limit editors (structural + catalog slugs).
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        // Catalog names override soft fallbacks; structural keys stay.
        return array_merge(self::LABELS, self::catalogNames());
    }

    /**
     * Usable display names from extra_limits (slug → name).
     * Skips empty names and name===slug so soft fallbacks still apply on broken prod data.
     *
     * @return array<string, string>
     */
    private static function catalogNames(): array
    {
        if (self::$catalogNames !== null) {
            return self::$catalogNames;
        }

        try {
            if (! Schema::hasTable('extra_limits') || ! Schema::hasColumn('extra_limits', 'slug')) {
                return self::$catalogNames = [];
            }

            $map = [];
            foreach (ExtraLimits::query()->whereNotNull('slug')->get(['slug', 'name']) as $row) {
                $slug = (string) $row->slug;
                $name = is_string($row->name) ? trim($row->name) : '';
                if ($slug === '' || $name === '' || $name === $slug) {
                    continue;
                }
                $map[$slug] = $name;
            }
            self::$catalogNames = $map;
        } catch (\Throwable) {
            self::$catalogNames = [];
        }

        return self::$catalogNames;
    }
}
