<?php

namespace App\Models\Subscribers\Wb\AiCabinetAnalyzer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiCabinetAnalyzerReport extends Model
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    protected $table = 'wb_ai_cabinet_analyzer_reports';

    protected $fillable = [
        'cabinet_id',
        'status',
        'type',
        'result_json',
    ];

    protected $casts = [
        'result_json' => 'array',
    ];

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(AiCabinetAnalyzerCabinet::class, 'cabinet_id');
    }

    public function aiAnalyses(): HasMany
    {
        return $this->hasMany(AiCabinetAnalyzerAiAnalysis::class, 'report_id');
    }
}
