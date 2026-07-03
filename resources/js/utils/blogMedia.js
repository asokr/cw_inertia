export function toFrontendMediaUrl(rawSrc) {
    if (!rawSrc) {
        return "";
    }

    if (rawSrc.startsWith("/media/") || rawSrc.startsWith("data:")) {
        return rawSrc;
    }

    let normalized = rawSrc;

    if (rawSrc.startsWith("http://") || rawSrc.startsWith("https://")) {
        try {
            normalized = new URL(rawSrc).pathname || "";
        } catch {
            return rawSrc;
        }
    }

    const cleanPath = normalized.replace(/^\/+/, "");

    if (cleanPath.startsWith("storage/blog/images/")) {
        return `/media/${cleanPath.replace(/^storage\//, "")}`;
    }

    if (cleanPath.startsWith("media/blog/images/")) {
        return `/${cleanPath}`;
    }

    if (cleanPath.startsWith("blog/images/")) {
        return `/media/${cleanPath}`;
    }

    return rawSrc;
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