function fmtNumber(value) {
    const n = Number(value);
    return Number.isFinite(n) ? n.toLocaleString("ru-RU") : "—";
}

function fmtPercent(value) {
    const n = Number(value);
    return Number.isFinite(n) ? `${n} %` : "—";
}

function fmtCashback(value) {
    const n = Number(value);
    return n === 0 ? "" : fmtNumber(n);
}

/**
 * @param {Array<{key: string, header: string, format?: (row: object) => string}>} defs
 */
export function buildProfitabilityColumns(defs = []) {
    return defs.map((def) => ({
        accessorKey: def.key,
        header: def.header,
        enableSorting: false,
        cell: ({ row }) => {
            if (typeof def.format === "function") {
                return def.format(row.original);
            }
            return row.original[def.key] ?? "—";
        },
    }));
}

export const salesColumns = buildProfitabilityColumns([
    { key: "sa_name", header: "Артикул поставщика" },
    { key: "nm_id", header: "Артикул WB" },
    { key: "barcode", header: "Баркод" },
    { key: "warehouse", header: "Склад" },
    { key: "quantity", header: "Кол-во" },
    { key: "sum_to_transfer", header: "Сумма к перечислению", format: (row) => fmtNumber(row.sum_to_transfer) },
    { key: "purchase_cost", header: "Себестоимость" },
    { key: "logistics", header: "Логистика" },
    { key: "cost_adjustments", header: "Затраты/доплаты", format: (row) => fmtNumber(row.cost_adjustments) },
    { key: "nalog", header: "Налог", format: (row) => fmtNumber(row.nalog) },
    { key: "cashback", header: "Кэшбэк", format: (row) => fmtCashback(row.cashback) },
    { key: "dop_rashod", header: "Доп. расходы", format: (row) => fmtNumber(row.dop_rashod) },
    { key: "margin", header: "Маржинальность", format: (row) => fmtNumber(row.margin) },
    { key: "profitability_percent", header: "Рентабельность", format: (row) => fmtPercent(row.profitability_percent) },
]);

export const returnsColumns = buildProfitabilityColumns([
    { key: "sa_name", header: "Артикул поставщика" },
    { key: "nm_id", header: "Артикул WB" },
    { key: "barcode", header: "Баркод" },
    { key: "warehouse", header: "Склад" },
    { key: "quantity", header: "Кол-во" },
    { key: "sum_to_transfer", header: "Сумма", format: (row) => fmtNumber(row.sum_to_transfer) },
]);

export const logisticsColumns = buildProfitabilityColumns([
    { key: "sa_name", header: "Артикул поставщика" },
    { key: "nm_id", header: "Артикул WB" },
    { key: "barcode", header: "Баркод" },
    { key: "warehouse", header: "Склад" },
    { key: "reasoning", header: "Обоснование" },
    { key: "logistics", header: "Стоимость" },
]);

export const otherOperationsColumns = buildProfitabilityColumns([
    { key: "sa_name", header: "Артикул поставщика" },
    { key: "nm_id", header: "Артикул WB" },
    { key: "barcode", header: "Баркод" },
    { key: "warehouse", header: "Склад" },
    { key: "type", header: "Тип затраты" },
    { key: "reasoning", header: "Обоснование" },
    {
        key: "sum_to_transfer",
        header: "Стоимость",
        format: (row) => {
            const value = Number(row.sum_to_transfer) ? row.sum_to_transfer : row.logistics;
            return fmtNumber(value);
        },
    },
]);