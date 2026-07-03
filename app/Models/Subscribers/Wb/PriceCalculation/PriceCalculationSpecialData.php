<?php

namespace App\Models\Subscribers\Wb\PriceCalculation;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PriceCalculationSpecialData extends Model
{
    use HasFactory;

    protected $table = 'wp_price_special_data';

    protected $fillable = [
        'cabinet_id',
        'nalog',
        'promotions',
        'advertising',
        'maintenance',
        'acquiring_fee',
    ];

    // Если хотите, чтобы при массовом присвоении эти поля сразу парсились
    protected $attributes = [
        'nalog'          => 0,
        'promotions'     => 0,
        'advertising'    => 0,
        'maintenance'    => 0,
        'acquiring_fee'  => 0,
    ];

    // Пример мутатора для одного поля
    public function setNalogAttribute($value)
    {
        $this->attributes['nalog'] = $this->castCommaToDot($value);
    }

    // Повторите для всех остальных полей
    public function setPromotionsAttribute($value)
    {
        $this->attributes['promotions'] = $this->castCommaToDot($value);
    }

    public function setAdvertisingAttribute($value)
    {
        $this->attributes['advertising'] = $this->castCommaToDot($value);
    }

    public function setMaintenanceAttribute($value)
    {
        $this->attributes['maintenance'] = $this->castCommaToDot($value);
    }

    public function setAcquiringFeeAttribute($value)
    {
        $this->attributes['acquiring_fee'] = $this->castCommaToDot($value);
    }


    // Вспомогательный метод
    protected function castCommaToDot($value): float
    {
        if (is_string($value)) {
            // убираем пробелы, заменяем запятую на точку
            $normalized = str_replace([',', ' '], ['.', ''], $value);
            return (float) $normalized;
        }

        return (float) $value;
    }
}
