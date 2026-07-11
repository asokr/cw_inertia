<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiImageGeneration extends Model
{
    protected $table = 'ai_image_generations';

    protected $fillable = [
        'subscriber_id',
        'user_id',
        'title',
    ];

    public function tasks(): HasMany
    {
        return $this->hasMany(AiImageGenerationTask::class, 'image_generation_id');
    }
}