function isPipeTableLine(rawLine) {
  const line = rawLine.trim();

  if (!line.startsWith("|") || !line.endsWith("|")) {
    return false;
  }

  const cellParts = line
    .slice(1, -1)
    .split("|")
    .map((part) => part.trim());

  return cellParts.length >= 2;
}

function splitPipeCells(rawLine) {
  return rawLine
    .trim()
    .slice(1, -1)
    .split("|")
    .map((cell) => cell.trim());
}

function pushCellTokens(state, tagOpen, tagClose, content, tokenType) {
  const openToken = state.push(tagOpen, tokenType, 1);
  openToken.markup = "|";

  const inlineToken = state.push("inline", "", 0);
  inlineToken.content = content;
  inlineToken.children = [];

  const closeToken = state.push(tagClose, tokenType, -1);
  closeToken.markup = "|";
}

export function markdownItLooseTable(md) {
  md.block.ruler.before(
    "table",
    "loose_pipe_table",
    (state, startLine, endLine, silent) => {
      const startPos = state.bMarks[startLine] + state.tShift[startLine];
      const maxPos = state.eMarks[startLine];

      if (startPos >= maxPos) {
        return false;
      }

      const firstLine = state.src.slice(startPos, maxPos);

      if (!isPipeTableLine(firstLine)) {
        return false;
      }

      let nextLine = startLine + 1;

      while (nextLine < endLine) {
        const lineStart = state.bMarks[nextLine] + state.tShift[nextLine];
        const lineEnd = state.eMarks[nextLine];
        const candidateLine = state.src.slice(lineStart, lineEnd);

        if (!isPipeTableLine(candidateLine)) {
          break;
        }

        nextLine += 1;
      }

      if (silent) {
        return true;
      }

      const rows = [];

      for (let line = startLine; line < nextLine; line += 1) {
        const lineStart = state.bMarks[line] + state.tShift[line];
        const lineEnd = state.eMarks[line];
        const rowText = state.src.slice(lineStart, lineEnd);
        rows.push(splitPipeCells(rowText));
      }

      if (!rows.length) {
        return false;
      }

      const tableOpen = state.push("table_open", "table", 1);
      tableOpen.map = [startLine, nextLine];

      state.push("thead_open", "thead", 1);
      state.push("tr_open", "tr", 1);

      rows[0].forEach((headerCell) => {
        pushCellTokens(state, "th_open", "th_close", headerCell, "th");
      });

      state.push("tr_close", "tr", -1);
      state.push("thead_close", "thead", -1);

      if (rows.length > 1) {
        state.push("tbody_open", "tbody", 1);

        for (let rowIndex = 1; rowIndex < rows.length; rowIndex += 1) {
          state.push("tr_open", "tr", 1);

          rows[rowIndex].forEach((cell) => {
            pushCellTokens(state, "td_open", "td_close", cell, "td");
          });

          state.push("tr_close", "tr", -1);
        }

        state.push("tbody_close", "tbody", -1);
      }

      state.push("table_close", "table", -1);
      state.line = nextLine;

      return true;
    },
  );
}
