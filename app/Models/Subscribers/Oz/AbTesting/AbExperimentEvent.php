<?php

namespace App\Models\Subscribers\Oz\AbTesting;

use App\Models\Subscribers\Oz\OzCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbExperimentEvent extends Model
{
    public $timestamps = false;

    protected $table = 'oz_ab_experiment_events';

    protected $fillable = [
        'ab_experiment_id',
        'cabinet_id',
        'type',
        'message',
        'meta',
        'created_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(AbExperiment::class, 'ab_experiment_id');
    }

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(OzCabinet::class, 'cabinet_id');
    }
}
