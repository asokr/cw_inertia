<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiVideoGeneration extends Model
{
    protected $table = 'ai_video_generations';

    protected $fillable = [
        'subscriber_id',
        'user_id',
        'title',
    ];

    public function tasks(): HasMany
    {
        return $this->hasMany(AiVideoGenerationTask::class, 'video_generation_id');
    }
}