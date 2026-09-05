<?php

namespace App\Models\Subscribers\Oz\StockHistory;

use App\Enums\OzStockHistorySnapshotStatus;
use App\Models\Subscribers\Oz\OzCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OzStockHistorySnapshot extends Model
{
    protected $table = 'oz_stock_history_snapshots';

    protected $fillable = [
        'cabinet_id',
        'stock_date',
        'status',
        'collected_at',
        'products_count',
        'rows_count',
        'error_message',
    ];

    protected $casts = [
        'status' => OzStockHistorySnapshotStatus::class,
        'collected_at' => 'datetime',
        'products_count' => 'integer',
        'rows_count' => 'integer',
    ];

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(OzCabinet::class, 'cabinet_id');
    }
}
