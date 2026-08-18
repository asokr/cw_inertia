<?php

namespace App\Models\Credits;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditLegacyMigration extends Model
{
    protected $fillable = [
        'user_id',
        'source_extra_limits',
        'source_month_limits',
        'coefficients',
        'purchased_credits',
        'subscription_credits',
        'extra_migrated_at',
        'month_migrated_at',
        'ran_at',
    ];

    protected $casts = [
        'source_extra_limits' => 'array',
        'source_month_limits' => 'array',
        'coefficients' => 'array',
        'purchased_credits' => 'integer',
        'subscription_credits' => 'integer',
        'extra_migrated_at' => 'datetime',
        'month_migrated_at' => 'datetime',
        'ran_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
