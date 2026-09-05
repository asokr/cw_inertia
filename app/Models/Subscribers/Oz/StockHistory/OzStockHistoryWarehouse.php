<?php

namespace App\Models\Subscribers\Oz\StockHistory;

use App\Models\Subscribers\Oz\OzCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OzStockHistoryWarehouse extends Model
{
    protected $table = 'oz_stock_history_warehouses';

    protected $fillable = [
        'cabinet_id',
        'warehouse_key',
        'warehouse_id',
        'warehouse_name',
        'cluster_id',
        'cluster_name',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'cluster_id' => 'integer',
    ];

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(OzCabinet::class, 'cabinet_id');
    }
}
