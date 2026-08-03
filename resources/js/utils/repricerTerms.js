export function normalizeTerms(terms) {
    if (!terms) return [];
    if (Array.isArray(terms)) return terms;
    // Legacy: один объект { start, end, value } из промокалькулятора
    if (typeof terms === "object" && terms.start != null && terms.end != null) {
        return [terms];
    }
    return [];
}

/** Ежедневное окно "HH:mm" */
export function isDailyTime(value) {
    return typeof value === "string" && /^\d{2}:\d{2}$/.test(value.trim());
}

/** Разовый период с датой */
export function isAbsoluteDateTime(value) {
    return (
        typeof value === "string" &&
        /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/.test(value.trim())
    );
}

export function isDateTimePeriod(period) {
    if (!period) return false;
    return isAbsoluteDateTime(period.start) || isAbsoluteDateTime(period.end);
}

/** Значение для input type=time | datetime-local */
export function toPeriodInputValue(value, mode) {
    if (!value) return "";
    const raw = String(value).trim();
    if (mode === "daily") {
        if (isDailyTime(raw)) return raw;
        // datetime → только время
        const match = raw.match(/(\d{2}:\d{2})/);
        return match ? match[1] : "";
    }
    // absolute → YYYY-MM-DDTHH:mm
    if (isDailyTime(raw)) {
        return "";
    }
    return raw.replace(" ", "T").slice(0, 16);
}

/** Из input → каноническое значение для API */
export function fromPeriodInputValue(value, mode) {
    if (!value) return "";
    const raw = String(value).trim();
    if (mode === "daily") {
        return raw.slice(0, 5);
    }
    // datetime-local: 2026-08-01T10:00 → 2026-08-01 10:00:00
    const normalized = raw.replace("T", " ");
    if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(normalized)) {
        return `${normalized}:00`;
    }
    return normalized;
}

export function formatPeriodBoundary(value) {
    if (!value) return "—";
    const raw = String(value).trim().replace("T", " ");
    if (isDailyTime(raw)) return raw;
    // "2026-08-01 10:00:00" → "2026-08-01 10:00"
    if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(raw)) {
        return raw.slice(0, 16);
    }
    return raw;
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
