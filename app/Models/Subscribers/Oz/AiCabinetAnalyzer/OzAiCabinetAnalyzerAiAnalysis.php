<?php

namespace App\Models\Subscribers\Oz\AiCabinetAnalyzer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OzAiCabinetAnalyzerAiAnalysis extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $table = 'oz_ai_cabinet_analyzer_ai_analyses';

    protected $fillable = [
        'report_id',
        'template_id',
        'status',
        'model',
        'provider',
        'analysis_json',
        'analysis_text',
        'analysis_markdown',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'credits_charged',
        'billing_snapshot',
        'started_at',
        'finished_at',
        'error_message',
        'credit_idempotency_key',
    ];

    protected $casts = [
        'analysis_json' => 'array',
        'billing_snapshot' => 'array',
        'credits_charged' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(OzAiCabinetAnalyzerReport::class, 'report_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(OzAiCabinetAnalyzerTemplate::class, 'template_id');
    }
}
