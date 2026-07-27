/**
 * Structural labels for cabinet / plan limits.
 * Purchasable monthly names come from DB (extra_limits.name) via server props or catalog.
 * WB tools share a unified cabinet count (wb_cabinets); legacy per-tool keys collapse in UI.
 */

export const LEGACY_WB_CABINET_KEYS = ["feedbacks_clients", "price_calc_clients", "adverts_clients"];

export const limitLabels = {
    wb_cabinets: "Единый кабинет Wildberries",
    oz_feedbacks_clients: "Кабинеты отзывов Ozon",
    oz_price_calc_clients: "Кабинеты ценообразования Ozon",
    repricer_nmid: "Номенклатуры в репрайсере",
    // Soft fallbacks if server/catalog map is missing
    feedbacks_gpt_query: "Запросы к ИИ для отзывов",
    ai_text_query: "Текстовые запросы к ИИ",
    ai_image_query: "Генерация изображений ИИ",
    ai_video_query: "Генерация видео ИИ",
};

/** Optional runtime map from Inertia (SubscriberLimitLabels::all / catalog). */
let externalLabels = null;

export function setLimitLabels(map) {
    externalLabels = map && typeof map === "object" ? map : null;
}

export function formatLimitLabel(key, labelsMap = null) {
    if (LEGACY_WB_CABINET_KEYS.includes(key)) {
        return (labelsMap ?? externalLabels)?.wb_cabinets ?? limitLabels.wb_cabinets;
    }

    const fromMap = labelsMap?.[key] ?? externalLabels?.[key];
    if (typeof fromMap === "string" && fromMap !== "") {
        return fromMap;
    }

    return limitLabels[key] ?? key;
}

/**
 * Collapse legacy per-tool WB cabinet counters into a single wb_cabinets entry.
 * @param {Record<string, unknown>|null|undefined} limits
 * @returns {Record<string, number|string>}
 */
export function normalizePlanLimits(limits) {
    if (!limits || typeof limits !== "object") {
        return {};
    }

    const out = {};
    const legacyValues = [];

    for (const [rawKey, value] of Object.entries(limits)) {
        if (value === null || value === undefined || value === "") {
            continue;
        }

        if (LEGACY_WB_CABINET_KEYS.includes(rawKey)) {
            legacyValues.push(Number(value) || 0);
            continue;
        }

        out[rawKey] = value;
    }

    if (!Object.prototype.hasOwnProperty.call(out, "wb_cabinets") && legacyValues.length) {
        out.wb_cabinets = Math.max(...legacyValues);
    }

    for (const legacyKey of LEGACY_WB_CABINET_KEYS) {
        delete out[legacyKey];
    }

    return out;
}

/**
 * @param {Record<string, unknown>|null|undefined} base
 * @param {Record<string, unknown>|null|undefined} extra
 * @param {Record<string, unknown>|null|undefined} tariff
 * @returns {Array<{ name: string, base: number, extra: number, tariff: unknown }>}
 */
export function buildNormalizedLimitItems(base, extra, tariff) {
    const normalizedBase = normalizePlanLimits(base);
    const normalizedExtra = normalizePlanLimits(extra);
    const normalizedTariff = normalizePlanLimits(tariff);

    const keys = Array.from(
        new Set([
            ...Object.keys(normalizedBase),
            ...Object.keys(normalizedExtra),
            ...Object.keys(normalizedTariff),
        ])
    );

    return keys.map((name) => ({
        name,
        base: Number(normalizedBase[name] ?? 0),
        extra: Number(normalizedExtra[name] ?? 0),
        tariff: normalizedTariff[name],
    }));
}

export const limitCategoryMeta = {
    ai: { label: "Искусственный интеллект", order: 1 },
    wb: { label: "Wildberries", order: 2 },
    ozon: { label: "Ozon", order: 3 },
    other: { label: "Другое", order: 4 },
};

export function getLimitCategory(key) {
    if (key.startsWith("ai_") || key === "feedbacks_gpt_query") {
        return "ai";
    }

    if (key.startsWith("oz_")) {
        return "ozon";
    }

    if (
        key === "wb_cabinets" ||
        key.includes("feedbacks") ||
        key.includes("price_calc") ||
        key.includes("repricer") ||
        key.includes("adverts")
    ) {
        return "wb";
    }

    return "other";
}
