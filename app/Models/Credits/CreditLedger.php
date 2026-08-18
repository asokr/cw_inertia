<?php

namespace App\Models\Credits;

use App\Enums\Credits\CreditLedgerDirection;
use App\Enums\Credits\CreditLedgerType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CreditLedger extends Model
{
    protected $table = 'credit_ledger';

    protected $fillable = [
        'user_id',
        'type',
        'direction',
        'amount',
        'subscription_delta',
        'purchased_delta',
        'subscription_balance_after',
        'purchased_balance_after',
        'available_after',
        'source_split',
        'idempotency_key',
        'period_key',
        'service_code',
        'operation_params',
        'description',
        'user_label',
        'admin_user_id',
        'related_type',
        'related_id',
    ];

    protected $casts = [
        'type' => CreditLedgerType::class,
        'direction' => CreditLedgerDirection::class,
        'amount' => 'integer',
        'subscription_delta' => 'integer',
        'purchased_delta' => 'integer',
        'subscription_balance_after' => 'integer',
        'purchased_balance_after' => 'integer',
        'available_after' => 'integer',
        'source_split' => 'array',
        'operation_params' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }
}
