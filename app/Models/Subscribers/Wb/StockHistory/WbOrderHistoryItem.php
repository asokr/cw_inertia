<?php

namespace App\Models\Subscribers\Wb\StockHistory;

use App\Models\Subscribers\Wb\WbCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WbOrderHistoryItem extends Model
{
    protected $table = 'wb_order_history_items';

    protected $fillable = [
        'cabinet_id',
        'nm_id',
        'tech_size',
        'barcode',
        'vendor_code',
        'subject',
        'order_date',
        'orders_count',
    ];

    protected $casts = [
        'nm_id' => 'integer',
        'orders_count' => 'integer',
    ];

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(WbCabinet::class, 'cabinet_id');
    }
}
