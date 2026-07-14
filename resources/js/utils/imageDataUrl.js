import { toAiMediaUrl } from "@/composables/useAiMediaUrl";

const PANEL_MEDIA_PREFIX = "/panel/ai/media/";

export function resolveImageForForm(url) {
    if (!url || typeof url !== "string") {
        return "";
    }

    if (url.startsWith("data:image/")) {
        return url;
    }

    const normalized = toAiMediaUrl(url, { allowDataUrl: true });
    if (normalized) {
        return normalized;
    }

    const trimmed = url.trim();
    if (trimmed.startsWith("http://") || trimmed.startsWith("https://")) {
        return trimmed;
    }

    return "";
}

export function canUseImageWithoutFetch(url) {
    const resolved = resolveImageForForm(url);

    return Boolean(
        resolved
        && (
            resolved.startsWith(PANEL_MEDIA_PREFIX)
            || resolved.startsWith("data:image/")
            || resolved.startsWith("http://")
            || resolved.startsWith("https://")
        ),
    );
}

function buildFetchUrl(url) {
    const resolved = resolveImageForForm(url) || url;

    if (resolved.startsWith("http://") || resolved.startsWith("https://")) {
        return resolved;
    }

    if (resolved.startsWith("/")) {
        return `${window.location.origin}${resolved}`;
    }

    return resolved;
}

export async function urlToDataUrl(url) {
    if (!url || typeof url !== "string") {
        return "";
    }

    if (url.startsWith("data:image/")) {
        return url;
    }

    if (canUseImageWithoutFetch(url) && resolveImageForForm(url).startsWith(PANEL_MEDIA_PREFIX)) {
        return resolveImageForForm(url);
    }

    const fetchUrl = buildFetchUrl(url);
    const response = await fetch(fetchUrl, {
        credentials: "same-origin",
    });

    if (!response.ok) {
        throw new Error("Не удалось загрузить изображение");
    }

    const blob = await response.blob();

    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(typeof reader.result === "string" ? reader.result : "");
        reader.onerror = () => reject(new Error("Не удалось прочитать изображение"));
        reader.readAsDataURL(blob);
    });
}