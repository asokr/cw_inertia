<?php

namespace App\Models\Subscribers\Wb\StockHistory;

use App\Models\Subscribers\Wb\WbCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WbStockHistoryItem extends Model
{
    protected $table = 'wb_stock_history_items';

    protected $fillable = [
        'cabinet_id',
        'nm_id',
        'chrt_id',
        'warehouse_key',
        'stock_date',
        'qty',
    ];

    protected $casts = [
        'nm_id' => 'integer',
        'chrt_id' => 'integer',
        'qty' => 'integer',
    ];

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(WbCabinet::class, 'cabinet_id');
    }
}
