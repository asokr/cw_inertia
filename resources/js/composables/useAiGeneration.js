function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? "";
}

export function extractAiMessage(payload, fallback = "Произошла ошибка") {
    if (Array.isArray(payload?.messages) && payload.messages.length) {
        return payload.messages[0];
    }

    if (typeof payload?.message === "string" && payload.message) {
        return payload.message;
    }

    return fallback;
}

export async function parseAiJsonResponse(response) {
    const payload = await response.json();

    if (!response.ok && payload?.success !== true) {
        const error = new Error(extractAiMessage(payload, "Произошла ошибка"));
        error.status = response.status;
        error.payload = payload;
        throw error;
    }

    return payload;
}

export async function aiFetch(url, options = {}) {
    const headers = {
        Accept: "application/json",
        "X-CSRF-TOKEN": getCsrfToken(),
        "X-Requested-With": "XMLHttpRequest",
        ...(options.headers ?? {}),
    };

    const response = await fetch(url, {
        credentials: "same-origin",
        ...options,
        headers,
    });

    return parseAiJsonResponse(response);
}

export function useAiGeneration() {
    return {
        aiFetch,
        extractAiMessage,
        parseAiJsonResponse,
    };
}