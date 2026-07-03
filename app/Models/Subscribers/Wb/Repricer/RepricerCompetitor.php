<?php

namespace App\Models\Subscribers\Wb\Repricer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;

class RepricerCompetitor extends Model
{
    protected $table = 'wb_repricer_competitors';

    protected $fillable = [
        'nm_id',
        'cabinet_id',
        'product_data',
        'competitors',
        'difference',
        'difference_type',
        'competitors_price_type',
        'active',
        'status',
        'base_value',
        'base_discount',
        'repeats_counter',
    ];

    protected $casts = [
        'product_data' => 'array',
        'competitors' => 'array',
        'difference' => 'float',
        'active' => 'boolean',
        'status' => 'boolean',
        'base_value' => 'float',
        'base_discount' => 'float',
        'repeats_counter' => 'integer',
    ];

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(RepricerCabinets::class, 'cabinet_id');
    }
}
