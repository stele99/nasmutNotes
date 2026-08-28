import assert from 'node:assert/strict';
import test from 'node:test';

const { normalizeAnnotations, serializeAnnotations, annotationTexts, simplifyPath, MAX_ITEMS }
  = await import('../../resources/js/editor/annotations/schema.js');
const { buildOverlayMarkup } = await import('../../resources/js/editor/annotations/render.js');
const { imageAnnotatorMixin, readStoredWidths, storedToolWidth, storeToolWidth }
  = await import('../../resources/js/editor/annotations/annotator.js');

/**
 * Setzt einen minimalen Browser-Speicher ein und stellt den Zustand danach
 * wieder her - der Mixin-Code selbst bleibt DOM-frei testbar.
 */
function withFakeStorage(run) {
  const store = new Map();
  const previous = globalThis.window;
  globalThis.window = {
    localStorage: {
      getItem: (key) => (store.has(key) ? store.get(key) : null),
      setItem: (key, value) => store.set(key, String(value)),
    },
  };
  try {
    return run();
  } finally {
    if (previous === undefined) {
      delete globalThis.window;
    } else {
      globalThis.window = previous;
    }
  }
}

function annotations(items) {
  return { v: 1, space: { w: 1000, h: 800 }, items };
}

const line = {
  id: 'abcd1234', t: 'line', c: '#e11d48', w: 4,
  x1: 10, y1: 20, x2: 30, y2: 40, head: 'end',
};

test('verwirft Elemente mit unerlaubter Farbe, Typ oder Zahl', () => {
  assert.equal(normalizeAnnotations(annotations([{ ...line, c: 'red' }])), null);
  assert.equal(normalizeAnnotations(annotations([{ ...line, c: 'url(#x)' }])), null);
  assert.equal(normalizeAnnotations(annotations([{ ...line, t: 'script' }])), null);
  assert.equal(normalizeAnnotations(annotations([{ ...line, x1: Number.NaN }])), null);
  assert.equal(normalizeAnnotations(annotations([{ ...line, x1: 1e9 }])), null);
  assert.equal(normalizeAnnotations(annotations([{ ...line, w: 0 }])), null);
});

test('verwirft eine unbrauchbare Bezugsgröße und eine fremde Fassung', () => {
  assert.equal(normalizeAnnotations({ v: 2, space: { w: 10, h: 10 }, items: [line] }), null);
  assert.equal(normalizeAnnotations({ v: 1, space: { w: 0, h: 10 }, items: [line] }), null);
  assert.equal(normalizeAnnotations({ v: 1, space: { w: 99999, h: 10 }, items: [line] }), null);
});

test('rundet Koordinaten auf eine Nachkommastelle und deckelt die Elementzahl', () => {
  const value = normalizeAnnotations(annotations([{ ...line, x1: 10.06 }]));
  assert.equal(value.items[0].x1, 10.1);

  const many = Array.from({ length: MAX_ITEMS + 20 }, (_item, index) => ({
    ...line, id: `a${String(index).padStart(7, '0')}`.slice(0, 8),
  }));
  assert.equal(normalizeAnnotations(annotations(many)).items.length, MAX_ITEMS);
});

test('Douglas-Peucker behält Anfang und Ende und dünnt die Mitte aus', () => {
  const points = Array.from({ length: 200 }, (_point, index) => [index, 0]);
  const simplified = simplifyPath(points, 1);
  assert.deepEqual(simplified[0], [0, 0]);
  assert.deepEqual(simplified.at(-1), [199, 0]);
  assert.ok(simplified.length < 10);
});

test('liefert Texte in Lesereihenfolge', () => {
  const text = {
    id: 'text0001', t: 'text', c: '#111827', x: 1, y: 2, s: 20,
    f: null, bw: 100, bh: 20, text: 'Zuerst',
  };
  assert.deepEqual(annotationTexts(annotations([text, line])), ['Zuerst']);
});

test('erzeugt für jeden Typ Markup und für unbekannte Typen keines', () => {
  const markup = buildOverlayMarkup(annotations([line]));
  assert.match(markup, /^<svg /);
  assert.match(markup, /viewBox="0 0 1000 800"/);
  assert.match(markup, /<path /);
  assert.equal(buildOverlayMarkup(annotations([{ ...line, t: 'iframe' }])), '');
  assert.equal(buildOverlayMarkup(null), '');
});

test('maskiert Text statt ihn als Markup auszugeben', () => {
  const text = {
    id: 'text0002', t: 'text', c: '#111827', x: 0, y: 0, s: 20,
    f: '#ffffff', bw: 10, bh: 10, text: '<script>alert("x")</script> & "Anführung"',
  };
  const markup = buildOverlayMarkup(annotations([text]));
  assert.ok(!markup.includes('<script>'));
  assert.match(markup, /&lt;script&gt;/);
  assert.match(markup, /&quot;/);
});

test('trimmt bei Budgetüberschreitung von hinten statt alles zu verwerfen', () => {
  const long = Array.from({ length: 150 }, (_item, index) => ({
    ...line,
    id: `b${String(index).padStart(7, '0')}`.slice(0, 8),
    t: 'pen',
    p: Array.from({ length: 200 }, (_point, step) => [step, step]),
  }));
  const value = serializeAnnotations(annotations(long));
  assert.ok(value.items.length > 0);
  assert.ok(value.items.length < 150);
});

test('merkt sich die Strichstärke je Werkzeug im Browser-Speicher', () => {
  withFakeStorage(() => {
    storeToolWidth('pen', 12);
    storeToolWidth('highlighter', 40);

    const mixin = imageAnnotatorMixin();
    assert.equal(mixin.annoWidth, 12);

    mixin.$refs = {};
    mixin.annoSelectTool.call(mixin, { currentTarget: { dataset: { tool: 'highlighter' } } });
    assert.equal(mixin.annoWidth, 40);
    assert.equal(mixin.annoOpacity, 0.4);

    mixin.annoWidthInput.call(mixin, { target: { value: '20' } });
    assert.equal(readStoredWidths().highlighter, 20);

    const fresh = imageAnnotatorMixin();
    fresh.$refs = {};
    fresh.annoSelectTool.call(fresh, { currentTarget: { dataset: { tool: 'highlighter' } } });
    assert.equal(fresh.annoWidth, 20);
  });
});

test('fällt bei kaputtem oder ungültigem Speicherstand auf die Voreinstellung zurück', () => {
  withFakeStorage(() => {
    globalThis.window.localStorage.setItem('notes-anno-widths', '{kaputt');
    assert.deepEqual(readStoredWidths(), {});
    assert.equal(storedToolWidth('pen', 6), 6);

    globalThis.window.localStorage.setItem(
      'notes-anno-widths',
      JSON.stringify({ pen: 500, select: 9, line: 'dick' }),
    );
    assert.deepEqual(readStoredWidths(), {});
    assert.equal(storedToolWidth('pen', 6), 6);

    storeToolWidth('pen', 500);
    assert.equal(readStoredWidths().pen, 80);
    storeToolWidth('pen', 0.4);
    assert.equal(readStoredWidths().pen, 1);
  });
});

test('ohne Browser-Speicher wird der Vorgabewert benutzt', () => {
  assert.deepEqual(readStoredWidths(), {});
  assert.equal(storedToolWidth('pen', 6), 6);
  storeToolWidth('pen', 12);
});

/**
 * Ersetzt DOMParser und document durch Stümpfe, damit der Textpfad des
 * Mixins (Messen im Ziel-SVG, Rendern des Overlays) ohne Browser läuft.
 */
function withFakeCanvas(run) {
  const probe = {
    setAttribute() {},
    appendChild() {},
    remove() {},
    getBBox: () => ({ width: 120, height: 30 }),
  };
  const svg = { appendChild() {}, replaceChildren() {} };
  const host = { querySelector: () => svg, replaceChildren() {}, appendChild() {} };
  const previous = { document: globalThis.document, DOMParser: globalThis.DOMParser };
  globalThis.document = {
    createElementNS: () => probe,
    importNode: () => ({}),
  };
  globalThis.DOMParser = class {
    parseFromString() {
      return { querySelector: () => null, documentElement: {} };
    }
  };
  try {
    return run({ host, svg });
  } finally {
    if (previous.document === undefined) {
      delete globalThis.document;
    } else {
      globalThis.document = previous.document;
    }
    if (previous.DOMParser === undefined) {
      delete globalThis.DOMParser;
    } else {
      globalThis.DOMParser = previous.DOMParser;
    }
  }
}

test('der Schieberegler legt beim Textwerkzeug die Schriftgröße fest', () => {
  withFakeStorage(() => {
    withFakeCanvas(({ host }) => {
      const mixin = imageAnnotatorMixin();
      mixin.$refs = { annoLayer: host, annoPreview: host };

      mixin.annoSelectTool.call(mixin, { currentTarget: { dataset: { tool: 'text' } } });
      assert.equal(mixin.annoWidth, 44);
      assert.equal(mixin.annoFontSize, 44);
      assert.equal(mixin.annoWidthLabel(), 'Schriftgröße');

      mixin.annoWidthInput.call(mixin, { target: { value: '72' } });
      assert.equal(mixin.annoFontSize, 72);
      assert.equal(readStoredWidths().text, 72);

      mixin.annoTextAnchor = { x: 10, y: 20 };
      mixin.annoTextDraft = 'Hallo';
      mixin.annoConfirmText();
      assert.equal(mixin.annoItems[0].s, 72);

      const fresh = imageAnnotatorMixin();
      assert.equal(fresh.annoWidthLabel(), 'Strichstärke');
      fresh.$refs = { annoLayer: host, annoPreview: host };
      fresh.annoSelectTool.call(fresh, { currentTarget: { dataset: { tool: 'text' } } });
      assert.equal(fresh.annoWidth, 72);
      assert.equal(fresh.annoWidthLabel(), 'Schriftgröße');
    });
  });
});

test('Marker und Zeilenlinien bleiben unabhängig vom Textregler', () => {
  withFakeStorage(() => {
    const mixin = imageAnnotatorMixin();
    mixin.$refs = {};

    mixin.annoSelectTool.call(mixin, { currentTarget: { dataset: { tool: 'text' } } });
    mixin.annoWidthInput.call(mixin, { target: { value: '72' } });

    mixin.annoSelectTool.call(mixin, { currentTarget: { dataset: { tool: 'marker' } } });
    const marker = mixin.annoCreateItem.call(mixin, { x: 5, y: 5 }, { pointerType: 'mouse' });
    assert.equal(marker.r, 40);

    mixin.annoSelectTool.call(mixin, { currentTarget: { dataset: { tool: 'rules' } } });
    const rules = mixin.annoCreateItem.call(mixin, { x: 5, y: 5 }, { pointerType: 'mouse' });
    assert.equal(rules.gap, 70);
  });
});
