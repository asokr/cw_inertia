<?php

namespace App\Models\Subscribers\Wb\PriceCalculation;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PriceCalculationV2Settings extends Model
{
    use HasFactory;

    protected $table = 'wb_price_calc_v2_settings';

    protected $fillable = [
        'cabinet_id',

        // --- Тип расчёта "% за ведение" ---
        'maintenance_type',           // 'transfer' = от суммы к перечислению на р/с, 'sales' = от суммы продаж

        // --- Настройка "% выкупа" ---
        'buyout_scope',               // 'cabinet' = единый на весь кабинет, 'article' = индивидуально на каждый артикул

        // --- Индекс локализации ---
        'use_localization_index',     // true = учитывать (подтягиваем с портала WB: Поставки и заказы → Тарифы), false = ставим 1

        // --- Хранение ---
        'use_storage',                // true = учитывать хранение в расчётах, false = не учитывать

        // --- ИРП ---
        'use_irp',                    // true = учитывать ИРП в расчётах, false = не учитывать

        // --- Источник комиссии WB ---
        'commission_source',          // 'fbs' = FBS (kgvpMarketplace), 'fbo' = FBO (paidStorageKgvp), 'reports' = из фин. отчетов, 'manual' = загрузка Excel на каждый товар

        // --- Источник эквайринга ---
        'acquiring_source',           // 'reports' = из фин. отчетов, 'manual' = загрузка Excel на каждый товар

        // --- Скрыть размеры ---
        'hide_sizes',                 // true = скрыть размеры (группировка по nm_id), false = показывать все размеры
    ];

    protected $casts = [
        'use_localization_index'     => 'boolean',
        'use_storage'                => 'boolean',
        'use_irp'                    => 'boolean',
        'hide_sizes'                 => 'boolean',
    ];

    protected $attributes = [
        'maintenance_type'       => 'transfer',
        'buyout_scope'           => 'cabinet',
        'use_localization_index' => false,
        'use_storage'            => false,
        'use_irp'                => false,
        'commission_source'      => 'fbs',
        'acquiring_source'       => 'manual',
        'hide_sizes'             => true,
    ];

    /**
     * Связь с кабинетом WB
     */
    public function cabinet()
    {
        return $this->belongsTo(PriceCalculationCabinets::class, 'cabinet_id');
    }

    /**
     * Связь с данными товаров v2 через кабинет
     */
    public function data()
    {
        return $this->hasMany(PriceCalculationV2Data::class, 'cabinet_id', 'cabinet_id');
    }
}
