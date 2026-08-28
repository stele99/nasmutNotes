import assert from 'node:assert/strict';
import test from 'node:test';

const { normalizeAnnotations, serializeAnnotations, annotationTexts, simplifyPath, MAX_ITEMS }
  = await import('../../resources/js/editor/annotations/schema.js');
const { buildOverlayMarkup } = await import('../../resources/js/editor/annotations/render.js');

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
