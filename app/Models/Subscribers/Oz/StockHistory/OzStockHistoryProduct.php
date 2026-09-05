<?php

namespace App\Models\Subscribers\Oz\StockHistory;

use App\Models\Subscribers\Oz\OzCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OzStockHistoryProduct extends Model
{
    protected $table = 'oz_stock_history_products';

    protected $fillable = [
        'cabinet_id',
        'sku',
        'product_id',
        'offer_id',
        'name',
        'image_url',
        'is_active',
    ];

    protected $casts = [
        'sku' => 'integer',
        'product_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(OzCabinet::class, 'cabinet_id');
    }
}
