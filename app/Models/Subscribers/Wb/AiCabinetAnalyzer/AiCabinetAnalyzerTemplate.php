<?php

namespace App\Models\Subscribers\Wb\AiCabinetAnalyzer;

use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerAiAnalysis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiCabinetAnalyzerTemplate extends Model
{
    protected $table = 'wb_ai_cabinet_analyzer_templates';

    protected $fillable = [
        'name',
        'description',
        'system_prompt',
        'sort_order',
        'is_active',
        'response_format',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'response_format' => 'string',
    ];

    public function analyses(): HasMany
    {
        return $this->hasMany(AiCabinetAnalyzerAiAnalysis::class, 'template_id');
    }
}
