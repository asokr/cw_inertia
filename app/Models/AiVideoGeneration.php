<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AiVideoGeneration extends Model
{
    protected $table = 'ai_video_generations';

    protected $fillable = [
        'uuid',
        'subscriber_id',
        'user_id',
        'title',
    ];

    protected static function booted(): void
    {
        static::creating(function (AiVideoGeneration $generation): void {
            if (! filled($generation->uuid)) {
                $generation->uuid = (string) Str::uuid();
            }
        });
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(AiVideoGenerationTask::class, 'video_generation_id');
    }
}