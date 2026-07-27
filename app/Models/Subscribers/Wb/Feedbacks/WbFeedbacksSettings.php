<?php

namespace App\Models\Subscribers\Wb\Feedbacks;

use App\Models\Subscribers\Wb\WbCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WbFeedbacksSettings extends Model
{
    protected $table = 'wb_feedbacks_settings';

    protected $fillable = [
        'cabinet_id',
        'brands',
        'bot_status',
        'ai_status',
        'ai_ratings',
        'review_type',
    ];

    protected $casts = [
        'bot_status' => 'boolean',
        'ai_status' => 'boolean',
        'ai_ratings' => 'array',
    ];

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(WbCabinet::class, 'cabinet_id');
    }
}
