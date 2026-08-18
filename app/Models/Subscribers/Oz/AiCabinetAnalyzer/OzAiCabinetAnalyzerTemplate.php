<?php

namespace App\Models\Subscribers\Oz\AiCabinetAnalyzer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OzAiCabinetAnalyzerTemplate extends Model
{
    public const DATA_SOURCE_PRODUCTS = 'products';

    public const DATA_SOURCE_ANALYTICS = 'analytics';

    public const DATA_SOURCE_SEARCH = 'search';

    public const DATA_SOURCE_STOCKS = 'stocks';

    public const DATA_SOURCE_ADVERTISING = 'advertising';

    public const DATA_SOURCE_CONTENT = 'content';

    public const DATA_SOURCE_SELLER_RATING = 'seller_rating';

    public const DATA_SOURCE_PROMOS = 'promos';

    /** @var list<string> */
    public const DATA_SOURCES = [
        self::DATA_SOURCE_PRODUCTS,
        self::DATA_SOURCE_ANALYTICS,
        self::DATA_SOURCE_SEARCH,
        self::DATA_SOURCE_STOCKS,
        self::DATA_SOURCE_ADVERTISING,
        self::DATA_SOURCE_CONTENT,
        self::DATA_SOURCE_SELLER_RATING,
        self::DATA_SOURCE_PROMOS,
    ];

    protected $table = 'oz_ai_cabinet_analyzer_templates';

    protected $fillable = [
        'name',
        'description',
        'system_prompt',
        'sort_order',
        'is_active',
        'response_format',
        'data_sources',
        'credits_cost',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'response_format' => 'string',
        'data_sources' => 'array',
        'credits_cost' => 'integer',
    ];

    /**
     * Стоимость генерации отчёта по этому шаблону.
     * Берётся из колонки БД; 0/пустое не допускаем как цену.
     */
    public function creditsCost(): int
    {
        $value = (int) ($this->credits_cost ?? 0);

        return $value > 0 ? $value : 1;
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(OzAiCabinetAnalyzerAiAnalysis::class, 'template_id');
    }

    /**
     * Нормализованный список источников данных для ИИ.
     * Пустое/null значение трактуется как «все источники» (обратная совместимость).
     *
     * @return list<string>
     */
    public function resolvedDataSources(): array
    {
        $allowed = array_flip(self::DATA_SOURCES);
        $raw = $this->data_sources;

        if (! is_array($raw) || $raw === []) {
            return self::DATA_SOURCES;
        }

        $resolved = [];
        foreach ($raw as $source) {
            $key = (string) $source;
            if (isset($allowed[$key]) && ! in_array($key, $resolved, true)) {
                $resolved[] = $key;
            }
        }

        return $resolved !== [] ? $resolved : self::DATA_SOURCES;
    }

    public function includesDataSource(string $source): bool
    {
        return in_array($source, $this->resolvedDataSources(), true);
    }
}
