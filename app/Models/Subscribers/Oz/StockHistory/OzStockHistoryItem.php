<?php

namespace App\Models\Subscribers\Oz\StockHistory;

use App\Models\Subscribers\Oz\OzCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OzStockHistoryItem extends Model
{
    protected $table = 'oz_stock_history_items';

    protected $fillable = [
        'cabinet_id',
        'sku',
        'warehouse_key',
        'stock_date',
        'qty',
    ];

    protected $casts = [
        'sku' => 'integer',
        'qty' => 'integer',
    ];

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(OzCabinet::class, 'cabinet_id');
    }
}
