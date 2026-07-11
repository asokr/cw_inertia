export const PROFITABILITY_JOB_STAGES = [
    {
        key: "queued",
        label: "В очереди",
        description: "Запрос принят, ожидаем запуск обработки",
    },
    {
        key: "preparing",
        label: "Подготовка",
        description: "Проверяем кабинет и данные себестоимости",
    },
    {
        key: "fetching",
        label: "Загрузка данных",
        description: "Получаем операции из Wildberries",
    },
    {
        key: "analyzing",
        label: "Обработка",
        description: "Группируем продажи, возвраты и логистику",
    },
    {
        key: "calculating",
        label: "Расчёт",
        description: "Считаем маржу и рентабельность",
    },
    {
        key: "saving",
        label: "Сохранение",
        description: "Записываем отчёт",
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
                ? "Ожидаем лимит API Wildberries (~1 мин). Для больших кабинетов это нормально — сервис продолжает работу."
                : null,
        };
    }

    const parts = [];

    if (jobStatus.batch) {
        parts.push(`Пакет ${jobStatus.batch}`);
    }

    const rowsText = formatRowsCount(jobStatus.rows_loaded);
    if (rowsText) {
        parts.push(`загружено ${rowsText} записей`);
    }

    const detail = parts.length ? parts.join(" • ") : null;
    const waitingHint = jobStatus.waiting_for_api
        ? "Ожидаем лимит API Wildberries (~1 мин). Для больших кабинетов это нормально — сервис продолжает работу."
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