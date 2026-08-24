<?php

namespace App\Models\Subscribers\Oz\AbTesting;

use App\Models\Subscribers\Oz\OzCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbExperimentPhoto extends Model
{
    protected $table = 'oz_ab_experiment_photos';

    protected $fillable = [
        'ab_experiment_id',
        'cabinet_id',
        'sort_order',
        'disk',
        'path',
        'original_name',
        'mime',
        'size',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'size' => 'integer',
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
