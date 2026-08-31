import { normalizeAnnotations } from './schema.js';

/**
 * Erzeugt das Overlay-SVG aus dem Annotationsmodell. Genau diese Datei bedient
 * alle Client-Pfade: die NodeView im Editor, den Lesemodus, den
 * Bildbetrachter und die Zeichenfläche des Annotationseditors. Ihr
 * Gegenstück auf dem Server ist ImageAnnotationSvgRenderer; beide erzeugen für
 * dasselbe Modell dasselbe Markup.
 *
 * buildOverlayMarkup() ist bewusst eine reine Funktion über Zeichenketten -
 * so lässt sie sich mit `node --test` ohne DOM prüfen.
 */

const SVG_NS = 'http://www.w3.org/2000/svg';
export const FONT_STACK = "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";
const MAX_RULE_LINES = 200;

function escapeXml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function num(value) {
  return Number.isFinite(value) ? String(Math.round(value * 10) / 10) : '0';
}

function common(item) {
  const opacity = Number.isFinite(item.o) && item.o < 1 ? ` opacity="${num(item.o)}"` : '';

  return opacity;
}

function stroke(item) {
  return ` stroke="${escapeXml(item.c)}" stroke-width="${num(item.w)}"`
    + ' stroke-linecap="round" stroke-linejoin="round" fill="none"';
}

/**
 * Weiße oder schwarze Schrift auf farbigem Grund, nach wahrgenommener
 * Helligkeit. Betrifft nur die Ziffer im nummerierten Marker.
 */
export function readableInk(color) {
  const hex = color.slice(1, 7);
  const red = parseInt(hex.slice(0, 2), 16);
  const green = parseInt(hex.slice(2, 4), 16);
  const blue = parseInt(hex.slice(4, 6), 16);

  return (red * 299 + green * 587 + blue * 114) / 1000 > 150 ? '#111827' : '#ffffff';
}

function penMarkup(item) {
  if (item.p.length === 1) {
    const [x, y] = item.p[0];

    return `<circle cx="${num(x)}" cy="${num(y)}" r="${num(item.w / 2)}"`
      + ` fill="${escapeXml(item.c)}"${common(item)}/>`;
  }

  const path = item.p
    .map((point, index) => `${index === 0 ? 'M' : 'L'} ${num(point[0])} ${num(point[1])}`)
    .join(' ');

  return `<path d="${path}"${stroke(item)}${common(item)}/>`;
}

/**
 * Pfeilspitzen werden als eigener Pfad gezeichnet, nicht als <marker>: Ein
 * <marker> braucht eine ID in <defs>, und auf einer Notiz mit mehreren
 * annotierten Bildern kollidieren diese IDs.
 */
function arrowHeadMarkup(item, x, y, angle) {
  const size = Math.max(item.w * 4, 12);
  const spread = Math.PI / 7;
  const ax = x - size * Math.cos(angle - spread);
  const ay = y - size * Math.sin(angle - spread);
  const bx = x - size * Math.cos(angle + spread);
  const by = y - size * Math.sin(angle + spread);

  return `<path d="M ${num(ax)} ${num(ay)} L ${num(x)} ${num(y)} L ${num(bx)} ${num(by)}"`
    + `${stroke(item)}${common(item)}/>`;
}

function lineMarkup(item) {
  const dash = item.d === true ? ` stroke-dasharray="${num(item.w * 3)} ${num(item.w * 2)}"` : '';
  let markup = `<path d="M ${num(item.x1)} ${num(item.y1)} L ${num(item.x2)} ${num(item.y2)}"`
    + `${stroke(item)}${dash}${common(item)}/>`;

  const angle = Math.atan2(item.y2 - item.y1, item.x2 - item.x1);
  if (item.head === 'end' || item.head === 'both') {
    markup += arrowHeadMarkup(item, item.x2, item.y2, angle);
  }
  if (item.head === 'both') {
    markup += arrowHeadMarkup(item, item.x1, item.y1, angle + Math.PI);
  }

  return markup;
}

/**
 * Maßband (FR-ANNO-13): Maßlinie mit Endstrichen quer zur Linie und der vom
 * Nutzer eingetragenen Länge darüber. Die Beschriftung liegt in einer eigenen
 * Gruppe, die um die Mitte gedreht wird - so wandert sie mit, wenn ein
 * Endpunkt gezogen wird, ohne dass Koordinaten nachgeführt werden müssen.
 *
 * Der Drehwinkel wird auf ±90° zurückgeklappt, damit die Zahl nie auf dem
 * Kopf steht. Genau diese Rechnung steht wortgleich in
 * ImageAnnotationSvgRenderer::dim().
 */
export function dimLabelAngle(item) {
  const degrees = (Math.atan2(item.y2 - item.y1, item.x2 - item.x1) * 180) / Math.PI;
  if (degrees > 90) {
    return degrees - 180;
  }

  return degrees < -90 ? degrees + 180 : degrees;
}

function dimMarkup(item) {
  const angle = Math.atan2(item.y2 - item.y1, item.x2 - item.x1);
  const tick = Math.max(item.w * 3, 10);
  const tx = (-Math.sin(angle) * tick) / 2;
  const ty = (Math.cos(angle) * tick) / 2;

  let markup = `<path d="M ${num(item.x1)} ${num(item.y1)} L ${num(item.x2)} ${num(item.y2)}"`
    + `${stroke(item)}${common(item)}/>`;
  for (const [x, y] of [[item.x1, item.y1], [item.x2, item.y2]]) {
    markup += `<path d="M ${num(x - tx)} ${num(y - ty)} L ${num(x + tx)} ${num(y + ty)}"`
      + `${stroke(item)}${common(item)}/>`;
  }

  if (typeof item.text !== 'string' || item.text === '') {
    return markup;
  }

  const padding = item.s * 0.25;
  const gap = item.w / 2 + item.s * 0.3;
  const top = -gap - item.bh;
  let label = '';
  if (item.f !== null && Number.isFinite(item.bw) && Number.isFinite(item.bh)) {
    label += `<rect x="${num(-item.bw / 2 - padding)}" y="${num(top - padding)}"`
      + ` width="${num(item.bw + padding * 2)}" height="${num(item.bh + padding * 2)}"`
      + ` rx="${num(padding)}" fill="${escapeXml(item.f)}"${common(item)}/>`;
  }
  label += `<text x="0" y="${num(top + item.s)}" fill="${escapeXml(item.c)}"`
    + ` font-family="${escapeXml(FONT_STACK)}" font-size="${num(item.s)}"`
    + ` text-anchor="middle"${common(item)}>${escapeXml(item.text)}</text>`;

  return markup + `<g transform="translate(${num((item.x1 + item.x2) / 2)} `
    + `${num((item.y1 + item.y2) / 2)}) rotate(${num(dimLabelAngle(item))})">${label}</g>`;
}

function rectMarkup(item) {
  const fill = item.f === null ? 'none' : escapeXml(item.f);

  return `<rect x="${num(item.x)}" y="${num(item.y)}" width="${num(item.rw)}"`
    + ` height="${num(item.rh)}" fill="${fill}" stroke="${escapeXml(item.c)}"`
    + ` stroke-width="${num(item.w)}"${common(item)}/>`;
}

function ellipseMarkup(item) {
  const fill = item.f === null ? 'none' : escapeXml(item.f);

  return `<ellipse cx="${num(item.x + item.rw / 2)}" cy="${num(item.y + item.rh / 2)}"`
    + ` rx="${num(item.rw / 2)}" ry="${num(item.rh / 2)}" fill="${fill}"`
    + ` stroke="${escapeXml(item.c)}" stroke-width="${num(item.w)}"${common(item)}/>`;
}

function maskMarkup(item) {
  return `<rect x="${num(item.x)}" y="${num(item.y)}" width="${num(item.rw)}"`
    + ` height="${num(item.rh)}" fill="${escapeXml(item.c)}"${common(item)}/>`;
}

function rulesMarkup(item) {
  const count = Math.min(MAX_RULE_LINES, Math.floor(item.rh / item.gap));
  let markup = '';
  for (let index = 1; index <= count; index += 1) {
    const y = item.y + index * item.gap;
    markup += `<path d="M ${num(item.x)} ${num(y)} L ${num(item.x + item.rw)} ${num(y)}"`
      + `${stroke(item)}${common(item)}/>`;
  }

  return markup;
}

function markerMarkup(item) {
  const ink = readableInk(item.c);

  return `<circle cx="${num(item.x)}" cy="${num(item.y)}" r="${num(item.r)}"`
    + ` fill="${escapeXml(item.c)}"${common(item)}/>`
    + `<text x="${num(item.x)}" y="${num(item.y)}" fill="${ink}"`
    + ` font-family="${escapeXml(FONT_STACK)}" font-size="${num(item.r * 1.2)}"`
    + ' font-weight="700" text-anchor="middle" dominant-baseline="central"'
    + `${common(item)}>${escapeXml(item.n)}</text>`;
}

/**
 * Die gespeicherten Maße bw/bh stammen aus der Messung beim Anlegen
 * (measureTextBox). Ohne sie gibt es keinen Hintergrundkasten - der Text
 * selbst wird trotzdem gezeichnet.
 */
export function textLineOffsets(item) {
  const lines = item.text.split('\n');
  const lineHeight = item.s * 1.25;

  return lines.map((line, index) => ({ line, y: item.y + item.s + index * lineHeight }));
}

function textMarkup(item) {
  const padding = item.s * 0.25;
  let markup = '';
  if (item.f !== null && Number.isFinite(item.bw) && Number.isFinite(item.bh)) {
    markup += `<rect x="${num(item.x - padding)}" y="${num(item.y - padding)}"`
      + ` width="${num(item.bw + padding * 2)}" height="${num(item.bh + padding * 2)}"`
      + ` rx="${num(padding)}" fill="${escapeXml(item.f)}"${common(item)}/>`;
  }

  const tspans = textLineOffsets(item)
    .map((entry) => `<tspan x="${num(item.x)}" y="${num(entry.y)}">${escapeXml(entry.line)}</tspan>`)
    .join('');

  return markup + `<text fill="${escapeXml(item.c)}" font-family="${escapeXml(FONT_STACK)}"`
    + ` font-size="${num(item.s)}"${common(item)}>${tspans}</text>`;
}

function itemMarkup(item) {
  switch (item.t) {
    case 'pen': return penMarkup(item);
    case 'line': return lineMarkup(item);
    case 'rect': return rectMarkup(item);
    case 'ellipse': return ellipseMarkup(item);
    case 'text': return textMarkup(item);
    case 'rules': return rulesMarkup(item);
    case 'marker': return markerMarkup(item);
    case 'mask': return maskMarkup(item);
    case 'dim': return dimMarkup(item);
    default: return '';
  }
}

/** @returns {string} vollständiges `<svg>` oder `''`, wenn nichts zu zeichnen ist. */
export function buildOverlayMarkup(annotations) {
  const value = normalizeAnnotations(annotations);
  if (value === null) {
    return '';
  }

  const body = value.items.map(itemMarkup).join('');

  return `<svg xmlns="${SVG_NS}" viewBox="0 0 ${value.space.w} ${value.space.h}"`
    + ' preserveAspectRatio="none" aria-hidden="true" focusable="false"'
    + ` class="note-image-overlay-svg">${body}</svg>`;
}

/**
 * Hängt das Overlay in einen Wirtsknoten. Der Umweg über den DOMParser statt
 * innerHTML ist nötig, weil SVG-Elemente im HTML-Namensraum sonst nicht
 * entstehen; er verwirft außerdem unvollständiges Markup, statt es halb
 * darzustellen.
 */
export function renderOverlay(host, annotations) {
  host.replaceChildren();
  const markup = buildOverlayMarkup(annotations);
  if (markup === '') {
    return null;
  }

  const parsed = new DOMParser().parseFromString(markup, 'image/svg+xml');
  if (parsed.querySelector('parsererror') !== null) {
    return null;
  }
  const svg = document.importNode(parsed.documentElement, true);
  host.appendChild(svg);

  return svg;
}

/**
 * Misst einen Textkasten in Bildkoordinaten. Gemessen wird im echten
 * Ziel-SVG, damit Schriftfamilie und Größe exakt dieselben sind wie beim
 * späteren Zeichnen.
 */
export function measureTextBox(svg, text, size) {
  const probe = document.createElementNS(SVG_NS, 'text');
  probe.setAttribute('font-family', FONT_STACK);
  probe.setAttribute('font-size', String(size));
  probe.setAttribute('visibility', 'hidden');
  for (const line of text.split('\n')) {
    const tspan = document.createElementNS(SVG_NS, 'tspan');
    tspan.setAttribute('x', '0');
    tspan.setAttribute('dy', String(size * 1.25));
    tspan.textContent = line === '' ? ' ' : line;
    probe.appendChild(tspan);
  }
  svg.appendChild(probe);
  let box = { width: 0, height: 0 };
  try {
    box = probe.getBBox();
  } finally {
    probe.remove();
  }

  return {
    bw: Math.round(box.width * 10) / 10,
    bh: Math.round(Math.max(box.height, size * 1.25 * text.split('\n').length) * 10) / 10,
  };
}
