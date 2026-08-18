<?php

namespace App\Models\Credits;

use App\Enums\Credits\CreditBillingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditService extends Model
{
    protected $fillable = [
        'code',
        'name',
        'billing_mode',
        'amount',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'billing_mode' => CreditBillingMode::class,
        'amount' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function tiers(): HasMany
    {
        return $this->hasMany(CreditServicePriceTier::class)->orderBy('sort_order')->orderBy('id');
    }
}
