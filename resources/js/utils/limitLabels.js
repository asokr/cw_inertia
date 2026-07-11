export const limitLabels = {
    adverts_clients: "Кабинеты рекламы",
    feedbacks_clients: "Кабинеты отзывов wb",
    oz_feedbacks_clients: "Кабинеты отзывов ozon",
    price_calc_clients: "Кабинеты ценообразования WB",
    oz_price_calc_clients: "Кабинеты ценообразования Ozon",
    feedbacks_gpt_query: "Запросы к ИИ для отзывов",
    ai_text_query: "Текстовые запросы к ИИ",
    ai_image_query: "Генерация изображений ИИ",
    ai_video_query: "Генерация видео ИИ",
    repricer_nmid: "Кол-во номенклатур в репрайсере",
};

export const limitCategoryMeta = {
    ai: { label: "Искусственный интеллект", order: 1 },
    wb: { label: "Wildberries", order: 2 },
    ozon: { label: "Ozon", order: 3 },
    other: { label: "Другое", order: 4 },
};

export function formatLimitLabel(key) {
    return limitLabels[key] ?? key;
}

export function getLimitCategory(key) {
    if (key.startsWith("ai_") || key === "feedbacks_gpt_query") {
        return "ai";
    }

    if (key.startsWith("oz_")) {
        return "ozon";
    }

    if (
        key.includes("feedbacks") ||
        key.includes("price_calc") ||
        key.includes("repricer") ||
        key.includes("adverts")
    ) {
        return "wb";
    }

    return "other";
}