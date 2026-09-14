<?php

namespace App\Models\Subscribers\Wb\StockHistory;

use App\Models\Subscribers\Wb\WbCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WbStockHistoryDay extends Model
{
    protected $table = 'wb_stock_history_days';

    protected $fillable = [
        'cabinet_id',
        'stock_date',
    ];

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(WbCabinet::class, 'cabinet_id');
    }
}
