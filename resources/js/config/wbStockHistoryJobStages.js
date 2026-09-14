export const WB_STOCK_HISTORY_STAGES = [
    {
        key: "queued",
        label: "Скоро начнём",
        description: "Запрос принят, готовимся к работе",
    },
    {
        key: "products",
        label: "Собираем товары",
        description: "Готовим список товаров",
    },
    {
        key: "history",
        label: "Собираем историю",
        description: "Собираем остатки по дням",
    },
    {
        key: "warehouses",
        label: "Уточняем остатки",
        description: "Обновляем текущие остатки",
    },
    {
        key: "saving",
        label: "Сохраняем данные",
        description: "Записываем результат",
    },
];

export const WB_ORDER_HISTORY_STAGES = [
    {
        key: "queued",
        label: "Скоро начнём",
        description: "Запрос принят, готовимся к работе",
    },
    {
        key: "history",
        label: "Собираем заказы",
        description: "Собираем заказы по дням",
    },
    {
        key: "saving",
        label: "Сохраняем данные",
        description: "Записываем результат",
    },
];

export function resolveHistoryProgressPercent(job = {}) {
    if (typeof job.progress_percent === "number") {
        return Math.min(100, Math.max(0, job.progress_percent));
    }
    if (job.status === "done") {
        return 100;
    }

    return job.status === "processing" ? 8 : 0;
}
