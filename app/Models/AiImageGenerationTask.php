<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiImageGenerationTask extends Model
{
    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $table = 'ai_image_generation_tasks';

    protected $fillable = [
        'image_generation_id',
        'subscriber_id',
        'user_id',
        'task_type',
        'prompt',
        'image_variants',
        'resolution',
        'aspect_ratio',
        'source_images',
        'status',
        'result_images',
        'error_message',
        'model',
        'limit_consumed_at',
    ];

    protected $casts = [
        'source_images' => 'array',
        'result_images' => 'array',
        'image_variants' => 'integer',
        'limit_consumed_at' => 'datetime',
    ];

    public function generation(): BelongsTo
    {
        return $this->belongsTo(AiImageGeneration::class, 'image_generation_id');
    }
}