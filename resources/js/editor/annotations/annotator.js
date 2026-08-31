import {
  MAX_ITEMS,
  MAX_JSON_BYTES,
  PALETTE,
  SIMPLIFY_RATIO,
  annotationBytes,
  createItemId,
  emptyAnnotations,
  normalizeAnnotations,
  round1,
  serializeAnnotations,
  simplifyPath,
} from './schema.js';
import { measureTextBox, renderOverlay } from './render.js';

const HISTORY_LIMIT = 50;

/** Voreinstellungen je Werkzeug; alles Übrige kommt aus der Eigenschaftsleiste.
 *  Beim Textwerkzeug trägt `width` die Schriftgröße, kein Strichstärke. */
const TOOL_DEFAULTS = {
  pen: { width: 6, opacity: 1 },
  highlighter: { width: 36, opacity: 0.4 },
  line: { width: 6, opacity: 1 },
  arrow: { width: 6, opacity: 1 },
  rect: { width: 6, opacity: 1 },
  ellipse: { width: 6, opacity: 1 },
  text: { width: 44, opacity: 1 },
  rules: { width: 3, opacity: 1 },
  marker: { width: 6, opacity: 1 },
  mask: { width: 6, opacity: 1 },
};

/** Feste Größen für Werkzeuge ohne Strichstärke - unabhängig vom Textregler. */
const MARKER_RADIUS = 40;
const RULES_LINE_GAP = 70;

/**
 * Strichstärke je Werkzeug im Browser-Speicher (localStorage) merken
 * (FR-ANNO-09): Nutzer ziehen sie meist einmal je Werkzeug fest und
 * erwarten sie beim nächsten Bild wieder. Grenzen und Schlüssel folgen dem
 * Eigenschaftsregler (min 1, max 80); Unbrauchbares fällt auf die
 * Voreinstellung zurück.
 */
const WIDTH_STORAGE_KEY = 'notes-anno-widths';

export function readStoredWidths() {
  try {
    if (typeof window === 'undefined' || !window.localStorage) {
      return {};
    }
    const parsed = JSON.parse(window.localStorage.getItem(WIDTH_STORAGE_KEY) ?? '{}');
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
      return {};
    }
    const widths = {};
    for (const [tool, value] of Object.entries(parsed)) {
      const width = Math.round(Number(value));
      if (TOOL_DEFAULTS[tool] && Number.isFinite(width) && width >= 1 && width <= 80) {
        widths[tool] = width;
      }
    }

    return widths;
  } catch (error) {
    return {};
  }
}

export function storedToolWidth(tool, fallback) {
  const width = readStoredWidths()[tool];

  return typeof width === 'number' ? width : fallback;
}

export function storeToolWidth(tool, width) {
  if (!TOOL_DEFAULTS[tool]) {
    return;
  }
  try {
    if (typeof window === 'undefined' || !window.localStorage) {
      return;
    }
    const widths = readStoredWidths();
    widths[tool] = Math.min(80, Math.max(1, Math.round(Number(width))));
    window.localStorage.setItem(WIDTH_STORAGE_KEY, JSON.stringify(widths));
  } catch (error) {
    // Ohne Speicher (Privatmodus, Quote) gilt die Dicke nur für die Sitzung.
  }
}

export function imageAnnotatorMixin() {
  // Außerhalb des Alpine-Zustands: Der laufende Zeigerzug und der Zugriff auf
  // den ProseMirror-Editor dürfen nicht reaktiv werden.
  let getEditor = () => null;
  let drag = null;
  let stageResizeHandler = null;

  return {
    annoOpen: false,
    annoSrc: '',
    annoAlt: '',
    annoPos: null,
    annoSpace: { w: 0, h: 0 },
    annoItems: [],
    annoTool: 'pen',
    annoColor: PALETTE[0],
    annoWidth: storedToolWidth('pen', TOOL_DEFAULTS.pen.width),
    annoOpacity: 1,
    annoFontSize: 44,
    annoSelectedId: '',
    annoSelectionStyle: '',
    annoNotice: '',
    annoError: '',
    annoMaskHintShown: false,
    annoTextOpen: false,
    annoTextDraft: '',
    annoTextEditId: '',
    annoTextAnchor: null,
    annoHistory: [],
    annoFuture: [],
    annoDirty: false,

    annoSetEditorAccessor(accessor) {
      getEditor = accessor;
    },

    annoEditor() {
      return getEditor();
    },

    /**
     * Einstieg über den Stift-Knopf am ausgewählten Bild (annotatedImage.js):
     * Die NodeView kennt den Alpine-Zustand nicht und schickt deshalb die
     * Knotenposition als aufsteigendes Ereignis. Der Knoten wird hier frisch
     * aus dem Dokument geholt, nicht aus dem Ereignis - die NodeView kann
     * älter sein als der letzte Update.
     */
    annoBindNodeViewEntry(editor) {
      editor.view.dom.addEventListener('open-image-annotator', (event) => {
        if (!this.canEditPage || this.isEncrypted()) {
          return;
        }
        const pos = event.detail?.pos;
        const node = typeof pos === 'number' ? editor.state.doc.nodeAt(pos) : null;
        if (!node || node.type.name !== 'image') {
          return;
        }
        this.annoBegin(pos, node);
      });
    },

    /** Einstieg aus der Werkzeugleiste: Es muss ein Bildknoten ausgewählt sein. */
    openImageAnnotator() {
      const editor = this.annoEditor();
      if (!editor || !this.canEditPage || this.isEncrypted()) {
        return;
      }
      const selected = editor.state.selection.node;
      if (!selected || selected.type.name !== 'image') {
        this.annoError = 'Bitte zuerst ein Bild antippen.';

        return;
      }
      this.annoBegin(editor.state.selection.from, selected);
    },

    /** Einstieg aus dem Vollbild-Betrachter (Handy). */
    openImageAnnotatorFromViewer() {
      const editor = this.annoEditor();
      if (!editor || !this.canEditPage || this.isEncrypted() || !this.imageViewerSrc) {
        return;
      }
      const path = new URL(this.imageViewerSrc, window.location.origin).pathname;
      let position = null;
      let node = null;
      editor.state.doc.descendants((candidate, pos) => {
        if (position !== null) {
          return false;
        }
        if (candidate.type.name === 'image' && candidate.attrs.src === path) {
          position = pos;
          node = candidate;
        }

        return position === null;
      });
      if (position === null) {
        return;
      }
      this.closeImageViewer();
      this.annoBegin(position, node);
    },

    /**
     * Der Annotationsraum wird einmal festgelegt und danach nie geändert -
     * sonst wanderten alle vorhandenen Elemente, sobald das Bild
     * serverseitig komprimiert wurde.
     */
    annoBegin(position, node) {
      const stored = normalizeAnnotations(node.attrs.annotations);
      const dom = this.annoEditor()?.view.nodeDOM(position) ?? null;
      const image = dom instanceof HTMLElement ? dom.querySelector('img') : null;
      const width = stored?.space.w ?? image?.naturalWidth ?? node.attrs.width ?? 0;
      const height = stored?.space.h ?? image?.naturalHeight ?? node.attrs.height ?? 0;
      if (width < 1 || height < 1) {
        this.annoError = 'Das Bild ist noch nicht vollständig geladen.';

        return;
      }

      this.annoPos = position;
      this.annoSrc = node.attrs.src;
      this.annoAlt = node.attrs.alt || '';
      this.annoSpace = stored?.space ?? emptyAnnotations(width, height).space;
      this.annoItems = stored ? JSON.parse(JSON.stringify(stored.items)) : [];
      this.annoSelectedId = '';
      this.annoSelectionStyle = '';
      this.annoHistory = [];
      this.annoFuture = [];
      this.annoDirty = false;
      this.annoError = '';
      this.annoNotice = '';
      this.annoOpen = true;
      this.$nextTick(() => {
        this.annoFitStage();
        this.annoWatchStageSize();
        this.annoRenderAll();
        this.annoSyncToolbar();
      });
    },

    closeImageAnnotator() {
      if (!this.annoOpen) {
        return;
      }
      if (this.annoDirty && !window.confirm('Änderungen an den Bildnotizen verwerfen?')) {
        return;
      }
      this.annoOpen = false;
      this.annoItems = [];
      this.annoPos = null;
      this.annoUnwatchStageSize();
      drag = null;
    },

    /**
     * Geschrieben wird über die gemerkte Position, nicht über die aktuelle
     * Auswahl: Der Einstieg aus dem Vollbild-Betrachter hat gar keine.
     */
    applyImageAnnotations() {
      const editor = this.annoEditor();
      const position = this.annoPos;
      if (!editor || position === null) {
        return;
      }
      const value = serializeAnnotations({
        v: 1,
        space: this.annoSpace,
        items: JSON.parse(JSON.stringify(this.annoItems)),
      });

      editor
        .chain()
        .focus()
        .command(({ tr }) => {
          const node = tr.doc.nodeAt(position);
          if (!node || node.type.name !== 'image') {
            return false;
          }
          tr.setNodeMarkup(position, undefined, { ...node.attrs, annotations: value });

          return true;
        })
        .run();

      this.annoDirty = false;
      this.annoOpen = false;
      this.annoItems = [];
      this.annoPos = null;
      this.annoUnwatchStageSize();
      drag = null;
    },

    // --- Werkzeuge und Eigenschaften -----------------------------------

    annoSelectTool(event) {
      const tool = event.currentTarget?.dataset.tool;
      if (!tool) {
        return;
      }
      this.annoTool = tool;
      const defaults = TOOL_DEFAULTS[tool];
      if (defaults) {
        this.annoWidth = storedToolWidth(tool, defaults.width);
        this.annoOpacity = defaults.opacity;
      }
      if (tool === 'text') {
        this.annoFontSize = this.annoWidth;
      }
      if (tool === 'mask' && !this.annoMaskHintShown) {
        this.annoMaskHintShown = true;
        this.annoNotice = 'Abdecken verbirgt den Bildbereich nur in der Anzeige. '
          + 'Der Bildinhalt bleibt in der Datei erhalten.';
      }
      if (tool !== 'select') {
        this.annoSelectedId = '';
        this.annoSelectionStyle = '';
      }
      this.annoSyncToolbar();
    },

    annoPickColor(event) {
      const color = event.currentTarget?.dataset.color;
      if (!color) {
        return;
      }
      this.annoColor = color;
      this.annoApplyToSelection('c', color);
      this.annoSyncToolbar();
    },

    annoColorInput(event) {
      this.annoColor = event.target.value;
      this.annoApplyToSelection('c', this.annoColor);
      this.annoSyncToolbar();
    },

    annoWidthInput(event) {
      this.annoWidth = Number(event.target.value);
      storeToolWidth(this.annoTool, this.annoWidth);
      if (this.annoTool === 'text') {
        this.annoFontSize = this.annoWidth;
        this.annoApplyFontSizeToSelection();

        return;
      }
      this.annoApplyToSelection('w', this.annoWidth);
    },

    /** Der Regler bedeutet je Werkzeug etwas anderes - für die Bildschirmleser. */
    annoWidthLabel() {
      return this.annoTool === 'text' ? 'Schriftgröße' : 'Strichstärke';
    },

    annoOpacityInput(event) {
      this.annoOpacity = Number(event.target.value);
      this.annoApplyToSelection('o', this.annoOpacity);
    },

    /** Eigenschaftsänderungen wirken auf das ausgewählte Element, sonst auf das nächste neue. */
    annoApplyToSelection(key, value) {
      const item = this.annoSelectedItem();
      if (!item || !(key in item)) {
        return;
      }
      this.annoPushHistory();
      item[key] = value;
      this.annoRenderAll();
    },

    /**
     * Regler am Textwerkzeug: Ein ausgewählter Textkasten ändert seine
     * Schriftgröße sofort; die gemessenen Maße bw/bh werden nachgezogen, damit
     * der Hintergrundkasten passend bleibt.
     */
    annoApplyFontSizeToSelection() {
      const item = this.annoSelectedItem();
      if (!item || item.t !== 'text') {
        return;
      }
      const svg = this.$refs.annoLayer?.querySelector('svg') ?? this.annoEnsureLayerSvg();
      const box = measureTextBox(svg, item.text, this.annoWidth);
      this.annoPushHistory();
      item.s = this.annoWidth;
      item.bw = box.bw;
      item.bh = box.bh;
      this.annoRenderAll();
    },

    annoSelectedItem() {
      return this.annoItems.find((item) => item.id === this.annoSelectedId) ?? null;
    },

    annoSyncToolbar() {
      const bar = this.$refs.annoToolbar;
      if (!bar) {
        return;
      }
      for (const button of bar.querySelectorAll('[data-tool]')) {
        button.classList.toggle('is-active', button.dataset.tool === this.annoTool);
      }
      for (const button of bar.querySelectorAll('[data-color]')) {
        button.classList.toggle('is-active', button.dataset.color === this.annoColor);
      }
    },

    annoCanAdd() {
      if (this.annoItems.length >= MAX_ITEMS) {
        this.annoError = `Mehr als ${MAX_ITEMS} Elemente sind auf einem Bild nicht möglich.`;

        return false;
      }
      if (annotationBytes({ v: 1, space: this.annoSpace, items: this.annoItems }) > MAX_JSON_BYTES * 0.95) {
        this.annoError = 'Die Bildnotizen sind zu umfangreich geworden. Bitte etwas entfernen.';

        return false;
      }
      this.annoError = '';

      return true;
    },

    // --- Zeichnen -------------------------------------------------------

    /**
     * Bildkoordinaten aus einem Zeigerereignis. Der Weg über
     * getBoundingClientRect() macht Zoom und Verschieben der Bühne
     * automatisch mit - dort ist keine Umrechnung nötig.
     */
    annoPoint(event) {
      const stage = this.$refs.annoStage;
      const rect = stage.getBoundingClientRect();

      return {
        x: round1(((event.clientX - rect.left) / rect.width) * this.annoSpace.w),
        y: round1(((event.clientY - rect.top) / rect.height) * this.annoSpace.h),
      };
    },

    annoPointerDown(event) {
      if (event.button !== undefined && event.button !== 0) {
        return;
      }
      // Handballenabweisung: Während ein Stift zeichnet, wird jede Berührung
      // ignoriert.
      if (drag !== null && drag.pointerType === 'pen' && event.pointerType === 'touch') {
        return;
      }
      event.preventDefault();
      this.$refs.annoStage.setPointerCapture(event.pointerId);
      const point = this.annoPoint(event);

      if (this.annoTool === 'select') {
        this.annoBeginSelect(point, event);

        return;
      }
      if (this.annoTool === 'text') {
        this.annoOpenTextDialog(point, '');

        return;
      }
      if (!this.annoCanAdd()) {
        return;
      }
      drag = {
        kind: 'create',
        pointerId: event.pointerId,
        pointerType: event.pointerType,
        origin: point,
        item: this.annoCreateItem(point, event),
      };
      this.annoRenderPreview(drag.item);
    },

    annoPointerMove(event) {
      if (drag === null || event.pointerId !== drag.pointerId) {
        return;
      }
      event.preventDefault();
      const point = this.annoPoint(event);

      if (drag.kind === 'create') {
        this.annoExtendItem(drag.item, drag.origin, point);
        this.annoRenderPreview(drag.item);

        return;
      }
      if (drag.kind === 'move') {
        this.annoTranslate(drag.item, point.x - drag.last.x, point.y - drag.last.y);
        drag.last = point;
        this.annoRenderAll();
        this.annoUpdateSelectionFrame();
      }
      if (drag.kind === 'resize') {
        this.annoResize(drag.item, point);
        this.annoRenderAll();
        this.annoUpdateSelectionFrame();
      }
    },

    annoPointerUp(event) {
      if (drag === null || event.pointerId !== drag.pointerId) {
        return;
      }
      this.$refs.annoStage.releasePointerCapture?.(event.pointerId);

      if (drag.kind === 'create') {
        const item = drag.item;
        drag = null;
        if (item.t === 'pen') {
          item.p = simplifyPath(item.p, this.annoSpace.w * SIMPLIFY_RATIO);
        }
        this.annoRenderPreview(null);
        if (!this.annoIsMeaningful(item)) {
          return;
        }
        this.annoPushHistory();
        this.annoItems.push(item);
        this.annoRenderAll();

        return;
      }

      drag = null;
      this.annoRenderAll();
    },

    annoPointerCancel(event) {
      if (drag !== null && event.pointerId === drag.pointerId) {
        drag = null;
        this.annoRenderPreview(null);
        this.annoRenderAll();
      }
    },

    /** Ein Klick ohne Zugbewegung soll kein unsichtbares Element hinterlassen. */
    annoIsMeaningful(item) {
      switch (item.t) {
        case 'pen':
          return item.p.length > 1 || item.w > 0;
        case 'line':
          return Math.hypot(item.x2 - item.x1, item.y2 - item.y1) > item.w;
        case 'rect':
        case 'ellipse':
        case 'mask':
        case 'rules':
          return item.rw > 2 && item.rh > 2;
        default:
          return true;
      }
    },

    annoCreateItem(point, event) {
      const base = {
        id: createItemId(),
        c: this.annoColor,
        o: this.annoOpacity,
      };
      // Ein Stift zeichnet feiner als ein Finger, solange der Nutzer die
      // Strichstärke nicht selbst angefasst hat.
      const width = event.pointerType === 'pen' ? Math.max(2, this.annoWidth / 2) : this.annoWidth;

      switch (this.annoTool) {
        case 'pen':
        case 'highlighter':
          return { ...base, t: 'pen', w: width, p: [[point.x, point.y]] };
        case 'line':
          return { ...base, t: 'line', w: width, x1: point.x, y1: point.y, x2: point.x, y2: point.y, head: 'none' };
        case 'arrow':
          return { ...base, t: 'line', w: width, x1: point.x, y1: point.y, x2: point.x, y2: point.y, head: 'end' };
        case 'rect':
          return { ...base, t: 'rect', w: width, x: point.x, y: point.y, rw: 1, rh: 1, f: null };
        case 'ellipse':
          return { ...base, t: 'ellipse', w: width, x: point.x, y: point.y, rw: 1, rh: 1, f: null };
        case 'rules':
          return { ...base, t: 'rules', w: width, x: point.x, y: point.y, rw: 1, rh: 1, gap: RULES_LINE_GAP };
        case 'mask':
          return { ...base, t: 'mask', o: 1, x: point.x, y: point.y, rw: 1, rh: 1 };
        case 'marker':
          return {
            ...base,
            t: 'marker',
            x: point.x,
            y: point.y,
            r: MARKER_RADIUS,
            n: Math.min(99, this.annoItems.filter((item) => item.t === 'marker').length + 1),
          };
        default:
          return { ...base, t: 'pen', w: width, p: [[point.x, point.y]] };
      }
    },

    annoExtendItem(item, origin, point) {
      if (item.t === 'pen') {
        item.p.push([point.x, point.y]);

        return;
      }
      if (item.t === 'line') {
        item.x2 = point.x;
        item.y2 = point.y;

        return;
      }
      if (item.t === 'marker') {
        item.x = point.x;
        item.y = point.y;

        return;
      }
      item.x = round1(Math.min(origin.x, point.x));
      item.y = round1(Math.min(origin.y, point.y));
      item.rw = Math.max(1, round1(Math.abs(point.x - origin.x)));
      item.rh = Math.max(1, round1(Math.abs(point.y - origin.y)));
    },

    // --- Auswahl, Verschieben, Skalieren --------------------------------

    annoBounds(item) {
      switch (item.t) {
        case 'pen': {
          const xs = item.p.map((point) => point[0]);
          const ys = item.p.map((point) => point[1]);
          const pad = item.w / 2;

          return {
            x: Math.min(...xs) - pad,
            y: Math.min(...ys) - pad,
            rw: Math.max(...xs) - Math.min(...xs) + item.w,
            rh: Math.max(...ys) - Math.min(...ys) + item.w,
          };
        }
        case 'line':
          return {
            x: Math.min(item.x1, item.x2) - item.w,
            y: Math.min(item.y1, item.y2) - item.w,
            rw: Math.abs(item.x2 - item.x1) + item.w * 2,
            rh: Math.abs(item.y2 - item.y1) + item.w * 2,
          };
        case 'marker':
          return { x: item.x - item.r, y: item.y - item.r, rw: item.r * 2, rh: item.r * 2 };
        case 'text':
          return { x: item.x, y: item.y, rw: item.bw, rh: item.bh };
        default:
          return { x: item.x, y: item.y, rw: item.rw, rh: item.rh };
      }
    },

    annoHitTest(point) {
      // Von hinten nach vorn: Das zuletzt gezeichnete Element liegt oben.
      for (let index = this.annoItems.length - 1; index >= 0; index -= 1) {
        const item = this.annoItems[index];
        const box = this.annoBounds(item);
        const pad = this.annoSpace.w * 0.006;
        if (point.x >= box.x - pad && point.x <= box.x + box.rw + pad
          && point.y >= box.y - pad && point.y <= box.y + box.rh + pad) {
          return item;
        }
      }

      return null;
    },

    annoBeginSelect(point, event) {
      const handle = event.target?.dataset?.annoHandle;
      const selected = this.annoSelectedItem();
      if (handle && selected) {
        this.annoPushHistory();
        drag = { kind: 'resize', pointerId: event.pointerId, pointerType: event.pointerType, item: selected };

        return;
      }

      const item = this.annoHitTest(point);
      this.annoSelectedId = item?.id ?? '';
      this.annoUpdateSelectionFrame();
      if (item === null) {
        return;
      }
      this.annoColor = item.c;
      this.annoSyncToolbar();
      this.annoPushHistory();
      drag = {
        kind: 'move',
        pointerId: event.pointerId,
        pointerType: event.pointerType,
        item,
        last: point,
      };
    },

    annoTranslate(item, dx, dy) {
      if (item.t === 'pen') {
        item.p = item.p.map((point) => [round1(point[0] + dx), round1(point[1] + dy)]);

        return;
      }
      if (item.t === 'line') {
        item.x1 = round1(item.x1 + dx);
        item.y1 = round1(item.y1 + dy);
        item.x2 = round1(item.x2 + dx);
        item.y2 = round1(item.y2 + dy);

        return;
      }
      item.x = round1(item.x + dx);
      item.y = round1(item.y + dy);
    },

    /** Skaliert wird in Ausbaustufe 1 nur, was ein Rechteck aufspannt. */
    annoResize(item, point) {
      if (!['rect', 'ellipse', 'mask', 'rules'].includes(item.t)) {
        return;
      }
      item.rw = Math.max(2, round1(point.x - item.x));
      item.rh = Math.max(2, round1(point.y - item.y));
    },

    annoUpdateSelectionFrame() {
      const item = this.annoSelectedItem();
      if (item === null) {
        this.annoSelectionStyle = '';

        return;
      }
      const box = this.annoBounds(item);
      const left = (box.x / this.annoSpace.w) * 100;
      const top = (box.y / this.annoSpace.h) * 100;
      const width = (box.rw / this.annoSpace.w) * 100;
      const height = (box.rh / this.annoSpace.h) * 100;
      this.annoSelectionStyle = `left:${left}%;top:${top}%;width:${width}%;height:${height}%;`;
    },

    annoCanResizeSelection() {
      const item = this.annoSelectedItem();

      return item !== null && ['rect', 'ellipse', 'mask', 'rules'].includes(item.t);
    },

    // --- Text -----------------------------------------------------------

    annoOpenTextDialog(anchor, text) {
      if (text === '' && !this.annoCanAdd()) {
        return;
      }
      this.annoTextAnchor = anchor;
      this.annoTextDraft = text;
      this.annoTextOpen = true;
      this.$nextTick(() => this.$refs.annoTextInput?.focus());
    },

    annoEditSelectedText() {
      const item = this.annoSelectedItem();
      if (item === null || item.t !== 'text') {
        return;
      }
      this.annoTextEditId = item.id;
      // Größe des vorhandenen Kastens übernehmen, statt sie still zu
      // überschreiben - auch wenn der Doppelklick aus einem anderen Werkzeug
      // heraus geschah.
      this.annoFontSize = item.s;
      if (this.annoTool === 'text') {
        this.annoWidth = item.s;
      }
      this.annoOpenTextDialog({ x: item.x, y: item.y }, item.text);
    },

    annoConfirmText() {
      const text = this.annoTextDraft.trim();
      this.annoTextOpen = false;
      if (text === '') {
        this.annoTextEditId = '';

        return;
      }

      // Gemessen wird im echten Ziel-SVG, damit Schrift und Größe exakt die
      // des späteren Renderns sind (siehe render.js).
      const svg = this.$refs.annoLayer?.querySelector('svg') ?? this.annoEnsureLayerSvg();
      const box = measureTextBox(svg, text, this.annoFontSize);

      this.annoPushHistory();
      const existing = this.annoItems.find((item) => item.id === this.annoTextEditId);
      if (existing) {
        existing.text = text;
        existing.s = this.annoFontSize;
        existing.c = this.annoColor;
        existing.bw = box.bw;
        existing.bh = box.bh;
      } else {
        this.annoItems.push({
          id: createItemId(),
          t: 'text',
          c: this.annoColor,
          o: this.annoOpacity,
          x: this.annoTextAnchor.x,
          y: this.annoTextAnchor.y,
          s: this.annoFontSize,
          f: '#ffffff',
          bw: box.bw,
          bh: box.bh,
          text,
        });
      }
      this.annoTextEditId = '';
      this.annoRenderAll();
    },

    annoCancelText() {
      this.annoTextOpen = false;
      this.annoTextDraft = '';
      this.annoTextEditId = '';
    },

    /**
     * Ohne Elemente gibt es noch kein Overlay-SVG - zum Messen wird eines
     * angelegt und bleibt danach als leere Hülle stehen.
     */
    annoEnsureLayerSvg() {
      const host = this.$refs.annoLayer;
      let svg = host.querySelector('svg');
      if (svg === null) {
        svg = renderOverlay(host, {
          v: 1,
          space: this.annoSpace,
          items: [{ id: createItemId(), t: 'mask', c: '#000000', o: 0.05, x: 0, y: 0, rw: 1, rh: 1 }],
        });
        svg.replaceChildren();
      }

      return svg;
    },

    // --- Verlauf, Löschen, Tastatur --------------------------------------

    annoPushHistory() {
      this.annoHistory.push(JSON.stringify(this.annoItems));
      if (this.annoHistory.length > HISTORY_LIMIT) {
        this.annoHistory.shift();
      }
      this.annoFuture = [];
      this.annoDirty = true;
    },

    annoUndoStep() {
      const previous = this.annoHistory.pop();
      if (previous === undefined) {
        return;
      }
      this.annoFuture.push(JSON.stringify(this.annoItems));
      this.annoItems = JSON.parse(previous);
      this.annoSelectedId = '';
      this.annoUpdateSelectionFrame();
      this.annoRenderAll();
    },

    annoRedoStep() {
      const next = this.annoFuture.pop();
      if (next === undefined) {
        return;
      }
      this.annoHistory.push(JSON.stringify(this.annoItems));
      this.annoItems = JSON.parse(next);
      this.annoSelectedId = '';
      this.annoUpdateSelectionFrame();
      this.annoRenderAll();
    },

    annoDeleteSelected() {
      if (this.annoSelectedId === '') {
        return;
      }
      this.annoPushHistory();
      this.annoItems = this.annoItems.filter((item) => item.id !== this.annoSelectedId);
      this.annoSelectedId = '';
      this.annoUpdateSelectionFrame();
      this.annoRenderAll();
    },

    annoClearAll() {
      if (this.annoItems.length === 0 || !window.confirm('Alle Bildnotizen dieses Bildes entfernen?')) {
        return;
      }
      this.annoPushHistory();
      this.annoItems = [];
      this.annoSelectedId = '';
      this.annoUpdateSelectionFrame();
      this.annoRenderAll();
    },

    annoKeydown(event) {
      if (!this.annoOpen || this.annoTextOpen) {
        return;
      }
      if (event.key === 'Delete' || event.key === 'Backspace') {
        event.preventDefault();
        this.annoDeleteSelected();

        return;
      }
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') {
        event.preventDefault();
        if (event.shiftKey) {
          this.annoRedoStep();
        } else {
          this.annoUndoStep();
        }
      }
    },

    // --- Darstellung ------------------------------------------------------

    annoRenderAll() {
      const host = this.$refs.annoLayer;
      if (!host) {
        return;
      }
      renderOverlay(host, { v: 1, space: this.annoSpace, items: this.annoItems });
      this.annoUpdateSelectionFrame();
    },

    annoRenderPreview(item) {
      const host = this.$refs.annoPreview;
      if (!host) {
        return;
      }
      renderOverlay(host, item === null
        ? null
        : { v: 1, space: this.annoSpace, items: [item] });
    },

    /**
     * Misst die Bühne so, dass das Bild unverzerrt in den Sichtbereich
     * passt - im Seitenverhältnis des Annotationsraums, wie das Bild auch in
     * der Notiz liegt. Reines CSS (aspect-ratio plus max-height) bricht das
     * Verhältnis, sobald max-height greift: Die Breite bleibt definitiv und
     * wird nicht nachverhandelt. Deshalb steht die Rechnung hier.
     */
    annoFitStage() {
      const viewport = this.$refs.annoViewport;
      const stage = this.$refs.annoStage;
      if (!viewport || !stage || this.annoSpace.w < 1 || this.annoSpace.h < 1) {
        return;
      }
      const styles = window.getComputedStyle(viewport);
      const availableWidth = viewport.clientWidth
        - Number.parseFloat(styles.paddingLeft || '0')
        - Number.parseFloat(styles.paddingRight || '0');
      const availableHeight = viewport.clientHeight
        - Number.parseFloat(styles.paddingTop || '0')
        - Number.parseFloat(styles.paddingBottom || '0');
      const ratio = this.annoSpace.w / this.annoSpace.h;
      let width = availableWidth;
      let height = width / ratio;
      if (height > availableHeight) {
        height = availableHeight;
        width = height * ratio;
      }

      stage.style.width = `${Math.max(1, Math.floor(width))}px`;
      stage.style.height = `${Math.max(1, Math.floor(height))}px`;
    },

    annoWatchStageSize() {
      this.annoUnwatchStageSize();
      stageResizeHandler = () => this.annoFitStage();
      window.addEventListener('resize', stageResizeHandler);
    },

    annoUnwatchStageSize() {
      if (stageResizeHandler !== null) {
        window.removeEventListener('resize', stageResizeHandler);
        stageResizeHandler = null;
      }
    },

    annoCountLabel() {
      return `${this.annoItems.length} von ${MAX_ITEMS} Elementen`;
    },
  };
}
