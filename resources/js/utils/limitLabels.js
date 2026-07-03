export const limitLabels = {
    adverts_clients: "Кабинеты рекламы",
    feedbacks_clients: "Кабинеты отзывов wb",
    oz_feedbacks_clients: "Кабинеты отзывов ozon",
    price_calc_clients: "Кабинеты ценообразования",
    feedbacks_gpt_query: "Запросы к ИИ для отзывов",
    ai_text_query: "Текстовые запросы к ИИ",
    ai_image_query: "Генерация изображений ИИ",
    ai_video_query: "Генерация видео ИИ",
    repricer_nmid: "Кол-во номенклатур в репрайсере",
};

export function formatLimitLabel(key) {
    return limitLabels[key] ?? key;
}