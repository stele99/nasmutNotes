function inlineText(node) {
  if (!node || typeof node !== 'object') return '';
  if (node.type === 'text') return String(node.text || '');
  if (node.type === 'hardBreak') return '\n';
  if (node.type === 'image') return `[Bild: ${node.attrs?.alt || node.attrs?.title || 'ohne Beschreibung'}]`;
  return Array.isArray(node.content) ? node.content.map(inlineText).join('') : '';
}

function signature(kind, node, extra = '') {
  return `${kind}:${extra}:${JSON.stringify(node)}`;
}

function collectList(node, blocks, depth, ordered, task) {
  (node.content || []).forEach((item, index) => {
    const checked = task ? Boolean(item.attrs?.checked) : null;
    const prefix = task ? (checked ? '☑' : '☐') : (ordered ? `${index + 1}.` : '•');
    const ownContent = (item.content || []).filter(
      (child) => !['bulletList', 'orderedList', 'taskList'].includes(child.type),
    );
    const text = ownContent.map(inlineText).join('').trim() || '(leerer Eintrag)';
    const ownItem = { ...item, content: ownContent };
    blocks.push({
      kind: task ? 'task' : 'list',
      text: `${'  '.repeat(depth)}${prefix} ${text}`,
      signature: signature(task ? 'task' : 'list', ownItem, `${depth}:${ordered}:${checked}`),
    });
    (item.content || []).forEach((child) => {
      if (child.type === 'bulletList') collectList(child, blocks, depth + 1, false, false);
      if (child.type === 'orderedList') collectList(child, blocks, depth + 1, true, false);
      if (child.type === 'taskList') collectList(child, blocks, depth + 1, false, true);
    });
  });
}

function collectBlocks(node, blocks, context = '') {
  if (!node || typeof node !== 'object') return;
  const text = inlineText(node).trim();
  switch (node.type) {
    case 'doc':
      (node.content || []).forEach((child) => collectBlocks(child, blocks));
      break;
    case 'heading': {
      const level = Number(node.attrs?.level || 2);
      blocks.push({ kind: 'heading', text: `H${level}  ${text || '(leere Überschrift)'}`, signature: signature('heading', node, String(level)) });
      break;
    }
    case 'paragraph':
      blocks.push({ kind: 'paragraph', text: `${context}${text || '(leerer Absatz)'}`, signature: signature('paragraph', node, context) });
      break;
    case 'blockquote':
      (node.content || []).forEach((child) => collectBlocks(child, blocks, '> '));
      break;
    case 'bulletList':
      collectList(node, blocks, 0, false, false);
      break;
    case 'orderedList':
      collectList(node, blocks, 0, true, false);
      break;
    case 'taskList':
      collectList(node, blocks, 0, false, true);
      break;
    case 'codeBlock': {
      const language = node.attrs?.language || 'Code';
      const lines = inlineText(node).split('\n');
      lines.forEach((line) => blocks.push({
        kind: 'code',
        text: line || ' ',
        signature: `code:${language}:${line}`,
      }));
      break;
    }
    case 'table':
      (node.content || []).forEach((row) => {
        const cells = (row.content || []).map((cell) => inlineText(cell).trim());
        blocks.push({ kind: 'table', text: `| ${cells.join(' | ')} |`, signature: signature('table', row) });
      });
      break;
    case 'image':
      blocks.push({ kind: 'image', text: inlineText(node), signature: signature('image', node) });
      break;
    case 'horizontalRule':
      blocks.push({ kind: 'rule', text: '────────', signature: signature('rule', node) });
      break;
    default:
      if (Array.isArray(node.content)) node.content.forEach((child) => collectBlocks(child, blocks, context));
  }
}

export function documentToDiffBlocks(document) {
  const blocks = [];
  collectBlocks(document, blocks);
  return blocks;
}

function value(map, key) {
  return map.has(key) ? map.get(key) : Number.NEGATIVE_INFINITY;
}

/** Myers-Diff über semantische ProseMirror-Blöcke. */
function diffBlocks(left, right) {
  const maximum = left.length + right.length;
  const frontier = new Map([[1, 0]]);
  const trace = [];

  for (let distance = 0; distance <= maximum; distance += 1) {
    trace.push(new Map(frontier));
    for (let diagonal = -distance; diagonal <= distance; diagonal += 2) {
      const down = diagonal === -distance
        || (diagonal !== distance && value(frontier, diagonal - 1) < value(frontier, diagonal + 1));
      let x = down ? value(frontier, diagonal + 1) : value(frontier, diagonal - 1) + 1;
      let y = x - diagonal;
      while (x < left.length && y < right.length && left[x].signature === right[y].signature) {
        x += 1;
        y += 1;
      }
      frontier.set(diagonal, x);
      if (x >= left.length && y >= right.length) {
        return backtrack(trace, left, right);
      }
    }
  }
  return [];
}

function backtrack(trace, left, right) {
  let x = left.length;
  let y = right.length;
  const result = [];

  for (let distance = trace.length - 1; distance >= 0; distance -= 1) {
    const frontier = trace[distance];
    const diagonal = x - y;
    const down = diagonal === -distance
      || (diagonal !== distance && value(frontier, diagonal - 1) < value(frontier, diagonal + 1));
    const previousDiagonal = down ? diagonal + 1 : diagonal - 1;
    const previousX = distance === 0 ? 0 : value(frontier, previousDiagonal);
    const previousY = previousX - previousDiagonal;

    while (x > previousX && y > previousY) {
      result.push({ type: 'same', marker: ' ', ...left[x - 1] });
      x -= 1;
      y -= 1;
    }
    if (distance === 0) break;
    if (x === previousX) {
      result.push({ type: 'added', marker: '+', ...right[y - 1] });
      y -= 1;
    } else {
      result.push({ type: 'removed', marker: '−', ...left[x - 1] });
      x -= 1;
    }
  }

  return result.reverse();
}

export function diffNoteDocuments(leftDocument, rightDocument) {
  return diffBlocks(documentToDiffBlocks(leftDocument), documentToDiffBlocks(rightDocument));
}
