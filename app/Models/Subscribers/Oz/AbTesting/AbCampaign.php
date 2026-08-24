<?php

namespace App\Models\Subscribers\Oz\AbTesting;

use App\Models\Subscribers\Oz\OzCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbCampaign extends Model
{
    protected $table = 'oz_ab_campaigns';

    protected $fillable = [
        'cabinet_id',
        'oz_campaign_id',
        'name',
        'state',
        'payment_type',
        'created_by_experiment_id',
    ];

    protected $casts = [
        'oz_campaign_id' => 'integer',
        'created_by_experiment_id' => 'integer',
    ];

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(OzCabinet::class, 'cabinet_id');
    }

    public function createdByExperiment(): BelongsTo
    {
        return $this->belongsTo(AbExperiment::class, 'created_by_experiment_id');
    }
}
