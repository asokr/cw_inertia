<?php

namespace App\Models\Credits;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AiCabinetAnalyzerCreditCharge extends Model
{
    protected $table = 'ai_cabinet_analyzer_credit_charges';

    protected $fillable = [
        'marketplace',
        'analysis_type',
        'analysis_id',
        'user_id',
        'provider',
        'model',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'credits_reserved',
        'credits_charged',
        'tariff_snapshot',
        'credit_idempotency_key',
    ];

    protected $casts = [
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'total_tokens' => 'integer',
        'credits_reserved' => 'integer',
        'credits_charged' => 'integer',
        'tariff_snapshot' => 'array',
    ];

    public function analysis(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function marketplaceLabel(): string
    {
        return match ($this->marketplace) {
            'wb' => 'Wildberries',
            'ozon' => 'Ozon',
            default => (string) $this->marketplace,
        };
    }
}
