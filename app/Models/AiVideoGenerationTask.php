<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiVideoGenerationTask extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FILTERED = 'filtered_by_moderation';

    protected $table = 'ai_video_generation_tasks';

    protected $fillable = [
        'video_generation_id',
        'subscriber_id',
        'user_id',
        'external_request_id',
        'task_type',
        'prompt',
        'duration',
        'resolution',
        'aspect_ratio',
        'source_images',
        'status',
        'result_video',
        'error_message',
        'model',
        'limit_consumed_at',
    ];

    protected $casts = [
        'source_images' => 'array',
        'result_video' => 'array',
        'duration' => 'integer',
        'limit_consumed_at' => 'datetime',
    ];

    public function generation(): BelongsTo
    {
        return $this->belongsTo(AiVideoGeneration::class, 'video_generation_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_DONE,
            self::STATUS_FAILED,
            self::STATUS_EXPIRED,
            self::STATUS_FILTERED,
        ], true);
    }
}