export function formatNumberSafe(value) {
    const numericValue = Number(value);
    return Number.isNaN(numericValue) ? "—" : numericValue.toLocaleString("ru-RU");
}

export function formatPercentSafe(value) {
    const numericValue = Number(value);
    return Number.isNaN(numericValue) ? "—" : `${numericValue.toLocaleString("ru-RU")}%`;
}

export function formatNmid(value) {
    if (value === null || value === undefined || value === "") return "—";
    return String(value);
}

export function formatReviewListSafe(value) {
    if (!Array.isArray(value) || !value.length) return "—";

    const normalized = value
        .map((item) => {
            if (item === null || item === undefined) return "";
            if (typeof item === "string" || typeof item === "number") return String(item).trim();
            if (typeof item === "object") {
                const text = String(item.bable ?? item.label ?? item.name ?? item.title ?? item.text ?? "").trim();
                const count = Number(item.count);
                if (!text) return "";
                return !Number.isNaN(count) && count > 0 ? `${text} (${count.toLocaleString("ru-RU")})` : text;
            }
            return "";
        })
        .filter(Boolean);

    return normalized.length ? normalized.join(", ") : "—";
}

export function formatRatingDistributionSafe(value) {
    if (!value || typeof value !== "object" || Array.isArray(value)) return "—";

    return [1, 2, 3, 4, 5]
        .map((rating) => {
            const count = Number(value[rating] ?? value[String(rating)]);
            return Number.isNaN(count) ? `${rating}★: 0` : `${rating}★: ${count.toLocaleString("ru-RU")}`;
        })
        .join(", ");
}

export function formatRatingAverageSafe(value) {
    const numericValue = Number(value);
    if (Number.isNaN(numericValue)) return "—";
    return numericValue.toLocaleString("ru-RU", { minimumFractionDigits: 1, maximumFractionDigits: 2 });
}

export function formatTimeToReadySafe(funnel) {
    const timeToReady = funnel?.time_to_ready || funnel?.timeToReady;
    if (!timeToReady || typeof timeToReady !== "object") return "—";

    const days = Number(timeToReady.days);
    const hours = Number(timeToReady.hours);
    const mins = Number(timeToReady.mins);

    if (Number.isNaN(days) || Number.isNaN(hours) || Number.isNaN(mins)) return "—";

    return `${days.toLocaleString("ru-RU")} д. ${hours.toLocaleString("ru-RU")} ч. ${mins.toLocaleString("ru-RU")} мин.`;
}

export function getByPath(row, path) {
    return path.split(".").reduce((acc, key) => (acc == null ? acc : acc[key]), row);
}