<?php

namespace App\Models\Subscribers\Wb\PriceCalculation;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PriceCalculationData extends Model
{
    use HasFactory;

    protected $table = 'wb_price_data';
    protected $fillable = [
        'cabinet_id',
        'nm_id',
        'skus',
        'nm_data',
        'cost_price',
        'profit',
        'redemption',
        'bought_out',
        'logistics',
        'total_month_storage',
        'fullfilment',
        'min_price',
        'price',
    ];

    protected function nmData(): Attribute
    {
        return new Attribute(
            get: fn($value) => json_decode($value, true),
            set: fn($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
        );
    }
    public function getCostPriceAttribute($value)
    {
        return (float) $value;
    }

    public function getProfitAttribute($value)
    {
        return (float) $value;
    }

    public function getCreatedAtAttribute($value)
    {
        return Carbon::parse($value)->setTimezone('Europe/Moscow')->format('d.m.Y H:i');
    }
}
