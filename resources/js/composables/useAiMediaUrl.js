const AI_STORAGE_PREFIX = "ai/";
const VIDEO_RELATIVE_PREFIX = "generated-videos/";
const IMAGE_RELATIVE_PREFIX = "source-images/";

const BACKEND_MEDIA_PROXY_PREFIX = "api/subscriber/ai/media/";
const WEB_MEDIA_PREFIX = "/panel/ai/media/";

function isAiMediaRelativePath(path) {
    const normalized = String(path || "").replace(/^\/+/, "").replace(/^ai\//, "");

    return (
        normalized.startsWith(VIDEO_RELATIVE_PREFIX) || normalized.startsWith(IMAGE_RELATIVE_PREFIX)
    );
}

function toWebMediaPath(path) {
    const normalized = String(path || "").replace(/^\/+/, "");

    if (!isAiMediaRelativePath(normalized)) {
        return "";
    }

    return normalized.replace(/^ai\//, "");
}

function buildPanelMediaUrl(pathPart) {
    const webPath = toWebMediaPath(pathPart);
    return webPath ? `${WEB_MEDIA_PREFIX}${webPath}` : "";
}

export function toAiMediaUrl(rawSrc, options = {}) {
    const { allowDataUrl = false } = options;

    if (!rawSrc || typeof rawSrc !== "string") {
        return "";
    }

    if (rawSrc.startsWith(WEB_MEDIA_PREFIX)) {
        const pathPart = rawSrc.slice(WEB_MEDIA_PREFIX.length).split("?")[0];
        return buildPanelMediaUrl(pathPart);
    }

    if (rawSrc.startsWith("/media/")) {
        const pathPart = rawSrc.replace(/^\/media\//, "").split("?")[0];
        return buildPanelMediaUrl(pathPart);
    }

    if (rawSrc.startsWith("data:")) {
        return allowDataUrl ? rawSrc : "";
    }

    if (rawSrc.startsWith("http://") || rawSrc.startsWith("https://")) {
        try {
            const parsed = new URL(rawSrc);
            if (parsed.pathname.startsWith(WEB_MEDIA_PREFIX)) {
                const pathPart = parsed.pathname.slice(WEB_MEDIA_PREFIX.length);
                const normalized = buildPanelMediaUrl(pathPart);
                if (normalized) {
                    return normalized;
                }
            }

            return rawSrc;
        } catch {
            return "";
        }
    }

    const cleanPath = rawSrc.replace(/^\/+/, "").split("?")[0];

    if (cleanPath.startsWith(BACKEND_MEDIA_PROXY_PREFIX)) {
        const encodedPath = cleanPath.slice(BACKEND_MEDIA_PROXY_PREFIX.length);
        try {
            const decodedPath = decodeURIComponent(encodedPath).replace(/^\/+/, "");
            const webPath = toWebMediaPath(decodedPath);
            if (webPath) {
                return `${WEB_MEDIA_PREFIX}${webPath}`;
            }
        } catch {
            return "";
        }
    }

    const storageCandidates = [
        cleanPath,
        cleanPath.replace(/^storage\//, ""),
        cleanPath.replace(/^media\//, ""),
    ];

    for (const candidate of storageCandidates) {
        const webPath = toWebMediaPath(candidate);
        if (webPath) {
            return `${WEB_MEDIA_PREFIX}${webPath}`;
        }
    }

    return "";
}

export function normalizeVideoItem(video) {
    if (!video || typeof video !== "object") {
        return null;
    }

    const source = video.url || video.url_preview || video.signed_url || video.path || "";
    const normalizedUrl = toAiMediaUrl(source);

    if (!normalizedUrl) {
        return null;
    }

    return {
        ...video,
        url: normalizedUrl,
        url_preview: normalizedUrl,
        signed_url: normalizedUrl,
    };
}

export function mapAiImageItem(item) {
    if (!item) {
        return "";
    }

    if (typeof item === "string") {
        return toAiMediaUrl(item, { allowDataUrl: true });
    }

    if (typeof item === "object") {
        const source = item.url_preview || item.signed_url || item.url || item.path || "";
        return toAiMediaUrl(source);
    }

    return "";
}

export function useAiMediaUrl() {
    return {
        toAiMediaUrl,
        mapAiImageItem,
        normalizeVideoItem,
    };
}