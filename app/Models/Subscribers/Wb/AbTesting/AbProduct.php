<?php

namespace App\Models\Subscribers\Wb\AbTesting;

use App\Models\Subscribers\Wb\WbCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AbProduct extends Model
{
    protected $table = 'wb_ab_products';

    protected $fillable = [
        'cabinet_id',
        'nm_id',
        'vendor_code',
        'title',
        'brand',
        'subject_name',
        'photo_url',
        'price',
        'rating',
        'rating_updated_at',
    ];

    protected $casts = [
        'nm_id' => 'integer',
        'price' => 'float',
        'rating' => 'float',
        'rating_updated_at' => 'datetime',
    ];

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(WbCabinet::class, 'cabinet_id');
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
