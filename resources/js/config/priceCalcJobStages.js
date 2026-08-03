export const PRICE_CALC_JOB_STAGES = [
    {
        key: "queued",
        label: "Скоро начнём",
        description: "Запрос принят, готовимся к работе",
    },
    {
        key: "importing",
        label: "Загружаем Excel",
        description: "Читаем файл и обновляем строки",
    },
    {
        key: "fetching",
        label: "Получаем данные",
        description: "Загружаем данные для расчёта",
    },
    {
        key: "calculating",
        label: "Считаем цены",
        description: "Считаем логистику и итоговые цены",
    },
    {
        key: "saving",
        label: "Сохраняем",
        description: "Записываем результат",
    },
];

export const PRICE_CALC_SYNC_STAGES = [
    {
        key: "queued",
        label: "Скоро начнём",
        description: "Запрос принят, готовимся к работе",
    },
    {
        key: "fetching",
        label: "Обновляем список",
        description: "Загружаем карточки товаров",
    },
    {
        key: "saving",
        label: "Сохраняем",
        description: "Записываем номенклатуру",
    },
];

/**
 * @param {object|null|undefined} jobStatus
 * @returns {{ detail: string|null, waitingHint: string|null }}
 */
export function buildPriceCalcProgressDetail(jobStatus = {}) {
    if (!jobStatus || jobStatus.status !== "processing") {
        return { detail: null, waitingHint: null };
    }

    if (jobStatus.status_detail) {
        return {
            detail: jobStatus.status_detail,
            waitingHint: null,
        };
    }

    return { detail: null, waitingHint: null };
}

export function resolvePriceCalcProgressPercent(jobStatus = {}) {
    if (typeof jobStatus.progress_percent === "number") {
        return Math.min(100, Math.max(0, jobStatus.progress_percent));
    }

    const stages =
        jobStatus.operation === "sync" ? PRICE_CALC_SYNC_STAGES : PRICE_CALC_JOB_STAGES;
    const stageIndex = stages.findIndex((stage) => stage.key === jobStatus.stage);
    if (stageIndex < 0) {
        return jobStatus.status === "processing" ? 8 : 0;
    }

    return Math.round(((stageIndex + 1) / stages.length) * 100);
}
