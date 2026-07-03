import { formatNumberSafe } from "@/utils/aiCabinetAnalyzerFormatters";

const metricsLabelMap = {
    total_campaigns: "Всего рекламных кампаний",
    active_spending_campaigns: "Активных кампаний (с показами)",
    total_ad_spend_rub: "Общий расход на рекламу (₽)",
    total_ad_orders: "Заказы с рекламы",
    top_sku_cpc_rub: "CPC лучшего SKU",
    top_sku_ctr_percent: "CTR лучшего SKU",
};

export function formatAnalysisDateTime(value) {
    if (!value) return "—";
    return new Date(value).toLocaleString("ru-RU", {
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
    });
}

export function analysisStatusLabel(status) {
    if (status === "done") return "готов";
    if (status === "failed") return "ошибка";
    if (status === "processing") return "обработка";
    return "неизвестно";
}

export function analysisStatusVariant(status) {
    if (status === "done") return "success";
    if (status === "failed") return "destructive";
    if (status === "processing") return "default";
    return "default";
}

export function canRegenerateAnalysis(item) {
    return item?.status === "done" || item?.status === "failed";
}

function tryParseJsonText(value) {
    if (typeof value !== "string") return null;
    const text = value.trim();
    if (!text) return null;

    const fencedMatch = text.match(/```(?:json)?\s*([\s\S]*?)\s*```/i);
    const candidate = fencedMatch?.[1] ? fencedMatch[1].trim() : text;

    try {
        return JSON.parse(candidate);
    } catch {
        return null;
    }
}

export function parseStructuredAnalysis(analysis) {
    const payload = analysis?.analysis_text;
    if (!payload) return null;

    if (typeof payload === "object" && !Array.isArray(payload)) {
        return payload.raw_text ? null : payload;
    }

    const parsed = tryParseJsonText(payload);
    if (parsed && typeof parsed === "object" && !Array.isArray(parsed)) {
        return parsed.raw_text ? null : parsed;
    }

    return null;
}

export function parseRawAnalysisText(analysis) {
    const payload = analysis?.analysis_text;
    if (!payload) return "";
    if (typeof payload === "string") return payload;
    if (typeof payload === "object" && payload.raw_text) return String(payload.raw_text);
    if (typeof payload === "object" && payload.raw) return String(payload.raw);
    return "";
}

export function isMarkdownAnalysis(analysis) {
    return analysis?.response_format === "markdown";
}

export function getMarkdownSource(analysis) {
    const src = analysis?.analysis_markdown;
    if (typeof src !== "string") return "";
    return src.trim();
}

function formatMetricValue(key, value) {
    const numericValue = Number(value);
    if (Number.isNaN(numericValue)) return String(value ?? "—");
    if (key.includes("_rub")) return `${numericValue.toLocaleString("ru-RU")} ₽`;
    if (key.includes("_percent")) return `${numericValue.toLocaleString("ru-RU")}%`;
    return numericValue.toLocaleString("ru-RU");
}

export function buildMetricsRows(structuredAnalysis) {
    const metrics = structuredAnalysis?.metrics;
    if (!metrics || typeof metrics !== "object") return [];

    if (Array.isArray(metrics)) {
        return metrics.map((item, index) => {
            const key = item?.key || item?.code || `metric_${index}`;
            const rawValue = item?.value ?? item?.metric_value ?? item?.amount ?? item?.raw ?? "—";
            return {
                key,
                label: item?.label || metricsLabelMap[key] || key,
                value: formatMetricValue(String(key), rawValue),
            };
        });
    }

    return Object.entries(metrics).map(([key, value]) => {
        const isObj = value && typeof value === "object" && !Array.isArray(value);
        const metricLabel = isObj ? value.label : null;
        const metricValue = isObj
            ? (value.value ?? value.metric_value ?? value.amount ?? value.raw ?? "—")
            : value;

        return {
            key,
            label: metricLabel || metricsLabelMap[key] || key,
            value: formatMetricValue(key, metricValue),
        };
    });
}

export function normalizeAnalysisRows(value) {
    return Array.isArray(value) ? value : [];
}

export function priorityVariant(priority) {
    const normalized = String(priority || "").toLowerCase();
    if (normalized.includes("выс")) return "destructive";
    if (normalized.includes("сред")) return "default";
    if (normalized.includes("низ")) return "secondary";
    return "outline";
}

export function formatTokenCount(value) {
    return formatNumberSafe(value);
}