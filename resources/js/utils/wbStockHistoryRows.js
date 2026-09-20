export function stockHistoryRowKey(row) {
    if (row?.kind === "nomenclature") {
        return `nm-${row.nm_id}`;
    }

    return `${row.nm_id}-${row.chrt_id}`;
}

export function orderHistoryRowKey(row) {
    if (row?.kind === "nomenclature") {
        return `nm-${row.nm_id}`;
    }

    return `${row.nm_id}-${row.tech_size}-${row.barcode}`;
}

export function stockHistoryRowLabel(row) {
    const article = row.vendor_code || row.nm_id || "Товар";
    if (row?.kind === "nomenclature") {
        return String(article);
    }

    const size = row.tech_size ? String(row.tech_size) : "";
    return [article, size].filter(Boolean).join(" · ");
}

/**
 * Сумма рядов по дням: незагруженный день у всех размеров остаётся прочерком.
 */
export function sumHistorySeries(seriesList) {
    const list = Array.isArray(seriesList) ? seriesList.filter((item) => Array.isArray(item)) : [];
    if (list.length === 0) {
        return [];
    }

    const length = Math.max(...list.map((item) => item.length));
    const result = [];
    for (let index = 0; index < length; index += 1) {
        let sum = 0;
        let hasValue = false;
        for (const series of list) {
            const value = index < series.length ? series[index] : null;
            if (value !== null && value !== undefined) {
                sum += Number(value);
                hasValue = true;
            }
        }
        result.push(hasValue ? sum : null);
    }

    return result;
}

/**
 * Номенклатура (nm_id) → размеры текущей страницы, с суммой значений по дням.
 */
export function groupHistoryRowsByNomenclature(rows) {
    const groups = [];
    const indexByNm = new Map();

    for (const row of Array.isArray(rows) ? rows : []) {
        const nmId = row.nm_id;
        let group = indexByNm.get(nmId);
        if (!group) {
            group = {
                kind: "nomenclature",
                nm_id: nmId,
                image_url: row.image_url,
                subject: row.subject,
                vendor_code: row.vendor_code,
                barcode: row.barcode,
                tech_size: null,
                quantity: 0,
                in_way_to_client: 0,
                in_way_from_client: 0,
                series: [],
                sizes: [],
            };
            indexByNm.set(nmId, group);
            groups.push(group);
        }
        group.sizes.push(row);
        if (!group.image_url && row.image_url) {
            group.image_url = row.image_url;
        }
        if (!group.subject && row.subject) {
            group.subject = row.subject;
        }
        if (!group.vendor_code && row.vendor_code) {
            group.vendor_code = row.vendor_code;
        }
    }

    for (const group of groups) {
        group.quantity = group.sizes.reduce((sum, row) => sum + (Number(row.quantity) || 0), 0);
        group.in_way_to_client = group.sizes.reduce((sum, row) => sum + (Number(row.in_way_to_client) || 0), 0);
        group.in_way_from_client = group.sizes.reduce((sum, row) => sum + (Number(row.in_way_from_client) || 0), 0);
        group.series = sumHistorySeries(group.sizes.map((row) => row.series || []));
    }

    return groups;
}

export function groupStockHistoryRowsByNomenclature(rows) {
    return groupHistoryRowsByNomenclature(rows);
}

export function orderHistoryRowLabel(row) {
    const article = row.vendor_code || row.nm_id || "Товар";
    if (row?.kind === "nomenclature") {
        return String(article);
    }

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
