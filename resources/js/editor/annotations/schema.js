/**
 * Datenmodell der Bild-Annotationen (FR-ANNO-04/05). Diese Datei ist die
 * einzige Stelle im Frontend, die entscheidet, was ein gültiges
 * Annotationsobjekt ist - benutzt von der TipTap-Erweiterung (beim Parsen aus
 * der Zwischenablage), vom Annotationseditor (beim Übernehmen) und von
 * sanitize.js (beim Reparieren eines Dokuments).
 *
 * Der Client ist nachsichtig und wirft Unbrauchbares weg; der Server ist
 * streng und lehnt es ab (ImageAnnotationValidator). Beides zusammen sorgt
 * dafür, dass eine Notiz nie in einen Zustand gerät, in dem sie sich nicht
 * mehr speichern lässt.
 */

export const ANNOTATION_VERSION = 1;
export const MAX_ITEMS = 200;
export const MAX_POINTS = 400;
export const MAX_TEXT_LENGTH = 500;
export const MAX_TEXT_LINES = 12;
export const MAX_JSON_BYTES = 40000;
export const MAX_SPACE = 20000;
export const COORD_LIMIT = 100000;

/** Toleranz der Pfadvereinfachung, als Anteil der Bildbreite. */
export const SIMPLIFY_RATIO = 0.003;

export const PALETTE = [
  '#e11d48', '#f97316', '#eab308', '#16a34a',
  '#2563eb', '#7c3aed', '#111827', '#ffffff',
];

const HEX_COLOR = /^#[0-9a-f]{6}([0-9a-f]{2})?$/i;
const ITEM_ID = /^[a-z0-9]{8}$/;
const HEAD_VALUES = ['none', 'end', 'both'];

/**
 * Feldbeschreibung je Elementtyp. `num` sind Pflichtzahlen, `pos` davon die,
 * die größer als 0 sein müssen. Diese Tabelle hat ihr wortgleiches Gegenstück
 * in ImageAnnotationValidator::TYPES - Änderungen immer an beiden Stellen.
 */
export const TYPES = {
  pen: { num: ['w'], pos: ['w'], points: true },
  line: { num: ['w', 'x1', 'y1', 'x2', 'y2'], pos: ['w'], head: true, dash: true },
  rect: { num: ['w', 'x', 'y', 'rw', 'rh'], pos: ['w', 'rw', 'rh'], fill: true },
  ellipse: { num: ['w', 'x', 'y', 'rw', 'rh'], pos: ['w', 'rw', 'rh'], fill: true },
  text: { num: ['x', 'y', 's', 'bw', 'bh'], pos: ['s'], fill: true, text: true },
  rules: { num: ['w', 'x', 'y', 'rw', 'rh', 'gap'], pos: ['w', 'rw', 'rh', 'gap'] },
  marker: { num: ['x', 'y', 'r', 'n'], pos: ['r', 'n'] },
  mask: { num: ['x', 'y', 'rw', 'rh'], pos: ['rw', 'rh'] },
};

function isNumber(value) {
  return typeof value === 'number' && Number.isFinite(value);
}

function isColor(value) {
  return typeof value === 'string' && HEX_COLOR.test(value);
}

export function round1(value) {
  return Math.round(value * 10) / 10;
}

function clamp(value, min, max) {
  return Math.min(max, Math.max(min, value));
}

export function createItemId() {
  const alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
  const bytes = new Uint8Array(8);
  if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
    crypto.getRandomValues(bytes);
  } else {
    for (let index = 0; index < bytes.length; index += 1) {
      bytes[index] = Math.floor(Math.random() * 256);
    }
  }

  return Array.from(bytes, (byte) => alphabet[byte % alphabet.length]).join('');
}

export function emptyAnnotations(width, height) {
  return {
    v: ANNOTATION_VERSION,
    space: { w: Math.round(clamp(width, 1, MAX_SPACE)), h: Math.round(clamp(height, 1, MAX_SPACE)) },
    items: [],
  };
}

function normalizeSpace(space) {
  if (!space || typeof space !== 'object') {
    return null;
  }
  const width = Math.round(Number(space.w));
  const height = Math.round(Number(space.h));
  if (!isNumber(width) || !isNumber(height)) {
    return null;
  }
  if (width < 1 || height < 1 || width > MAX_SPACE || height > MAX_SPACE) {
    return null;
  }

  return { w: width, h: height };
}

function normalizeText(value) {
  if (typeof value !== 'string') {
    return null;
  }
  const lines = value
    .replace(/\r\n?/g, '\n')
    .split('\n')
    .map((line) => line.replace(/\s+$/, ''))
    .slice(0, MAX_TEXT_LINES);
  const text = lines.join('\n').slice(0, MAX_TEXT_LENGTH).trim();

  return text === '' ? null : text;
}

/**
 * Zu lange Pfade werden gleichmäßig ausgedünnt statt verworfen: Ein Strich,
 * den der Nutzer gerade gezogen hat, soll nicht wortlos verschwinden.
 */
function normalizePoints(value) {
  if (!Array.isArray(value) || value.length === 0) {
    return null;
  }

  const points = [];
  for (const point of value) {
    if (!Array.isArray(point) || point.length < 2) {
      continue;
    }
    const [x, y] = point;
    if (!isNumber(x) || !isNumber(y) || Math.abs(x) > COORD_LIMIT || Math.abs(y) > COORD_LIMIT) {
      continue;
    }
    points.push([round1(x), round1(y)]);
  }
  if (points.length === 0) {
    return null;
  }
  if (points.length <= MAX_POINTS) {
    return points;
  }

  const step = (points.length - 1) / (MAX_POINTS - 1);
  const thinned = [];
  for (let index = 0; index < MAX_POINTS; index += 1) {
    thinned.push(points[Math.round(index * step)]);
  }

  return thinned;
}

function normalizeItem(raw) {
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) {
    return null;
  }
  const spec = TYPES[raw.t];
  if (!spec || !isColor(raw.c)) {
    return null;
  }

  const item = {
    id: typeof raw.id === 'string' && ITEM_ID.test(raw.id) ? raw.id : createItemId(),
    t: raw.t,
    c: raw.c.toLowerCase(),
  };

  if (isNumber(raw.o) && raw.o < 1) {
    item.o = Math.round(clamp(raw.o, 0.05, 1) * 100) / 100;
  }

  for (const key of spec.num) {
    const value = raw[key];
    if (!isNumber(value) || Math.abs(value) > COORD_LIMIT) {
      return null;
    }
    if (spec.pos.includes(key) && value <= 0) {
      return null;
    }
    item[key] = round1(value);
  }

  if (spec.fill) {
    item.f = isColor(raw.f) ? raw.f.toLowerCase() : null;
  }
  if (spec.head) {
    item.head = HEAD_VALUES.includes(raw.head) ? raw.head : 'none';
  }
  if (spec.dash && raw.d === true) {
    item.d = true;
  }
  if (spec.text) {
    const text = normalizeText(raw.text);
    if (text === null) {
      return null;
    }
    item.text = text;
  }
  if (spec.points) {
    const points = normalizePoints(raw.p);
    if (points === null) {
      return null;
    }
    item.p = points;
  }
  if (raw.t === 'marker') {
    if (!Number.isInteger(raw.n) || raw.n < 1 || raw.n > 99) {
      return null;
    }
  }

  return item;
}

/**
 * @returns {{ v: number, space: { w: number, h: number }, items: object[] } | null}
 *   `null`, wenn nichts Gültiges übrig bleibt - dann trägt der Bildknoten
 *   `annotations: null` und das Dokument bleibt so klein wie zuvor.
 */
export function normalizeAnnotations(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return null;
  }
  if (value.v !== ANNOTATION_VERSION) {
    return null;
  }
  const space = normalizeSpace(value.space);
  if (space === null) {
    return null;
  }

  const items = [];
  const seen = new Set();
  for (const raw of Array.isArray(value.items) ? value.items : []) {
    if (items.length >= MAX_ITEMS) {
      break;
    }
    const item = normalizeItem(raw);
    if (item === null || seen.has(item.id)) {
      continue;
    }
    seen.add(item.id);
    items.push(item);
  }

  return items.length === 0 ? null : { v: ANNOTATION_VERSION, space, items };
}

export function annotationBytes(value) {
  if (value === null || value === undefined) {
    return 0;
  }

  return new TextEncoder().encode(JSON.stringify(value)).length;
}

/**
 * Letzte Verteidigungslinie vor dem Schreiben in den Knoten: Passt das Objekt
 * nicht ins Budget, fallen Elemente von hinten weg. Der Annotationseditor
 * verhindert diesen Fall bereits beim Zeichnen (annoCanAdd), damit hier
 * niemandem unbemerkt etwas abhandenkommt.
 */
export function serializeAnnotations(value) {
  let normalized = normalizeAnnotations(value);
  while (normalized !== null && annotationBytes(normalized) > MAX_JSON_BYTES) {
    const items = normalized.items.slice(0, -1);
    normalized = items.length === 0 ? null : { ...normalized, items };
  }

  return normalized;
}

/** Texte in Lesereihenfolge - für Suche, Alternativtext und Versions-Diff. */
export function annotationTexts(value) {
  const normalized = normalizeAnnotations(value);
  if (normalized === null) {
    return [];
  }

  return normalized.items
    .filter((item) => item.t === 'text')
    .map((item) => item.text);
}

/**
 * Douglas-Peucker, iterativ statt rekursiv: Ein mit dem Finger gezogener
 * Strich liefert schnell mehrere hundert Punkte, und eine Rekursion darüber
 * wäre auf schwachen Geräten unnötig tief.
 */
export function simplifyPath(points, tolerance) {
  if (!Array.isArray(points) || points.length < 3 || tolerance <= 0) {
    return Array.isArray(points) ? points.slice() : [];
  }

  const keep = new Array(points.length).fill(false);
  keep[0] = true;
  keep[points.length - 1] = true;
  const stack = [[0, points.length - 1]];

  while (stack.length > 0) {
    const [start, end] = stack.pop();
    let farthest = -1;
    let distance = tolerance;
    for (let index = start + 1; index < end; index += 1) {
      const current = perpendicularDistance(points[index], points[start], points[end]);
      if (current > distance) {
        distance = current;
        farthest = index;
      }
    }
    if (farthest !== -1) {
      keep[farthest] = true;
      stack.push([start, farthest], [farthest, end]);
    }
  }

  return points.filter((_point, index) => keep[index]);
}

function perpendicularDistance(point, start, end) {
  const dx = end[0] - start[0];
  const dy = end[1] - start[1];
  const length = Math.hypot(dx, dy);
  if (length === 0) {
    return Math.hypot(point[0] - start[0], point[1] - start[1]);
  }

  return Math.abs(dy * point[0] - dx * point[1] + end[0] * start[1] - end[1] * start[0]) / length;
}
