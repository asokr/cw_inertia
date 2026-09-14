<?php

namespace App\Models\Subscribers\Wb\StockHistory;

use App\Models\Subscribers\Wb\WbCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WbStockHistoryProduct extends Model
{
    protected $table = 'wb_stock_history_products';

    protected $fillable = [
        'cabinet_id',
        'nm_id',
        'chrt_id',
        'vendor_code',
        'subject',
        'name',
        'tech_size',
        'barcode',
        'is_active',
    ];

    protected $casts = [
        'nm_id' => 'integer',
        'chrt_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(WbCabinet::class, 'cabinet_id');
    }
}
