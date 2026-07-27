<?php

namespace App\Models\Subscribers\Wb\Repricer;

use Carbon\Carbon;
use App\Models\User;
use App\Casts\EncryptCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Subscribers\Wb\Repricer\RepricerSettings;

class RepricerCabinets extends Model
{
    use HasFactory;

    /** Immediate hard-stop: deactivate stocks and stop dispatching. */
    public const FATAL_ERROR_CODES = [401, 403];

    /**
     * Codes that prevent new stocks jobs from being dispatched
     * (includes chronic rate-limit disable after threshold).
     */
    public const SKIP_DISPATCH_ERROR_CODES = [401, 403, 429];

    /** Consecutive 429 responses within the window before auto-disable. */
    public const RATE_LIMIT_DISABLE_THRESHOLD = 8;

    protected $table = 'wb_repricer_cabinets';
    protected $fillable = [
        'user_id',
        'name',
        'apikey',
        'error_code',
        'error_message',
        'is_migrated',
        'migrated_at',
        'wb_cabinet_id',
    ];
    protected $casts = [
        'apikey' => EncryptCast::class,
        'error_code' => 'integer',
        'is_migrated' => 'boolean',
        'migrated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function settings()
    {
        return $this->hasMany(RepricerSettings::class, 'cabinet_id', 'id');
    }

    public function stocks()
    {
        return $this->hasMany(RepricerStocks::class, 'cabinet_id', 'id');
    }

    public function logs()
    {
        return $this->hasMany(RepricerLogs::class, 'cabinet_id', 'id');
    }

    public function getCreatedAtAttribute($value)
    {
        return Carbon::parse($value)->setTimezone('Europe/Moscow')->format('d.m.Y H:i');
    }
}
