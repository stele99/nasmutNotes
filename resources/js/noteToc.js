const TABLE_OF_CONTENTS_LEVELS = new Set([1, 2]);

function nodeText(node) {
  if (!node || typeof node !== 'object') {
    return '';
  }
  if (typeof node.text === 'string') {
    return node.text;
  }
  if (node.type === 'hardBreak') {
    return ' ';
  }
  if (!Array.isArray(node.content)) {
    return '';
  }
  return node.content.map(nodeText).join('');
}

export function noteTableOfContents(document) {
  const headings = [];
  let headingIndex = 0;

  const visit = (node) => {
    if (!node || typeof node !== 'object') {
      return;
    }

    const level = node.type === 'heading' ? Number(node.attrs?.level) : null;
    if (TABLE_OF_CONTENTS_LEVELS.has(level)) {
      const index = headingIndex;
      headingIndex += 1;
      const text = nodeText(node).replace(/\s+/gu, ' ').trim();
      if (text !== '') {
        headings.push({ index, level, text });
      }
    }

    if (Array.isArray(node.content)) {
      node.content.forEach(visit);
    }
  };

  visit(document);
  return headings;
}
