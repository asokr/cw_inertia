<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class AiRequestLog extends Model
{
    use Prunable;

    public $timestamps = false;

    protected $table = 'ai_request_logs';

    protected $fillable = [
        'user_id',
        'subscriber_id',
        'task_type',
        'marketplace',
        'provider',
        'model',
        'external_request_id',
        'request_payload',
        'provider_response_payload',
        'response_text',
        'response_images',
        'response_videos',
        'response_type',
        'generation_status',
        'images_count',
        'videos_count',
        'input_tokens',
        'output_tokens',
        'prompt_tokens',
        'candidates_tokens',
        'total_tokens',
        'status_code',
        'error_message',
        'created_at',
        'limit_consumed_at',
        'updated_at',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'provider_response_payload' => 'array',
        'response_images' => 'array',
        'response_videos' => 'array',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'prompt_tokens' => 'integer',
        'candidates_tokens' => 'integer',
        'total_tokens' => 'integer',
        'created_at' => 'datetime',
        'limit_consumed_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<=', now()->subMonth());
    }
}
