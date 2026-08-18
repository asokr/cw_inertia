<?php

namespace App\Models\Credits;

use App\Models\Subscribers\SubscribersPlans;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditLegacyPlanMigration extends Model
{
    protected $fillable = [
        'plan_id',
        'source_month_limits',
        'quotes',
        'credits_written',
        'previous_credits_per_period',
        'new_credits_per_period',
        'ran_at',
    ];

    protected $casts = [
        'source_month_limits' => 'array',
        'quotes' => 'array',
        'credits_written' => 'integer',
        'previous_credits_per_period' => 'integer',
        'new_credits_per_period' => 'integer',
        'ran_at' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscribersPlans::class, 'plan_id');
    }
}
