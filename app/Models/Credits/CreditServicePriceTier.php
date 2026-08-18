<?php

namespace App\Models\Credits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditServicePriceTier extends Model
{
    protected $fillable = [
        'credit_service_id',
        'param_key',
        'param_value',
        'amount',
        'sort_order',
    ];

    protected $casts = [
        'amount' => 'integer',
        'sort_order' => 'integer',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(CreditService::class, 'credit_service_id');
    }
}
