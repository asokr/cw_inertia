<?php

namespace App\Models\Subscribers\Wb\StockHistory;

use App\Enums\WbHistoryLoadStatus;
use App\Models\Subscribers\Wb\WbCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WbStockHistorySetting extends Model
{
    public const DEFAULT_RETENTION_DAYS = 90;

    public const MIN_RETENTION_DAYS = 7;

    public const MAX_RETENTION_DAYS = 180;

    protected $table = 'wb_stock_history_settings';

    protected $fillable = [
        'cabinet_id',
        'retention_days',
        'stocks_tracking_enabled',
        'orders_tracking_enabled',
        'stocks_status',
        'orders_status',
        'stocks_last_error',
        'orders_last_error',
        'products_synced_at',
        'products_count',
    ];

    protected $casts = [
        'retention_days' => 'integer',
        'stocks_tracking_enabled' => 'boolean',
        'orders_tracking_enabled' => 'boolean',
        'stocks_status' => WbHistoryLoadStatus::class,
        'orders_status' => WbHistoryLoadStatus::class,
        'products_synced_at' => 'datetime',
        'products_count' => 'integer',
    ];

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(WbCabinet::class, 'cabinet_id');
    }
}
