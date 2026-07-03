<?php

namespace App\Models\Subscribers\Oz\PriceCalc;

use Carbon\Carbon;
use App\Models\User;
use App\Casts\EncryptCast;
use App\Models\Subscribers\Oz\PriceCalc\OzPriceCalcFbs;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OzPriceCalcCabinet extends Model
{
    use HasFactory;

    protected $table = 'oz_price_calc_cabinets';

    protected $fillable = [
        'user_id',
        'name',
        'client_id',
        'apikey',
        'last_sync_error',
    ];

    protected $casts = [
        'apikey' => EncryptCast::class,
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function fboRecords()
    {
        return $this->hasMany(OzPriceCalcFbo::class, 'cabinet_id');
    }

    public function fbsRecords()
    {
        return $this->hasMany(OzPriceCalcFbs::class, 'cabinet_id');
    }

    public function getCreatedAtAttribute($value)
    {
        return Carbon::parse($value)->setTimezone('Europe/Moscow')->format('d.m.Y H:i');
    }
}
