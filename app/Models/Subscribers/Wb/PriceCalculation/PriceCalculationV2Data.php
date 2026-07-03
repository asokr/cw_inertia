<?php

namespace App\Models\Subscribers\Wb\PriceCalculation;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PriceCalculationV2Data extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'wb_price_calc_v2_data';

    protected $fillable = [
        'cabinet_id',

        // --- Данные товара (подтягиваются из API) ---
        'brand',                      // Бренд товара
        'subject_name',               // Предмет (категория товара на WB)
        'vendor_code',                // Артикул продавца
        'nm_id',                      // Артикул WB (номенклатура)
        'size',                       // Размер
        'barcode',                    // Баркод
        'volume_liters',              // Объем, л.
        'extra_liters',               // Доп. литры сверх 1 л

        // --- Заполняется пользователем с фронта ---
        'cost_price',                 // Себестоимость, руб.
        'margin_percent',             // Маржа, %
        'fulfillment_fee',            // Услуги ФФ (фулфилмент), руб./ед.
        'maintenance_percent',        // % за ведение (от суммы к перечислению на р/с ИЛИ от суммы продаж — тип выбирается в настройках кабинета)

        // --- Расчётные поля (вычисляются сервисом) ---
        'stop_price',                 // СТОП-ЦЕНА, руб. — минимальная цена для покрытия себестоимости + маржа + ФФ + ведение
        'avg_base_logistics',         // Ср. базовая логистика — средняя по складам из тарифов WB
        'avg_extra_liter_logistics',  // Ср. логистика за доп. литр — доплата за каждый литр сверх 1 л
        'avg_logistics',              // Средняя логистика, руб. — итого логистика с учётом объёма товара
        'return_cost',                // ВОЗВРАТ, руб. — стоимость возврата товара
        'buyout_percent',             // % выкупа (настройка: на кабинет или на каждый артикул)
        'localization_index',         // Индекс локализации (1 = не учитывается; если учитывается — подтягивается с портала WB: Поставки и заказы → Тарифы)
        'total_logistics',            // ИТОГОВАЯ ЛОГИСТИКА, руб. — с учётом % выкупа, возврата и индекса локализации
        'storage_cost',               // Хранение, руб. (переключатель: учитывать / не учитывать)
        'sales_count',                // Продажи, шт. — количество продаж за период
        'storage_per_sale',           // Хранение / 1 продажа, руб. = storage_cost / sales_count
        'advertising_percent',        // ДРР, % от оборота — % от цены продажи 1 ед. на рекламу
        'wb_commission_percent',      // Комиссия ВБ — зависит от категории; выбор: FBS (kgvpMarketplace), FBO (paidStorageKgvp), из фин. отчетов или вручную
        'acquiring_percent',          // Эквайринг, % — вручную или из фин. отчетов
        'commission_plus_acquiring',  // Комиссия + Эквайринг, % = wb_commission_percent + acquiring_percent

        // --- Заполняется пользователем ---
        'tax_percent',                // % налога
        'standard_discount_percent',  // Стандартная скидка для покупателя, %
        'promotion_percent',          // % на участие в акции

        // --- Итоговые рассчитанные цены ---
        'min_price_promo',            // Минимальная цена (для акций) = (stop_price + total_logistics + storage_per_sale) / ((100 - advertising - commission_plus_acquiring - tax) / 100)
        'standard_price',             // Стандартная цена (без акций) = min_price_promo / ((100 - promotion_percent) / 100)
        'price_before_discount',      // Цена до скидки = ОКРУГЛВВЕРХ(standard_price / ((100 - standard_discount_percent) / 100))
    ];

    protected $casts = [
        'nm_id'                      => 'integer',
        'volume_liters'              => 'float',
        'extra_liters'               => 'float',
        'cost_price'                 => 'float',
        'margin_percent'             => 'float',
        'fulfillment_fee'            => 'float',
        'maintenance_percent'        => 'float',
        'stop_price'                 => 'float',
        'avg_base_logistics'         => 'float',
        'avg_extra_liter_logistics'  => 'float',
        'avg_logistics'              => 'float',
        'return_cost'                => 'float',
        'buyout_percent'             => 'float',
        'localization_index'         => 'float',
        'total_logistics'            => 'float',
        'storage_cost'               => 'float',
        'sales_count'                => 'integer',
        'storage_per_sale'           => 'float',
        'advertising_percent'        => 'float',
        'wb_commission_percent'      => 'float',
        'acquiring_percent'          => 'float',
        'commission_plus_acquiring'  => 'float',
        'tax_percent'                => 'float',
        'standard_discount_percent'  => 'float',
        'promotion_percent'          => 'float',
        'min_price_promo'            => 'float',
        'standard_price'             => 'float',
        'price_before_discount'      => 'float',
    ];

    /**
     * Связь с кабинетом WB
     */
    public function cabinet()
    {
        return $this->belongsTo(PriceCalculationCabinets::class, 'cabinet_id');
    }
}
