function fmtNum(value) {
    const n = Number(value);
    return Number.isFinite(n) ? n.toLocaleString("ru-RU") : "—";
}

function fmtCur(value) {
    const n = Number(value);
    return Number.isFinite(n)
        ? new Intl.NumberFormat("ru-RU", { style: "currency", currency: "RUB", maximumFractionDigits: 2 }).format(n)
        : "—";
}

function fmtPct(value) {
    const n = Number(value);
    return Number.isFinite(n) ? `${n.toFixed(2)}%` : "—";
}

function fmtInt(value) {
    const n = Number(value);
    return Number.isFinite(n) ? Math.round(n).toLocaleString("ru-RU") : "—";
}

function col(id, header, group, format = "text") {
    const formatters = {
        text: (v) => v ?? "—",
        num: fmtNum,
        cur: fmtCur,
        pct: fmtPct,
        int: fmtInt,
    };

    return {
        accessorKey: id,
        header,
        meta: { group },
        enableSorting: true,
        cell: ({ getValue }) => formatters[format](getValue()),
    };
}

export function buildWbPriceCalcV3Columns(settings = {}) {
    const columns = [
        col("brand", "Бренд", "product"),
        col("subject_name", "Предмет", "product"),
        col("vendor_code", "Артикул продавца", "product"),
    ];

    if (!settings.hide_sizes) {
        columns.push(col("size", "Размер", "product"), col("barcode", "Баркод", "product"));
    }

    columns.push(
        col("nm_id", "Артикул WB", "product"),
        col("volume_liters", "Объём, л.", "product", "num"),
        col("extra_liters", "Литры свыше 1 л", "product", "num"),
        col("cost_price", "Себес, руб.", "cost", "cur"),
        col("margin_percent", "Маржа, %", "cost", "pct"),
        col("fulfillment_fee", "Услуги ФФ, руб./ед", "cost", "cur"),
        col("maintenance_percent", "% за ведение (от перечисл.)", "cost", "pct"),
        col("stop_price", "СТОП-ЦЕНА, руб.", "white", "cur"),
        col("avg_base_logistics", "Ср. ст-ть прям. лог. за 1 л", "logistics", "cur"),
        col("avg_extra_liter_logistics", "Ср. ст-ть прям. лог. за доп. л", "logistics", "cur"),
        col("localization_index", "ИЛ", "logistics", "num"),
        col("avg_logistics", "Итог. ст-ть прям. логистики, руб.", "white", "cur"),
        col("reverse_logistics_cost_gt_1_0_l", "Ст-ть обр. лог. > 1 л", "extra", "cur"),
        col("reverse_logistics_cost_0_801_1_0_l", "Ст-ть обр. лог. 0,801-1л", "extra", "cur"),
        col("reverse_logistics_cost_0_601_0_8_l", "Ст-ть обр. лог. 0,601-0,8л", "extra", "cur"),
        col("reverse_logistics_cost_0_401_0_6_l", "Ст-ть обр. лог. 0,401-0,6л", "extra", "cur"),
        col("reverse_logistics_cost_0_201_0_4_l", "Ст-ть обр. лог. 0,201-0,4л", "extra", "cur"),
        col("reverse_logistics_cost_0_001_0_2_l", "Ст-ть обр. лог. 0,001-0,2л", "extra", "cur"),
        col("return_rate_gt_1_1_l", "ВОЗВРАТ >1.1 л", "white", "cur"),
        col("return_rate_0_801_1_0_l", "ВОЗВРАТ 0,801-1л", "white", "cur"),
        col("return_rate_0_601_0_8_l", "ВОЗВРАТ 0,601-0,8л", "white", "cur"),
        col("return_rate_0_401_0_6_l", "ВОЗВРАТ 0,401-0,6л", "white", "cur"),
        col("return_rate_0_201_0_4_l", "ВОЗВРАТ 0,201-0,4л", "white", "cur"),
        col("return_rate_0_001_0_2_l", "ВОЗВРАТ 0,001-0,2л", "white", "cur"),
        col("return_cost", "Итог. ст-ть возврата", "white", "cur"),
        col("buyout_percent", "% ВЫКУПА", "beige", "pct"),
        col("total_logistics", "ИТОГОВАЯ ЛОГИСТИКА, руб.", "white", "cur"),
    );

    if (settings.use_storage !== false) {
        columns.push(
            col("storage_cost", "Хранение, руб.", "logistics", "cur"),
            col("sales_count", "Продажи, шт.", "logistics", "int"),
            col("storage_per_sale", "Хранение/1 прод., руб.", "white", "cur"),
        );
    }

    columns.push(
        col("advertising_percent", "ДРР, % от оборота", "finance", "pct"),
        col("wb_commission_percent", "Комиссия ВБ", "finance", "pct"),
        col("options_constructor_percent_sales", "% опции конструктора (от продажи)", "finance", "pct"),
        col("options_constructor_percent_transfer", "% опции конструктора (от перечисл.)", "finance", "pct"),
        col("acquiring_percent", "Эквайринг", "finance", "pct"),
        col("tax_percent", "Налог, % от продажи", "finance", "pct"),
        col("maintenance_percent_sales", "% за ведение (от продаж)", "finance", "pct"),
    );

    if (settings.use_irp !== false) {
        columns.push(col("irp", "ИРП", "finance", "num"));
    }

    columns.push(
        col("commission_plus_acquiring", "общий % с каждой проданной ед.", "white", "pct"),
        col("standard_discount_percent", "Стандартная скидка, %", "beige", "pct"),
        col("promotion_percent", "% на участие в акции", "beige", "pct"),
        col("min_price_promo", "MIN ЦЕНА ДЛЯ АКЦИИ", "result", "cur"),
        col("standard_price", "ЦЕНА БЕЗ АКЦИИ", "result", "cur"),
        col("price_before_discount", "ЦЕНА ДО СКИДКИ", "result", "cur"),
    );

    return columns;
}