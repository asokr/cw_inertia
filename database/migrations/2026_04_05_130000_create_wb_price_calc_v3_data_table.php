<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wb_price_calc_v3_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabinet_id')
                ->constrained('wb_price_cabinets')
                ->cascadeOnDelete()
                ->comment('Кабинет'); // Кабинет

            // Данные товара (из файла)
            $table->string('brand')->nullable()->comment('Бренд'); // Бренд
            $table->string('subject_name')->nullable()->comment('Предмет'); // Предмет
            $table->string('vendor_code')->nullable()->comment('Артикул продавца'); // Артикул продавца
            $table->string('size')->nullable()->comment('Размер'); // Размер (добавлено по требованию)
            $table->string('barcode')->nullable()->comment('Баркод'); // Баркод (добавлено по требованию)
            $table->unsignedBigInteger('nm_id')->nullable()->comment('Артикул WB'); // Артикул WB
            $table->decimal('volume_liters', 10, 3)->nullable()->comment('Объем, л.'); // Объем, л.
            $table->decimal('extra_liters', 10, 3)->nullable()->comment('Лишние свыше 1 литра'); // Лишние свыше 1 литра

            // Вводимые/расчетные поля
            $table->decimal('cost_price', 12, 2)->nullable()->comment('себес. руб.'); // себес. руб.
            $table->decimal('margin_percent', 6, 2)->nullable()->comment('маржа, %'); // маржа, %
            $table->decimal('fulfillment_fee', 12, 2)->nullable()->comment('услуги фф руб./ед'); // услуги фф руб./ед
            $table->decimal('maintenance_percent', 6, 2)->nullable()->comment('% за ведение (от суммы к перечислению на р/с)'); // % за ведение (от суммы к перечислению на р/с)
            $table->decimal('stop_price', 12, 2)->nullable()->comment('СТОП-ЦЕНА, руб.'); // СТОП-ЦЕНА, руб.
            $table->decimal('avg_base_logistics', 12, 2)->nullable()->comment('ср. ст-ть прямой логистики за 1 л'); // ср. ст-ть прямой логистики за 1 л
            $table->decimal('avg_extra_liter_logistics', 12, 2)->nullable()->comment('ср. ст-ть прямой логистики за доп. л'); // ср. ст-ть прямой логистики за доп. л
            $table->decimal('localization_index', 8, 4)->default(1)->comment('ИЛ'); // ИЛ
            $table->decimal('avg_logistics', 12, 2)->nullable()->comment('итог. ст-ть прямой логистики, руб.'); // итог. ст-ть прямой логистики, руб.

            // Стоимость обратной логистики по диапазонам объема
            $table->decimal('reverse_logistics_cost_gt_1_0_l', 12, 2)->nullable()->comment('ст-ть обр. логистики для каждого товара > 1 л'); // ст-ть обр. логистики для каждого товара > 1 л
            $table->decimal('reverse_logistics_cost_0_801_1_0_l', 12, 2)->nullable()->comment('ст-ть обр. логистики для товаров 0,801-1,0 л'); // ст-ть обр. логистики для товаров 0,801-1,0 л
            $table->decimal('reverse_logistics_cost_0_601_0_8_l', 12, 2)->nullable()->comment('ст-ть обр. логистики для товаров 0,601-0,8 л'); // ст-ть обр. логистики для товаров 0,601-0,8 л
            $table->decimal('reverse_logistics_cost_0_401_0_6_l', 12, 2)->nullable()->comment('ст-ть обр. логистики для товаров 0,401-0,6 л'); // ст-ть обр. логистики для товаров 0,401-0,6 л
            $table->decimal('reverse_logistics_cost_0_201_0_4_l', 12, 2)->nullable()->comment('ст-ть обр. логистики для товаров 0,201-0,4 л'); // ст-ть обр. логистики для товаров 0,201-0,4 л
            $table->decimal('reverse_logistics_cost_0_001_0_2_l', 12, 2)->nullable()->comment('ст-ть обр. логистики для товаров 0,001-0,2 л'); // ст-ть обр. логистики для товаров 0,001-0,2 л

            // Проценты возврата по диапазонам объема
            $table->decimal('return_rate_gt_1_1_l', 8, 4)->nullable()->comment('ВОЗВРАТ >1.1 л.'); // ВОЗВРАТ >1.1 л.
            $table->decimal('return_rate_0_801_1_0_l', 8, 4)->nullable()->comment('ВОЗВРАТ 0,801-1л'); // ВОЗВРАТ 0,801-1л
            $table->decimal('return_rate_0_601_0_8_l', 8, 4)->nullable()->comment('ВОЗВРАТ 0,601-0,8л'); // ВОЗВРАТ 0,601-0,8л
            $table->decimal('return_rate_0_401_0_6_l', 8, 4)->nullable()->comment('ВОЗВРАТ 0,401-0,6л'); // ВОЗВРАТ 0,401-0,6л
            $table->decimal('return_rate_0_201_0_4_l', 8, 4)->nullable()->comment('ВОЗВРАТ 0,201-0,4л'); // ВОЗВРАТ 0,201-0,4л
            $table->decimal('return_rate_0_001_0_2_l', 8, 4)->nullable()->comment('ВОЗВРАТ 0,001-0,2л'); // ВОЗВРАТ 0,001-0,2л

            $table->decimal('return_cost', 12, 2)->nullable()->comment('Итог. ст-ть возврата'); // Итог. ст-ть возврата
            $table->decimal('buyout_percent', 6, 2)->nullable()->comment('% ВЫКУПА'); // % ВЫКУПА
            $table->decimal('total_logistics', 12, 2)->nullable()->comment('ИТОГОВАЯ ЛОГИСТИКА, руб.'); // ИТОГОВАЯ ЛОГИСТИКА, руб.
            $table->decimal('storage_cost', 12, 2)->nullable()->comment('хранение руб.'); // хранение руб.
            $table->unsignedInteger('sales_count')->nullable()->comment('продажи, шт.'); // продажи, шт.
            $table->decimal('storage_per_sale', 12, 2)->nullable()->comment('хранение/1 продажа, руб.'); // хранение/1 продажа, руб.

            $table->decimal('advertising_percent', 6, 2)->nullable()->comment('ДРР, % от оборота'); // ДРР, % от оборота
            $table->decimal('wb_commission_percent', 6, 2)->nullable()->comment('комиссия ВБ'); // комиссия ВБ
            $table->decimal('options_constructor_percent_sales', 6, 2)->nullable()->comment('% на опции в конструкторе тарифов, от суммы продажи'); // % на опции в конструкторе тарифов, от суммы продажи
            $table->decimal('options_constructor_percent_transfer', 6, 2)->nullable()->comment('% на опции в конструкторе тарифов, от перечисления'); // % на опции в конструкторе тарифов, от перечисления
            $table->decimal('acquiring_percent', 6, 2)->nullable()->comment('эквайринг'); // эквайринг
            $table->decimal('tax_percent', 6, 2)->nullable()->comment('налог, % от продажи'); // налог, % от продажи
            $table->decimal('maintenance_percent_sales', 6, 2)->nullable()->comment('% за ведение, если считается от суммы продажи'); // % за ведение, если считается от суммы продажи
            $table->decimal('irp', 8, 4)->nullable()->comment('ИРП'); // ИРП
            $table->decimal('commission_plus_acquiring', 6, 2)->nullable()->comment('общий % с каждой проданной ед. на расходы WB'); // общий % с каждой проданной ед. на расходы WB

            $table->decimal('standard_discount_percent', 6, 2)->nullable()->comment('стандартная скидка для покупателя, %'); // стандартная скидка для покупателя, %
            $table->decimal('promotion_percent', 6, 2)->nullable()->comment('% на участие в акции'); // % на участие в акции
            $table->decimal('min_price_promo', 12, 3)->nullable()->comment('MIN ЦЕНА ДЛЯ АКЦИЙ'); // MIN ЦЕНА ДЛЯ АКЦИЙ
            $table->decimal('standard_price', 12, 2)->nullable()->comment('ЦЕНА БЕЗ АКЦИИ'); // ЦЕНА БЕЗ АКЦИИ
            $table->decimal('price_before_discount', 12, 2)->nullable()->comment('ЦЕНА ДО СКИДКИ'); // ЦЕНА ДО СКИДКИ

            $table->softDeletes();
            $table->timestamps();

            $table->index('cabinet_id');
            $table->index('nm_id');
            $table->index('vendor_code');
            $table->index('barcode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wb_price_calc_v3_data');
    }
};
