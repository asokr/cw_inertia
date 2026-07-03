<?php

namespace App\Models\Subscribers\Wb\PriceCalculation;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PriceCalculationV3Data extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'wb_price_calc_v3_data';

    protected $fillable = [
        'cabinet_id', // Кабинет

        'brand', // Бренд
        'subject_name', // Предмет
        'vendor_code', // Артикул продавца
        'size', // Размер
        'barcode', // Баркод
        'nm_id', // Артикул WB
        'volume_liters', // Объем, л.
        'extra_liters', // Лишние свыше 1 литра

        'cost_price', // себес. руб.
        'margin_percent', // маржа, %
        'fulfillment_fee', // услуги фф руб./ед
        'maintenance_percent', // % за ведение (от суммы к перечислению на р/с)
        'stop_price', // СТОП-ЦЕНА, руб.
        'avg_base_logistics', // ср. ст-ть прямой логистики за 1 л
        'avg_extra_liter_logistics', // ср. ст-ть прямой логистики за доп. л
        'localization_index', // ИЛ
        'avg_logistics', // итог. ст-ть прямой логистики, руб.

        'reverse_logistics_cost_gt_1_0_l', // ст-ть обр. логистики для каждого товара > 1 л
        'reverse_logistics_cost_0_801_1_0_l', // ст-ть обр. логистики для товаров 0,801-1,0 л
        'reverse_logistics_cost_0_601_0_8_l', // ст-ть обр. логистики для товаров 0,601-0,8 л
        'reverse_logistics_cost_0_401_0_6_l', // ст-ть обр. логистики для товаров 0,401-0,6 л
        'reverse_logistics_cost_0_201_0_4_l', // ст-ть обр. логистики для товаров 0,201-0,4 л
        'reverse_logistics_cost_0_001_0_2_l', // ст-ть обр. логистики для товаров 0,001-0,2 л

        'return_rate_gt_1_1_l', // ВОЗВРАТ >1.1 л.
        'return_rate_0_801_1_0_l', // ВОЗВРАТ 0,801-1л
        'return_rate_0_601_0_8_l', // ВОЗВРАТ 0,601-0,8л
        'return_rate_0_401_0_6_l', // ВОЗВРАТ 0,401-0,6л
        'return_rate_0_201_0_4_l', // ВОЗВРАТ 0,201-0,4л
        'return_rate_0_001_0_2_l', // ВОЗВРАТ 0,001-0,2л

        'return_cost', // Итог. ст-ть возврата
        'buyout_percent', // % ВЫКУПА
        'total_logistics', // ИТОГОВАЯ ЛОГИСТИКА, руб.
        'storage_cost', // хранение руб.
        'sales_count', // продажи, шт.
        'storage_per_sale', // хранение/1 продажа, руб.

        'advertising_percent', // ДРР, % от оборота
        'wb_commission_percent', // комиссия ВБ
        'options_constructor_percent_sales', // % на опции в конструкторе тарифов, от суммы продажи
        'options_constructor_percent_transfer', // % на опции в конструкторе тарифов, от перечисления
        'acquiring_percent', // эквайринг
        'tax_percent', // налог, % от продажи
        'maintenance_percent_sales', // % за ведение, если считается от суммы продажи
        'irp', // ИРП
        'commission_plus_acquiring', // общий % с каждой проданной ед. на расходы WB

        'standard_discount_percent', // стандартная скидка для покупателя, %
        'promotion_percent', // % на участие в акции
        'min_price_promo', // MIN ЦЕНА ДЛЯ АКЦИЙ
        'standard_price', // ЦЕНА БЕЗ АКЦИИ
        'price_before_discount', // ЦЕНА ДО СКИДКИ
    ];

    protected $casts = [
        'nm_id' => 'integer',
        'sales_count' => 'integer',

        'volume_liters' => 'float',
        'extra_liters' => 'float',
        'cost_price' => 'float',
        'margin_percent' => 'float',
        'fulfillment_fee' => 'float',
        'maintenance_percent' => 'float',
        'stop_price' => 'float',
        'avg_base_logistics' => 'float',
        'avg_extra_liter_logistics' => 'float',
        'localization_index' => 'float',
        'avg_logistics' => 'float',

        'reverse_logistics_cost_gt_1_0_l' => 'float',
        'reverse_logistics_cost_0_801_1_0_l' => 'float',
        'reverse_logistics_cost_0_601_0_8_l' => 'float',
        'reverse_logistics_cost_0_401_0_6_l' => 'float',
        'reverse_logistics_cost_0_201_0_4_l' => 'float',
        'reverse_logistics_cost_0_001_0_2_l' => 'float',

        'return_rate_gt_1_1_l' => 'float',
        'return_rate_0_801_1_0_l' => 'float',
        'return_rate_0_601_0_8_l' => 'float',
        'return_rate_0_401_0_6_l' => 'float',
        'return_rate_0_201_0_4_l' => 'float',
        'return_rate_0_001_0_2_l' => 'float',

        'return_cost' => 'float',
        'buyout_percent' => 'float',
        'total_logistics' => 'float',
        'storage_cost' => 'float',
        'storage_per_sale' => 'float',
        'advertising_percent' => 'float',
        'wb_commission_percent' => 'float',
        'options_constructor_percent_sales' => 'float',
        'options_constructor_percent_transfer' => 'float',
        'acquiring_percent' => 'float',
        'tax_percent' => 'float',
        'maintenance_percent_sales' => 'float',
        'irp' => 'float',
        'commission_plus_acquiring' => 'float',
        'standard_discount_percent' => 'float',
        'promotion_percent' => 'float',
        'min_price_promo' => 'float',
        'standard_price' => 'float',
        'price_before_discount' => 'float',
    ];

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(PriceCalculationCabinets::class, 'cabinet_id');
    }
}
