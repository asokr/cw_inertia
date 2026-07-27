<?php

namespace App\Models\Subscribers\Wb\PriceCalculation;

use Carbon\Carbon;
use App\Casts\EncryptCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PriceCalculationCabinets extends Model
{
    use HasFactory;

    protected $table = 'wb_price_cabinets';
    protected $fillable = [
        'user_id',
        'name',
        'apikey',
        'is_migrated',
        'migrated_at',
        'wb_cabinet_id',
    ];
    protected $casts = [
        'apikey' => EncryptCast::class,
        'is_migrated' => 'boolean',
        'migrated_at' => 'datetime',
    ];

    public function getCreatedAtAttribute($value)
    {
        return Carbon::parse($value)->setTimezone('Europe/Moscow')->format('d.m.Y H:i');
    }
}
