export function toFrontendMediaUrl(rawSrc) {
    if (!rawSrc) {
        return "";
    }

    const trimmed = String(rawSrc).trim();
    if (!trimmed) {
        return "";
    }

    if (trimmed.startsWith("/media/") || trimmed.startsWith("data:")) {
        return trimmed;
    }

    let normalized = trimmed;
    let suffix = "";

    const queryIndex = normalized.indexOf("?");
    const hashIndex = normalized.indexOf("#");
    const cutIndex = queryIndex >= 0
        ? queryIndex
        : (hashIndex >= 0 ? hashIndex : -1);

    if (cutIndex >= 0) {
        suffix = normalized.slice(cutIndex);
        normalized = normalized.slice(0, cutIndex);
    }

    if (
        normalized.startsWith("http://")
        || normalized.startsWith("https://")
        || normalized.startsWith("//")
    ) {
        try {
            const url = normalized.startsWith("//") ? `https:${normalized}` : normalized;
            normalized = new URL(url).pathname || "";
        } catch {
            return trimmed;
        }
    }

    const cleanPath = normalized.replace(/^\/+/, "");

    if (cleanPath.startsWith("storage/blog/images/")) {
        return `/media/${cleanPath.replace(/^storage\//, "")}${suffix}`;
    }

    if (cleanPath.startsWith("media/blog/images/")) {
        return `/${cleanPath}${suffix}`;
    }

    if (cleanPath.startsWith("blog/images/")) {
        return `/media/${cleanPath}${suffix}`;
    }

    return trimmed;
}

export function getPostCoverUrl(post) {
    if (post?.cover_image_url) {
        return toFrontendMediaUrl(post.cover_image_url);
    }

    if (post?.cover_image) {
        return `/media/${post.cover_image}`;
    }

    return "";
}