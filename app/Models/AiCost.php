<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiCost extends Model
{
    protected $table = 'ai_costs';

    protected $fillable = [
        'date',
        'provider',
        'model',
        'task_type',
        'requests_count',
        'input_tokens',
        'output_tokens',
        'images_count',
        'videos_seconds',
        'cost',
    ];

    protected $casts = [
        'date' => 'date',
        'requests_count' => 'integer',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'images_count' => 'integer',
        'videos_seconds' => 'integer',
        'cost' => 'decimal:6',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
