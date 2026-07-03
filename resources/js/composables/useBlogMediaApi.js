function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]')?.content;
    if (meta) {
        return meta;
    }

    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : "";
}

function extractMessage(payload, fallback = "Произошла ошибка") {
    if (Array.isArray(payload?.messages) && payload.messages.length) {
        return payload.messages.join(" ");
    }

    return fallback;
}

export function useBlogMediaApi() {
    async function uploadImage(file) {
        const formData = new FormData();
        formData.append("image", file);

        const response = await fetch("/cw-page/blog/upload-image", {
            method: "POST",
            headers: {
                Accept: "application/json",
                "X-CSRF-TOKEN": getCsrfToken(),
                "X-Requested-With": "XMLHttpRequest",
            },
            credentials: "same-origin",
            body: formData,
        });

        const payload = await response.json();

        if (!response.ok || !payload?.success) {
            return {
                success: false,
                messages: [extractMessage(payload)],
                errors: payload?.errors ?? {},
            };
        }

        return {
            success: true,
            data: payload.data,
            messages: payload.messages ?? ["Изображение загружено"],
        };
    }

    return { uploadImage };
}