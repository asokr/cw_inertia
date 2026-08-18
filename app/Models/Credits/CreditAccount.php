<?php

namespace App\Models\Credits;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditAccount extends Model
{
    protected $fillable = [
        'user_id',
        'subscription_balance',
        'purchased_balance',
        'subscription_held',
        'purchased_held',
        'last_granted_period_key',
        'subscription_exhausted_notified_period',
    ];

    protected $casts = [
        'subscription_balance' => 'integer',
        'purchased_balance' => 'integer',
        'subscription_held' => 'integer',
        'purchased_held' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(CreditLedger::class, 'user_id', 'user_id');
    }

    public function available(): int
    {
        return max(
            0,
            $this->subscription_balance
            + $this->purchased_balance
            - $this->subscription_held
            - $this->purchased_held
        );
    }

    public function availableSubscription(): int
    {
        return max(0, $this->subscription_balance - $this->subscription_held);
    }

    public function availablePurchased(): int
    {
        return max(0, $this->purchased_balance - $this->purchased_held);
    }
}
