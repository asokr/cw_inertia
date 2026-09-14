export function stockHistoryRowKey(row) {
    if (row.warehouse_key) {
        return `${row.nm_id}-${row.chrt_id}-${row.warehouse_key}`;
    }

    return `${row.nm_id}-${row.chrt_id}`;
}

export function orderHistoryRowKey(row) {
    return `${row.nm_id}-${row.tech_size}-${row.barcode}`;
}

export function stockHistoryRowLabel(row) {
    const article = row.vendor_code || row.nm_id || "Товар";
    const size = row.tech_size ? String(row.tech_size) : "";
    const warehouse = row.warehouse_name ? String(row.warehouse_name) : "";
    return [article, size, warehouse].filter(Boolean).join(" · ");
}

export function orderHistoryRowLabel(row) {
    const article = row.vendor_code || row.nm_id || "Товар";
    const size = row.tech_size ? String(row.tech_size) : "";
    const barcode = row.barcode ? String(row.barcode) : "";
    return [article, size, barcode].filter(Boolean).join(" · ");
}

export function formatHistoryChartDate(date) {
    const parts = String(date).split("-");
    if (parts.length !== 3) {
        return date;
    }

    return `${Number(parts[2])}.${parts[1]}`;
}
