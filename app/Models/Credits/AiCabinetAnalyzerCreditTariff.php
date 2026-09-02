<?php

namespace App\Models\Credits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AiCabinetAnalyzerCreditTariff extends Model
{
    public const PROVIDER_GEMINI = 'gemini';

    public const PROVIDER_GPT = 'gpt';

    /** @var list<string> */
    public const PROVIDERS = [
        self::PROVIDER_GEMINI,
        self::PROVIDER_GPT,
    ];

    protected $table = 'ai_cabinet_analyzer_credit_tariffs';

    protected $fillable = [
        'provider',
        'model',
        'input_credits_per_1k',
        'output_credits_per_1k',
        'coefficient',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'input_credits_per_1k' => 'decimal:6',
        'output_credits_per_1k' => 'decimal:6',
        'coefficient' => 'decimal:4',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function providerLabel(): string
    {
        return match ($this->provider) {
            self::PROVIDER_GEMINI => 'Gemini',
            self::PROVIDER_GPT => 'GPT',
            default => (string) $this->provider,
        };
    }
}
