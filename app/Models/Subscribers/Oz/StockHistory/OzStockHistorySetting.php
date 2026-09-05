<?php

namespace App\Models\Subscribers\Oz\StockHistory;

use App\Enums\OzStockHistoryTrackingStatus;
use App\Models\Subscribers\Oz\OzCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OzStockHistorySetting extends Model
{
    public const DEFAULT_RETENTION_DAYS = 90;

    public const MIN_RETENTION_DAYS = 7;

    public const MAX_RETENTION_DAYS = 180;

    protected $table = 'oz_stock_history_settings';

    protected $fillable = [
        'cabinet_id',
        'retention_days',
        'tracking_enabled',
        'tracking_status',
        'products_synced_at',
        'products_count',
        'last_error',
    ];

    protected $casts = [
        'retention_days' => 'integer',
        'tracking_enabled' => 'boolean',
        'tracking_status' => OzStockHistoryTrackingStatus::class,
        'products_synced_at' => 'datetime',
        'products_count' => 'integer',
    ];

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(OzCabinet::class, 'cabinet_id');
    }
}
