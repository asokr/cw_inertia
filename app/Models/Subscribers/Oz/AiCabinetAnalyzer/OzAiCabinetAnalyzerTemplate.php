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

    /** @var list<string> */
    public const DATA_SOURCES = [
        self::DATA_SOURCE_PRODUCTS,
        self::DATA_SOURCE_ANALYTICS,
        self::DATA_SOURCE_SEARCH,
        self::DATA_SOURCE_STOCKS,
        self::DATA_SOURCE_ADVERTISING,
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
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'response_format' => 'string',
        'data_sources' => 'array',
    ];

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
