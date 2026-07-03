export function formatRub(value) {
    const numeric = Number(value);
    if (Number.isNaN(numeric)) return "—";
    return `${numeric.toLocaleString("ru-RU")} ₽`;
}

export function formatPercent(value) {
    const numeric = Number(value);
    if (Number.isNaN(numeric)) return "—";
    return `${numeric.toLocaleString("ru-RU")} %`;
}

export function formatCount(value, suffix = "шт.") {
    const numeric = Number(value);
    if (Number.isNaN(numeric)) return "—";
    return `${numeric.toLocaleString("ru-RU")} ${suffix}`;
}

export function rowProfitClass(profit) {
    const numeric = Number(profit);
    if (Number.isNaN(numeric)) return "";
    if (numeric < 0) return "bg-[#f99790a1]";
    if (numeric === 0) return "bg-[#efe46f8f]";
    return "bg-[#7dd9817d]";
}