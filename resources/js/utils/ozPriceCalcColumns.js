function fmtCurrency(value) {
    const n = Number(value);
    return Number.isFinite(n)
        ? new Intl.NumberFormat("ru-RU", { style: "currency", currency: "RUB", maximumFractionDigits: 0 }).format(n)
        : "—";
}

function fmtPercent(value) {
    const n = Number(value);
    return Number.isFinite(n) ? `${n.toFixed(1)}%` : "—";
}

function fmtWithUnit(value, unit) {
    const n = Number(value);
    return Number.isFinite(n) ? `${n.toLocaleString("ru-RU")} ${unit}` : "—";
}

function formatCell(value, unit) {
    if (unit === "₽") return fmtCurrency(value);
    if (unit === "%") return fmtPercent(value);
    if (unit) return fmtWithUnit(value, unit);
    return value ?? "—";
}

function formatUpdatedAt(value) {
    if (!value) return "—";
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString("ru-RU");
}

/**
 * @param {Array<{key: string, title: string, unit?: string, color?: string|null, font_color?: string|null}>} apiColumns
 */
export function buildTanStackColumns(apiColumns = []) {
    const columns = apiColumns.map((col) => ({
        accessorKey: col.key,
        header: col.title,
        enableSorting: true,
        meta: {
            backgroundColor: col.color ?? null,
            fontColor: col.font_color ?? null,
        },
        cell: ({ getValue }) => formatCell(getValue(), col.unit ?? ""),
    }));

    columns.push({
        accessorKey: "updated_at",
        header: "Обновлено",
        enableSorting: true,
        cell: ({ getValue }) => formatUpdatedAt(getValue()),
    });

    return columns;
}