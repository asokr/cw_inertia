import MarkdownIt from "markdown-it";
import markdownItImageFigures from "markdown-it-image-figures";
import markdownItSub from "markdown-it-sub";
import markdownItSup from "markdown-it-sup";
import markdownItTaskLists from "markdown-it-task-lists";
import { markdownItLooseTable } from "@/utils/markdownItLooseTable";
import { toFrontendMediaUrl } from "@/utils/blogMedia";

function markdownItStrikethrough(md) {
  md.inline.ruler.before("emphasis", "strikethrough", (state, silent) => {
    const start = state.pos;
    const max = state.posMax;

    if (state.src.charCodeAt(start) !== 0x7e || start + 1 >= max) {
      return false;
    }

    if (state.src.charCodeAt(start + 1) !== 0x7e) {
      return false;
    }

    let matched = false;
    let contentEnd = start + 2;

    while (contentEnd < max) {
      if (
        state.src.charCodeAt(contentEnd) === 0x7e &&
        contentEnd + 1 < max &&
        state.src.charCodeAt(contentEnd + 1) === 0x7e &&
        (contentEnd === start + 2 ||
          state.src.charCodeAt(contentEnd - 1) !== 0x20)
      ) {
        matched = true;
        break;
      }

      contentEnd += 1;
    }

    if (!matched || contentEnd === start + 2) {
      return false;
    }

    if (silent) {
      return true;
    }

    const openToken = state.push("s_open", "s", 1);
    openToken.markup = "~~";

    const textToken = state.push("text", "", 0);
    textToken.content = state.src.slice(start + 2, contentEnd);

    const closeToken = state.push("s_close", "s", -1);
    closeToken.markup = "~~";

    state.pos = contentEnd + 2;

    return true;
  });
}

const md = new MarkdownIt({
  html: true,
  linkify: true,
  typographer: false,
  breaks: true,
});

md.use(markdownItSub);
md.use(markdownItSup);
md.use(markdownItLooseTable);
md.use(markdownItImageFigures, { figcaption: true });
md.use(markdownItTaskLists, { enabled: true, label: true, labelAfter: false });
md.use(markdownItStrikethrough);

const defaultLinkRender =
  md.renderer.rules.link_open ||
  function (tokens, idx, options, env, self) {
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

const defaultImageRender =
  md.renderer.rules.image ||
  function (tokens, idx, options, env, self) {
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

function rewriteMarkdownImageUrls(content) {
  return content.replace(
    /!\[([^\]]*)]\(([^)\s]+)(?:\s+"[^"]*")?\)/g,
    (match, alt, src) => `![${alt}](${toFrontendMediaUrl(src)})`,
  );
}

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

  const normalizedContent = rewriteMarkdownImageUrls(String(content));
  let html = md.render(normalizedContent);

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
