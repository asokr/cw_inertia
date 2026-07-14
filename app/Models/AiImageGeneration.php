<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AiImageGeneration extends Model
{
    protected $table = 'ai_image_generations';

    protected $fillable = [
        'uuid',
        'subscriber_id',
        'user_id',
        'title',
    ];

    protected static function booted(): void
    {
        static::creating(function (AiImageGeneration $generation): void {
            if (! filled($generation->uuid)) {
                $generation->uuid = (string) Str::uuid();
            }
        });
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(AiImageGenerationTask::class, 'image_generation_id');
    }
}