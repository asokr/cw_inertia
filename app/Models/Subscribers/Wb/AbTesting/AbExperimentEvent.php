<?php

namespace App\Models\Subscribers\Wb\AbTesting;

use App\Models\Subscribers\Wb\WbCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbExperimentEvent extends Model
{
    public $timestamps = false;

    protected $table = 'wb_ab_experiment_events';

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
        return $this->belongsTo(WbCabinet::class, 'cabinet_id');
    }
}
