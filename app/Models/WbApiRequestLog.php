<?php

namespace App\Models;

use App\Casts\EncryptCast;
use Illuminate\Database\Eloquent\Model;

class WbApiRequestLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'seller_id',
        'api_key_hash',
        'api_key',
        'method',
        'endpoint',
        'request_data',
        'response_code',
        'created_at',
    ];

    protected $casts = [
        'api_key' => EncryptCast::class,
        'request_data' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Связь с агрегированной статистикой по дню
     */
    public function dailyStat()
    {
        return $this->hasOne(WbApiUsageStat::class, 'api_key_hash', 'api_key_hash')
            ->whereDate('stat_date', $this->created_at?->toDateString());
    }
}
