<?php

namespace App\Models\Subscribers\Oz\AbTesting;

use App\Models\Subscribers\Oz\OzCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AbProduct extends Model
{
    protected $table = 'oz_ab_products';

    protected $fillable = [
        'cabinet_id',
        'oz_product_id',
        'offer_id',
        'sku',
        'model_id',
        'title',
        'brand',
        'photo_url',
        'price',
    ];

    protected $casts = [
        'oz_product_id' => 'integer',
        'sku' => 'integer',
        'model_id' => 'integer',
        'price' => 'float',
    ];

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(OzCabinet::class, 'cabinet_id');
    }

    public function experiments(): HasMany
    {
        return $this->hasMany(AbExperiment::class, 'ab_product_id');
    }

    public function latestExperiment(): HasOne
    {
        return $this->hasOne(AbExperiment::class, 'ab_product_id')->latestOfMany('created_at');
    }
}
