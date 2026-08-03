<?php

namespace App\Models\Subscribers\Wb\AbTesting;

use App\Models\Subscribers\Wb\WbCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbCampaign extends Model
{
    protected $table = 'wb_ab_campaigns';

    protected $fillable = [
        'cabinet_id',
        'wb_advert_id',
        'name',
        'bid_type',
        'payment_type',
        'created_by_experiment_id',
    ];

    protected $casts = [
        'wb_advert_id' => 'integer',
        'created_by_experiment_id' => 'integer',
    ];

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(WbCabinet::class, 'cabinet_id');
    }

    public function createdByExperiment(): BelongsTo
    {
        return $this->belongsTo(AbExperiment::class, 'created_by_experiment_id');
    }
}
