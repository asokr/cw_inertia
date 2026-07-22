export const PROFITABILITY_JOB_STAGES = [
    {
        key: "queued",
        label: "Скоро начнём",
        description: "Запрос принят, готовимся к работе",
    },
    {
        key: "preparing",
        label: "Готовим данные",
        description: "Проверяем кабинет и себестоимость",
    },
    {
        key: "fetching",
        label: "Загружаем данные",
        description: "Получаем продажи и операции из Wildberries",
    },
    {
        key: "analyzing",
        label: "Разбираем операции",
        description: "Сортируем продажи, возвраты и логистику",
    },
    {
        key: "calculating",
        label: "Считаем прибыль",
        description: "Считаем маржу и рентабельность по товарам",
    },
    {
        key: "saving",
        label: "Сохраняем отчёт",
        description: "Записываем итог — почти готово",
    },
];

function formatRowsCount(value) {
    const count = Number(value ?? 0);
    if (!Number.isFinite(count) || count <= 0) {
        return null;
    }

    return count.toLocaleString("ru-RU");
}

/**
 * @param {object|null|undefined} jobStatus
 * @returns {{ detail: string|null, waitingHint: string|null }}
 */
export function buildProfitabilityProgressDetail(jobStatus = {}) {
    if (!jobStatus || jobStatus.status !== "processing") {
        return { detail: null, waitingHint: null };
    }

    if (jobStatus.status_detail) {
        return {
            detail: jobStatus.status_detail,
            waitingHint: jobStatus.waiting_for_api
                ? "Ждём ответ Wildberries — обычно около минуты. Для крупных кабинетов это нормально."
                : null,
        };
    }

    const rowsText = formatRowsCount(jobStatus.rows_loaded);
    const detail = rowsText ? `Уже загружено ${rowsText} операций` : null;
    const waitingHint = jobStatus.waiting_for_api
        ? "Ждём ответ Wildberries — обычно около минуты. Для крупных кабинетов это нормально."
        : null;

    return { detail, waitingHint };
}

export function resolveProfitabilityProgressPercent(jobStatus = {}) {
    if (typeof jobStatus.progress_percent === "number") {
        return Math.min(100, Math.max(0, jobStatus.progress_percent));
    }

    const stageIndex = PROFITABILITY_JOB_STAGES.findIndex((stage) => stage.key === jobStatus.stage);
    if (stageIndex < 0) {
        return jobStatus.status === "processing" ? 8 : 0;
    }

    return Math.round(((stageIndex + 1) / PROFITABILITY_JOB_STAGES.length) * 100);
}
