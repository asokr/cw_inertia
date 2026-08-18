<?php

namespace App\Models\Subscribers\Wb\AiCabinetAnalyzer;

use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerAiAnalysis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiCabinetAnalyzerTemplate extends Model
{
    public const DATA_SOURCE_ADS = 'ads';

    public const DATA_SOURCE_REVIEWS = 'reviews';

    public const DATA_SOURCE_FUNNEL = 'funnel';

    /** @var list<string> */
    public const DATA_SOURCES = [
        self::DATA_SOURCE_ADS,
        self::DATA_SOURCE_REVIEWS,
        self::DATA_SOURCE_FUNNEL,
    ];

    protected $table = 'wb_ai_cabinet_analyzer_templates';

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
        return $this->hasMany(AiCabinetAnalyzerAiAnalysis::class, 'template_id');
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
