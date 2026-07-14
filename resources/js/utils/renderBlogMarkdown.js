import MarkdownIt from "markdown-it";
import { toFrontendMediaUrl } from "@/utils/blogMedia";

const md = new MarkdownIt({
    html: true,
    linkify: true,
    typographer: true,
    breaks: true,
});

const defaultLinkRender = md.renderer.rules.link_open || function (tokens, idx, options, env, self) {
    return self.renderToken(tokens, idx, options);
};

md.renderer.rules.link_open = function (tokens, idx, options, env, self) {
    const href = tokens[idx].attrGet("href");

    if (href && (href.startsWith("http://") || href.startsWith("https://"))) {
        tokens[idx].attrSet("target", "_blank");
        tokens[idx].attrSet("rel", "noopener noreferrer");
    }

    return defaultLinkRender(tokens, idx, options, env, self);
};

const defaultImageRender = md.renderer.rules.image || function (tokens, idx, options, env, self) {
    return self.renderToken(tokens, idx, options);
};

md.renderer.rules.image = function (tokens, idx, options, env, self) {
    const token = tokens[idx];
    const src = token.attrGet("src");

    if (src) {
        token.attrSet("src", toFrontendMediaUrl(src));
    }

    token.attrSet("loading", "lazy");
    token.attrSet("decoding", "async");

    return defaultImageRender(tokens, idx, options, env, self);
};

function rewriteHtmlImageSources(html) {
    return html.replace(
        /<img\b([^>]*?)\bsrc=(["'])(.*?)\2/gi,
        (match, before, quote, src) => {
            const rewrittenSrc = toFrontendMediaUrl(src);

            if (rewrittenSrc === src) {
                return match;
            }

            return `<img${before}src=${quote}${rewrittenSrc}${quote}`;
        },
    );
}

export function renderBlogMarkdown(content) {
    if (!content) {
        return "";
    }

    let html = md.render(content);

    return rewriteHtmlImageSources(html);
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