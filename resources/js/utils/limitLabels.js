/**
 * Подписи структурных лимитов тарифа.
 * Кабинеты WB сворачиваются в wb_cabinets; для Ozon остаётся только oz_cabinets.
 */

export const LEGACY_WB_CABINET_KEYS = ["feedbacks_clients", "price_calc_clients", "adverts_clients"];

/** Удалённые ключи Ozon — не показываем. */
export const DROPPED_OZ_CABINET_KEYS = ["oz_price_calc_clients", "oz_feedbacks_clients"];

export const limitLabels = {
    wb_cabinets: "Единый кабинет Wildberries",
    oz_cabinets: "Единый кабинет Ozon",
    repricer_nmid: "Номенклатуры в репрайсере",
};

/** Необязательная карта с сервера (SubscriberLimitLabels::all). */
let externalLabels = null;

export function setLimitLabels(map) {
    externalLabels = map && typeof map === "object" ? map : null;
}

export function formatLimitLabel(key, labelsMap = null) {
    if (LEGACY_WB_CABINET_KEYS.includes(key)) {
        return (labelsMap ?? externalLabels)?.wb_cabinets ?? limitLabels.wb_cabinets;
    }

    if (key === "oz_cabinets" || DROPPED_OZ_CABINET_KEYS.includes(key)) {
        return (labelsMap ?? externalLabels)?.oz_cabinets ?? limitLabels.oz_cabinets;
    }

    const fromMap = labelsMap?.[key] ?? externalLabels?.[key];
    if (typeof fromMap === "string" && fromMap !== "" && fromMap !== key) {
        return fromMap;
    }

    return limitLabels[key] ?? key;
}

/**
 * Сворачивает старые счётчики кабинетов WB; убирает удалённые ключи Ozon.
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

        if (DROPPED_OZ_CABINET_KEYS.includes(rawKey)) {
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
 * @param {Record<string, unknown>|null|undefined} tariff
 * @returns {Array<{ name: string, base: number, tariff: unknown }>}
 */
export function buildNormalizedLimitItems(base, tariff) {
    const normalizedBase = normalizePlanLimits(base);
    const normalizedTariff = normalizePlanLimits(tariff);

    const keys = Array.from(
        new Set([
            ...Object.keys(normalizedBase),
            ...Object.keys(normalizedTariff),
        ])
    );

    return keys.map((name) => ({
        name,
        base: Number(normalizedBase[name] ?? 0),
        tariff: normalizedTariff[name],
    }));
}

export const limitCategoryMeta = {
    wb: { label: "Wildberries", order: 1 },
    ozon: { label: "Ozon", order: 2 },
    other: { label: "Другое", order: 3 },
};

export function getLimitCategory(key) {
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
