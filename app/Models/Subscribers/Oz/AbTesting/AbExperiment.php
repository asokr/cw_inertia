<?php

namespace App\Models\Subscribers\Oz\AbTesting;

use App\Enums\OzAbTestStatus;
use App\Models\Subscribers\Oz\OzCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AbExperiment extends Model
{
    protected $table = 'oz_ab_experiments';

    protected $fillable = [
        'ab_product_id',
        'cabinet_id',
        'name',
        'status',
        'progress',
        'oz_campaign_id',
        'oz_campaign_name',
        'campaign_bound_at',
        'sku',
        'gallery_snapshot',
        'impressions_per_photo',
        'impressions_per_round',
        'round_minutes',
        'cpm',
        'started_at',
        'finished_at',
        'error_message',
        'winner_photo_id',
        'last_processed_at',
        'consecutive_failures',
    ];

    protected $casts = [
        'progress' => 'integer',
        'oz_campaign_id' => 'integer',
        'sku' => 'integer',
        'gallery_snapshot' => 'array',
        'campaign_bound_at' => 'datetime',
        'impressions_per_photo' => 'integer',
        'impressions_per_round' => 'integer',
        'round_minutes' => 'integer',
        'cpm' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'last_processed_at' => 'datetime',
        'winner_photo_id' => 'integer',
        'consecutive_failures' => 'integer',
        'status' => OzAbTestStatus::class,
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(AbProduct::class, 'ab_product_id');
    }

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(OzCabinet::class, 'cabinet_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(AbExperimentPhoto::class, 'ab_experiment_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(AbExperimentCycle::class, 'ab_experiment_id')
            ->orderBy('sequence')
            ->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AbExperimentEvent::class, 'ab_experiment_id')
            ->orderByDesc('id');
    }

    public function openCycle(): HasOne
    {
        return $this->hasOne(AbExperimentCycle::class, 'ab_experiment_id')
            ->whereNull('ended_at')
            ->latestOfMany('sequence');
    }

    public function winnerPhoto(): BelongsTo
    {
        return $this->belongsTo(AbExperimentPhoto::class, 'winner_photo_id');
    }

    public function resolveOpenCycle(): ?AbExperimentCycle
    {
        if ($this->relationLoaded('openCycle')) {
            return $this->getRelation('openCycle');
        }

        if ($this->relationLoaded('cycles')) {
            return $this->cycles->first(fn (AbExperimentCycle $c) => $c->ended_at === null);
        }

        return AbExperimentCycle::query()
            ->where('ab_experiment_id', $this->id)
            ->whereNull('ended_at')
            ->orderByDesc('sequence')
            ->orderByDesc('id')
            ->first();
    }
}
