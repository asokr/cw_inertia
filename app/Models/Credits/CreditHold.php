<?php

namespace App\Models\Credits;

use App\Enums\Credits\CreditHoldStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditHold extends Model
{
    protected $fillable = [
        'user_id',
        'amount',
        'subscription_reserved',
        'purchased_reserved',
        'status',
        'idempotency_key',
        'service_code',
        'operation_params',
        'expires_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'subscription_reserved' => 'integer',
        'purchased_reserved' => 'integer',
        'status' => CreditHoldStatus::class,
        'operation_params' => 'array',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === CreditHoldStatus::Held;
    }
}
