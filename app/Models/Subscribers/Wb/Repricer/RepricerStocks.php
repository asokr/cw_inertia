<?php

namespace App\Models\Subscribers\Wb\Repricer;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use App\Models\Subscribers\Wb\Repricer\RepricerLogs;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;

class RepricerStocks extends Model
{
    protected $table = 'wb_repricer_stocks';
    protected $fillable = [
        'name',
        'cabinet_id',
        'nmID',
        'base_value', //Стандартная цена
        'base_discount', //скидка товара
        'strategy', // Стратегия - меняем дял всей номенклатуры (1), или для размеров (2)
        'editable_size_price',
        'terms', //JSON Условия стратегии
        'added_value', //Сколько было добавлена к цене
        'active', //Активна стратеги Да/Нет (ставит бот)
        'status', //Работаем по задаче или нет
        'repeats_counter', //Подсчёт ошибок при попытке смены стоимости
    ];

    protected $appends = ['discounted_price'];

    public function getDiscountedPriceAttribute()
    {
        return round($this->base_value - ($this->base_value * $this->base_discount / 100));
    }

    protected function terms(): Attribute
    {
        return new Attribute(
            get: fn($value) => json_decode($value, true),
            set: fn($value) => json_encode($value, JSON_UNESCAPED_UNICODE)
        );
    }

    public function cabinet()
    {
        return $this->belongsTo(RepricerCabinets::class, 'cabinet_id', 'id');
    }

    public function logs()
    {
        return $this->hasMany(RepricerLogs::class, 'nmID', 'nmID');
    }

    public function getCreatedAtAttribute($value)
    {
        return Carbon::parse($value)->setTimezone('Europe/Moscow')->format('d.m.Y H:i');
    }

    public function belong()
    {
        return RepricerCabinets::where(['id' => $this->cabinet_id, 'user_id' => auth()->id()])->first();
    }

    public static function boot()
    {
        parent::boot();

        // static::deleted(function ($query) {
        //     RepricerLogs::where(['nmID' => $query->nmID, 'strategy' => 'STOCK'])->delete();
        // });
    }
}
