<?php

namespace App\Models\Subscribers\Wb\Repricer;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use App\Models\Subscribers\Wb\Repricer\RepricerLogs;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;

class RepricerSettings extends Model
{
    use HasFactory;

    protected $table = 'wb_repricer_settings';
    protected $fillable = [
        'name',
        'cabinet_id',
        'nmID',
        'base_value', //Стандартная цена
        'base_discount', //скидка товара
        'price_type', //enum (Скидка) DISCOUNT || PRICE (Цена без скидки)
        'strategy', //enum TIME || STOCK
        'pricing_modifier_type', //enum 'PROCENT' || 'FIXED'
        'terms', //JSON Условия стратегии
        'active', //Активна стратеги Да/Нет (ставит бот)
        'status', //Работаем по задаче или нет
        'repeats_counter', //Подсчёт ошибок при попытке смены стоимости
    ];

    protected function terms(): Attribute
    {
        return new Attribute(
            get: fn($value) => json_decode($value, true),
            set: fn($value) => json_encode($value, JSON_UNESCAPED_UNICODE)
        );
    }

    public function cabinet()
    {
        return $this->hasOne(RepricerCabinets::class, 'id', 'cabinet_id');
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
        //     RepricerLogs::where(['nmID' => $query->nmID, 'strategy' => 'TIME'])->delete();
        // });
    }
}
