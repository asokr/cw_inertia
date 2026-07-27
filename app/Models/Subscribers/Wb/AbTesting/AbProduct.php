<?php

namespace App\Models\Subscribers\Wb\AbTesting;

use App\Models\Subscribers\Wb\WbCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    ];

    protected $casts = [
        'nm_id' => 'integer',
        'price' => 'float',
        'rating' => 'float',
    ];

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(WbCabinet::class, 'cabinet_id');
    }
}
