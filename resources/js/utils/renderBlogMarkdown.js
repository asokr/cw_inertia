import MarkdownIt from "markdown-it";
import { toFrontendMediaUrl } from "@/utils/blogMedia";

const md = new MarkdownIt({
    html: true,
    linkify: true,
    typographer: true,
});

const defaultRender = md.renderer.rules.link_open || function (tokens, idx, options, env, self) {
    return self.renderToken(tokens, idx, options);
};

md.renderer.rules.link_open = function (tokens, idx, options, env, self) {
    const href = tokens[idx].attrGet("href");

    if (href && (href.startsWith("http://") || href.startsWith("https://"))) {
        tokens[idx].attrSet("target", "_blank");
        tokens[idx].attrSet("rel", "noopener noreferrer");
    }

    return defaultRender(tokens, idx, options, env, self);
};

export function renderBlogMarkdown(content) {
    if (!content) {
        return "";
    }

    let html = md.render(content);

    html = html.replace(
        /<img\s([^>]*)src="([^"]+)"/g,
        (match, before, src) => {
            const rewrittenSrc = toFrontendMediaUrl(src);

            if (rewrittenSrc === src) {
                return match;
            }

            return `<img ${before}src="${rewrittenSrc}"`;
        },
    );

    return html;
}

export function formatBlogDate(dateStr) {
    if (!dateStr) {
        return "";
    }

    const date = new Date(dateStr);

    return date.toLocaleDateString("ru-RU", {
        day: "numeric",
        month: "long",
        year: "numeric",
    });
}