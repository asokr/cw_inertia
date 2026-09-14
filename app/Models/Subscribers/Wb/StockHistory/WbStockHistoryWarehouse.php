<?php

namespace App\Models\Subscribers\Wb\StockHistory;

use App\Models\Subscribers\Wb\WbCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WbStockHistoryWarehouse extends Model
{
    protected $table = 'wb_stock_history_warehouses';

    protected $fillable = [
        'cabinet_id',
        'warehouse_key',
        'warehouse_id',
        'warehouse_name',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
    ];

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(WbCabinet::class, 'cabinet_id');
    }
}
