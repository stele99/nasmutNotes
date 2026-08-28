/**
 * Der Server nimmt nur einen engen Knotenbaum an (ProseMirrorValidator):
 * Überschriften bis H3, Bilder ausschließlich aus dem geschützten
 * Attachment-Speicher. Eingefügte Fremdinhalte bringen regelmäßig mehr mit -
 * und danach lässt sich die Notiz überhaupt nicht mehr speichern, weil jeder
 * Speicherversuch an derselben Validierung scheitert. Deshalb wird schon beim
 * Einfügen zurechtgezogen, statt den Fehler erst beim Sync zu bemerken.
 */
import { normalizeAnnotations } from './annotations/schema.js';

export const MAX_HEADING_LEVEL = 3;
export const HEADING_LEVELS = [1, 2, 3];

/**
 * Erlaubt sind der Server-Speicher und der lokale Entwurfsspeicher - Letzterer
 * wird beim Sync gegen die Server-Adresse getauscht (uploadOfflineAttachments).
 */
const ALLOWED_IMAGE_SRC = /^\/(api\/attachments\/[a-f0-9]{64}|offline-attachments\/[a-f0-9-]+)$/;

const NEEDS_CLEANUP = /<h[456][\s>]|<img[\s>]|data-annotations/i;

/**
 * Pflichteltern für Fragmente aus der Zwischenablage, gleiche Tabelle wie in
 * prosemirror-view: ein einzelnes <tr> verwirft der HTML-Parser, sobald es ohne
 * <table> wieder eingelesen wird.
 */
const WRAP_MAP = {
  thead: ['table'],
  tbody: ['table'],
  tfoot: ['table'],
  caption: ['table'],
  colgroup: ['table'],
  col: ['table', 'colgroup'],
  tr: ['table', 'tbody'],
  td: ['table', 'tbody', 'tr'],
  th: ['table', 'tbody', 'tr'],
};

/**
 * Zwischenablage-HTML auf das erlaubte Repertoire bringen: tiefere
 * Überschriften rutschen auf H3, fremde Bildquellen fliegen heraus. Bilder aus
 * der Zwischenablage kommen als Datei-Anhang herein (ProtectedImageUpload) und
 * sind hier nicht betroffen.
 *
 * Unauffälliges HTML geht unverändert weiter - jeder Umweg über den Parser
 * kostet Genauigkeit, die beim Einfügen niemand braucht.
 */
export function sanitizePastedHtml(html) {
  if (typeof html !== 'string' || !NEEDS_CLEANUP.test(html)) {
    return html;
  }

  // Führende <meta>-Angaben entfernt ProseMirror ohnehin; hier müssen sie weg,
  // damit die Erkennung des ersten Tags greift.
  const metas = /^(\s*<meta [^>]*>)*/.exec(html);
  let fragment = metas ? html.slice(metas[0].length) : html;
  const firstTag = /<([a-z][^>\s/]*)/i.exec(fragment);
  const wrap = firstTag ? WRAP_MAP[firstTag[1].toLowerCase()] : null;
  if (wrap) {
    fragment = wrap.map((tag) => `<${tag}>`).join('')
      + fragment
      + wrap.map((tag) => `</${tag}>`).reverse().join('');
  }

  const parsed = new DOMParser().parseFromString(fragment, 'text/html');
  if (!parsed.body) {
    return html;
  }

  for (const heading of parsed.body.querySelectorAll('h4, h5, h6')) {
    const replacement = parsed.createElement(`h${MAX_HEADING_LEVEL}`);
    replacement.innerHTML = heading.innerHTML;
    heading.replaceWith(replacement);
  }

  for (const image of parsed.body.querySelectorAll('img')) {
    if (!ALLOWED_IMAGE_SRC.test(image.getAttribute('src') || '')) {
      image.remove();
      continue;
    }
    const raw = image.getAttribute('data-annotations');
    if (raw !== null) {
      let value = null;
      try {
        value = normalizeAnnotations(JSON.parse(raw));
      } catch (error) {
        value = null;
      }
      if (value === null) {
        image.removeAttribute('data-annotations');
      } else {
        image.setAttribute('data-annotations', JSON.stringify(value));
      }
    }
  }

  let root = parsed.body;
  for (const tag of wrap || []) {
    root = root.querySelector(tag) || root;
  }

  return root.innerHTML;
}

/**
 * Dieselbe Bereinigung für ein bereits vorhandenes Dokument. Notizen, in denen
 * vor dieser Bereinigung etwas Unerlaubtes gelandet ist, liegen als lokaler
 * Entwurf vor und blieben sonst dauerhaft unspeicherbar.
 *
 * @returns {{ doc: unknown, changed: boolean }}
 */
export function sanitizeNoteDoc(doc) {
  let changed = false;

  const walk = (node) => {
    if (!node || typeof node !== 'object') {
      return node;
    }

    if (node.type === 'heading') {
      const level = Number(node.attrs?.level);
      if (!Number.isInteger(level) || level < 1 || level > MAX_HEADING_LEVEL) {
        changed = true;
        node = {
          ...node,
          attrs: { ...(node.attrs || {}), level: Math.min(Math.max(level || 1, 1), MAX_HEADING_LEVEL) },
        };
      }
    }

    if (!Array.isArray(node.content)) {
      return node;
    }

    const content = [];
    for (const child of node.content) {
      if (child?.type === 'image' && !ALLOWED_IMAGE_SRC.test(child.attrs?.src || '')) {
        changed = true;
        continue;
      }
      if (child?.type === 'image' && child.attrs?.annotations != null) {
        const value = normalizeAnnotations(child.attrs.annotations);
        if (JSON.stringify(value) !== JSON.stringify(child.attrs.annotations)) {
          changed = true;
          content.push({ ...child, attrs: { ...child.attrs, annotations: value } });
          continue;
        }
      }
      content.push(walk(child));
    }

    return { ...node, content };
  };

  return { doc: walk(doc), changed };
}
