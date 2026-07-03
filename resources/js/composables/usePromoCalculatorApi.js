function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? "";
}

function extractMessage(payload, fallback = "Произошла ошибка") {
    if (Array.isArray(payload?.messages) && payload.messages.length) {
        return payload.messages.join(" ");
    }

    if (typeof payload?.message === "string" && payload.message) {
        return payload.message;
    }

    return fallback;
}

async function parseJsonResponse(response) {
    const payload = await response.json();

    if (!response.ok && !payload?.success) {
        throw new Error(extractMessage(payload, "Произошла ошибка"));
    }

    return payload;
}

export function usePromoCalculatorApi() {
    async function uploadFile(file) {
        const formData = new FormData();
        formData.append("file", file);

        const response = await fetch("/panel/wb/promocalculator/upload", {
            method: "POST",
            headers: {
                Accept: "application/json",
                "X-CSRF-TOKEN": getCsrfToken(),
                "X-Requested-With": "XMLHttpRequest",
            },
            credentials: "same-origin",
            body: formData,
        });

        const payload = await parseJsonResponse(response);

        if (!payload?.success) {
            throw new Error(extractMessage(payload, "Не удалось загрузить файл"));
        }

        return payload.data?.file ?? null;
    }

    async function calculate({ file, cabinetId }) {
        const response = await fetch("/panel/wb/promocalculator/calculate", {
            method: "POST",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": getCsrfToken(),
                "X-Requested-With": "XMLHttpRequest",
            },
            credentials: "same-origin",
            body: JSON.stringify({
                file,
                cabinet_id: cabinetId,
            }),
        });

        const payload = await parseJsonResponse(response);

        if (!payload?.success) {
            throw new Error(extractMessage(payload, "Не удалось выполнить расчёт"));
        }

        return payload.data ?? [];
    }

    async function exportResults(data) {
        const response = await fetch("/panel/wb/promocalculator/export", {
            method: "POST",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": getCsrfToken(),
                "X-Requested-With": "XMLHttpRequest",
            },
            credentials: "same-origin",
            body: JSON.stringify({ data }),
        });

        const payload = await parseJsonResponse(response);

        if (!payload?.success) {
            throw new Error(extractMessage(payload, "Не удалось сформировать отчёт"));
        }

        return payload.data ?? null;
    }

    async function sendToRepricer({ data, dates, cabinetId }) {
        const response = await fetch("/panel/wb/promocalculator/repricer", {
            method: "POST",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": getCsrfToken(),
                "X-Requested-With": "XMLHttpRequest",
            },
            credentials: "same-origin",
            body: JSON.stringify({
                data,
                dates,
                cabinet_id: cabinetId,
            }),
        });

        const payload = await parseJsonResponse(response);

        if (!payload?.success) {
            const error = new Error(extractMessage(payload, "Не удалось отправить в репрайсер"));
            error.type = payload?.type ?? null;
            throw error;
        }

        return payload;
    }

    return {
        uploadFile,
        calculate,
        exportResults,
        sendToRepricer,
    };
}