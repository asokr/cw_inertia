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

    public const FATAL_ERROR_CODES = [401];

    protected $table = 'wb_repricer_cabinets';
    protected $fillable = [
        'user_id',
        'name',
        'apikey',
        'error_code',
        'error_message',
    ];
    protected $casts = [
        'apikey' => EncryptCast::class,
        'error_code' => 'integer',
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
