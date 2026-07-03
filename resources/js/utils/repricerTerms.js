export function normalizeTerms(terms) {
    if (!terms) return [];
    if (Array.isArray(terms)) return terms;
    return [];
}

export function formatTermValue(item, value) {
    const num = Number(value);
    if (!Number.isFinite(num)) return "—";

    if (item?.pricing_modifier_type === "PROCENT") {
        const base = item?.price_type === "DISCOUNT" ? "скидки" : "цены";
        return `${num}% от ${base}`;
    }

    return item?.price_type === "DISCOUNT" ? `${num}%` : `${num} ₽`;
}

export function priceTypeLabel(value) {
    return value === "DISCOUNT" ? "Скидку" : "Цену";
}

export function modifierLabel(item) {
    if (item?.pricing_modifier_type === "PROCENT") {
        const base = item?.price_type === "DISCOUNT" ? "скидки" : "цены";
        return `Процент от ${base}`;
    }

    return "Фиксированное значение";
}