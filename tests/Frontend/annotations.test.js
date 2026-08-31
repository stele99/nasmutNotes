import assert from 'node:assert/strict';
import test from 'node:test';

const { normalizeAnnotations, serializeAnnotations, annotationTexts, simplifyPath, MAX_ITEMS }
  = await import('../../resources/js/editor/annotations/schema.js');
const { buildOverlayMarkup, dimLabelAngle }
  = await import('../../resources/js/editor/annotations/render.js');
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
    addEventListener() {},
    removeEventListener() {},
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

const dim = {
  id: 'dim00001', t: 'dim', c: '#e11d48', w: 6,
  x1: 100, y1: 400, x2: 700, y2: 400,
  s: 40, bw: 120, bh: 50, f: '#ffffff', text: '3,20 m',
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

test('das Maßband behält seine Länge, verträgt aber auch keine', () => {
  const withLabel = normalizeAnnotations(annotations([dim]));
  assert.equal(withLabel.items[0].text, '3,20 m');

  // Zwischen Ziehen und Eintragen steht das Band ohne Beschriftung da; das
  // Feld fällt weg, statt das ganze Element zu verwerfen.
  const bare = normalizeAnnotations(annotations([{ ...dim, text: '' }]));
  assert.equal(bare.items.length, 1);
  assert.equal('text' in bare.items[0], false);

  // Mehrzeiliges und überlanges wird auf eine kurze Zeile gebracht.
  const messy = normalizeAnnotations(annotations([{ ...dim, text: ' 3,20\n\tm  ' }]));
  assert.equal(messy.items[0].text, '3,20 m');
  const long = normalizeAnnotations(annotations([{ ...dim, text: 'x'.repeat(200) }]));
  assert.equal(long.items[0].text.length, 40);

  // Pflichtzahlen gelten wie bei jedem anderen Typ.
  assert.equal(normalizeAnnotations(annotations([{ ...dim, s: 0 }])), null);
  assert.equal(normalizeAnnotations(annotations([{ ...dim, x2: Number.NaN }])), null);
});

test('das Maßband zeichnet Maßlinie, Endstriche und die gedrehte Länge', () => {
  const markup = buildOverlayMarkup(annotations([dim]));
  assert.equal((markup.match(/<path /g) ?? []).length, 3);
  assert.match(markup, /<g transform="translate\(400 400\) rotate\(0\)">/);
  assert.match(markup, /text-anchor="middle"[^>]*>3,20 m<\/text>/);

  // Ohne Beschriftung bleiben nur die drei Striche.
  const bare = buildOverlayMarkup(annotations([{ ...dim, text: '' }]));
  assert.equal((bare.match(/<path /g) ?? []).length, 3);
  assert.equal(bare.includes('<g transform'), false);

  // Die Länge steht nie auf dem Kopf: Der Winkel bleibt zwischen -90 und 90.
  assert.equal(dimLabelAngle({ x1: 700, y1: 400, x2: 100, y2: 400 }), 0);
  assert.equal(dimLabelAngle({ x1: 0, y1: 100, x2: 100, y2: 0 }), -45);
  assert.equal(dimLabelAngle({ x1: 100, y1: 0, x2: 0, y2: 100 }), -45);
});

test('liefert Texte in Lesereihenfolge', () => {
  const text = {
    id: 'text0001', t: 'text', c: '#111827', x: 1, y: 2, s: 20,
    f: null, bw: 100, bh: 20, text: 'Zuerst',
  };
  assert.deepEqual(annotationTexts(annotations([text, line])), ['Zuerst']);
  // Die Länge am Maßband ist Text auf dem Bild und damit auffindbar.
  assert.deepEqual(annotationTexts(annotations([text, dim])), ['Zuerst', '3,20 m']);
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

test('die Bühne misst sich unverzerrt in den Sichtbereich', () => {
  withFakeStorage(() => {
    globalThis.window.getComputedStyle = () => ({
      paddingLeft: '16px',
      paddingRight: '16px',
      paddingTop: '16px',
      paddingBottom: '16px',
    });
    const mixin = imageAnnotatorMixin();
    const stage = { style: {} };
    mixin.$refs = { annoViewport: { clientWidth: 1000, clientHeight: 600 }, annoStage: stage };

    // Querformat (1000:800): Die Höhe begrenzt (968/1.25 = 774.4 > 568),
    // die Breite folgt dem Verhältnis: 568 × 1.25 = 710.
    mixin.annoSpace = { w: 1000, h: 800 };
    mixin.annoFitStage();
    assert.equal(stage.style.width, '710px');
    assert.equal(stage.style.height, '568px');

    // Hochformat (800:1000): ebenfalls höhenbegrenzt, Breite 568 × 0.8.
    mixin.annoSpace = { w: 800, h: 1000 };
    mixin.annoFitStage();
    assert.equal(stage.style.width, '454px');
    assert.equal(stage.style.height, '568px');

    // Sehr breites Bild (2000:500): Die Breite begrenzt, Höhe folgt.
    mixin.annoSpace = { w: 2000, h: 500 };
    mixin.annoFitStage();
    assert.equal(stage.style.width, '968px');
    assert.equal(stage.style.height, '242px');

    delete globalThis.window.getComputedStyle;
  });
});

test('der Stift-Knopf am Bild öffnet den Annotationseditor an der Knotenposition', () => {
  withFakeStorage(() => {
    globalThis.HTMLElement = class {};
    const imageNode = {
      type: { name: 'image' },
      attrs: { src: '/api/attachments/' + 'a'.repeat(64), alt: 'Foto', width: 800, height: 600 },
    };
    let handler = null;
    const editor = {
      view: {
        dom: {
          addEventListener(name, listener) {
            if (name === 'open-image-annotator') {
              handler = listener;
            }
          },
        },
      },
      state: {
        doc: {
          nodeAt: (pos) => (pos === 5 ? imageNode : null),
        },
      },
    };

    const mixin = imageAnnotatorMixin();
    mixin.canEditPage = true;
    mixin.isEncrypted = () => false;
    mixin.$nextTick = () => {};
    mixin.$refs = {};
    mixin.annoBindNodeViewEntry(editor);
    assert.ok(handler !== null);

    handler({ detail: { pos: 5 } });
    assert.equal(mixin.annoOpen, true);
    assert.equal(mixin.annoPos, 5);
    assert.equal(mixin.annoSpace.w, 800);

    // Ohne Schreibrecht oder ohne Bildknoten bleibt alles zu.
    mixin.closeImageAnnotator();
    mixin.canEditPage = false;
    handler({ detail: { pos: 5 } });
    assert.equal(mixin.annoOpen, false);
    mixin.canEditPage = true;
    handler({ detail: { pos: 9 } });
    assert.equal(mixin.annoOpen, false);

    delete globalThis.HTMLElement;
  });
});

test('vorhandene Annotationen starten im Auswählen-Werkzeug', () => {
  withFakeStorage(() => {
    globalThis.HTMLElement = class {};
    const mixin = imageAnnotatorMixin();
    mixin.canEditPage = true;
    mixin.isEncrypted = () => false;
    mixin.$nextTick = () => {};
    mixin.$refs = {};
    const attrs = { src: '/api/attachments/' + 'a'.repeat(64), alt: 'Foto', width: 800, height: 600 };

    mixin.annoBegin(1, { type: { name: 'image' }, attrs: { ...attrs, annotations: null } });
    assert.equal(mixin.annoTool, 'pen');

    mixin.annoBegin(1, {
      type: { name: 'image' },
      attrs: {
        ...attrs,
        annotations: {
          v: 1,
          space: { w: 800, h: 600 },
          items: [{ id: 'abcd1234', t: 'line', c: '#e11d48', w: 4, x1: 10, y1: 20, x2: 30, y2: 40, head: 'end' }],
        },
      },
    });
    assert.equal(mixin.annoTool, 'select');

    delete globalThis.HTMLElement;
  });
});

test('Antippen ohne Zug erzeugt keinen Undo-Schritt und kein Dirty-Flag', () => {
  withFakeStorage(() => {
    const mixin = imageAnnotatorMixin();
    mixin.$refs = {
      annoStage: {
        setPointerCapture() {},
        releasePointerCapture() {},
        getBoundingClientRect: () => ({ left: 0, top: 0, width: 1000, height: 800 }),
      },
    };
    mixin.annoSpace = { w: 1000, h: 800 };
    mixin.annoOpen = true;
    mixin.annoTool = 'select';
    mixin.annoItems = [{ id: 'rect0001', t: 'rect', c: '#2563eb', w: 4, x: 100, y: 100, rw: 200, rh: 100, f: null }];

    const down = {
      pointerId: 1, pointerType: 'mouse', button: 0, clientX: 150, clientY: 140,
      target: {}, preventDefault() {},
    };
    mixin.annoPointerDown.call(mixin, down);
    assert.equal(mixin.annoSelectedId, 'rect0001');

    mixin.annoPointerUp.call(mixin, { pointerId: 1 });
    assert.equal(mixin.annoHistory.length, 0);
    assert.equal(mixin.annoDirty, false);

    // Erst der Zug wird zur Änderung - mit genau einem Undo-Schritt.
    mixin.annoPointerDown.call(mixin, down);
    mixin.annoPointerMove.call(mixin, {
      pointerId: 1, pointerType: 'mouse', clientX: 170, clientY: 140, preventDefault() {},
    });
    mixin.annoPointerUp.call(mixin, { pointerId: 1 });
    assert.equal(mixin.annoHistory.length, 1);
    assert.equal(mixin.annoItems[0].x, 120);
    assert.equal(mixin.annoDirty, true);
  });
});

test('Pfeiltasten verschieben die Auswahl als ein Undo-Schritt', () => {
  withFakeStorage(() => {
    const mixin = imageAnnotatorMixin();
    mixin.$refs = {};
    mixin.annoSpace = { w: 1000, h: 800 };
    mixin.annoOpen = true;
    mixin.annoTool = 'select';
    mixin.annoItems = [{ id: 'abcd1234', t: 'line', c: '#e11d48', w: 4, x1: 10, y1: 20, x2: 30, y2: 40, head: 'end' }];
    mixin.annoSelectedId = 'abcd1234';

    let prevented = 0;
    const press = (key, shiftKey = false) => mixin.annoKeydown.call(mixin, {
      key, shiftKey, preventDefault: () => { prevented += 1; },
    });

    press('ArrowLeft');
    press('ArrowUp');
    assert.deepEqual([mixin.annoItems[0].x1, mixin.annoItems[0].y1], [9, 19]);
    assert.equal(mixin.annoHistory.length, 1);

    press('ArrowRight', true);
    assert.equal(mixin.annoItems[0].x1, 19);
    assert.equal(mixin.annoHistory.length, 1);

    // Ein Undo-Schritt stellt den Ausgangszustand der ganzen Folge wieder her.
    mixin.annoUndoStep();
    assert.deepEqual([mixin.annoItems[0].x1, mixin.annoItems[0].y1], [10, 20]);

    // Ohne Auswahl bewegen die Pfeiltasten nichts und fangen nichts ab.
    mixin.annoSelectedId = '';
    const before = prevented;
    press('ArrowLeft');
    assert.equal(prevented, before);
  });
});

test('der Hover-Rahmen zeigt im Auswählen-Werkzeug das Element unter dem Zeiger', () => {
  withFakeStorage(() => {
    const mixin = imageAnnotatorMixin();
    mixin.$refs = {
      annoStage: { getBoundingClientRect: () => ({ left: 0, top: 0, width: 1000, height: 800 }) },
    };
    mixin.annoSpace = { w: 1000, h: 800 };
    mixin.annoOpen = true;
    mixin.annoTool = 'select';
    mixin.annoItems = [{ id: 'rect0001', t: 'rect', c: '#2563eb', w: 4, x: 100, y: 200, rw: 100, rh: 50, f: null }];

    mixin.annoPointerMove.call(mixin, { pointerId: 1, pointerType: 'mouse', clientX: 150, clientY: 225 });
    assert.match(mixin.annoHoverStyle, /left:10%;/);
    assert.match(mixin.annoHoverStyle, /top:25%;/);
    assert.equal(mixin.annoStageClass(), 'anno-stage-hover');

    mixin.annoPointerMove.call(mixin, { pointerId: 1, pointerType: 'mouse', clientX: 5, clientY: 5 });
    assert.equal(mixin.annoHoverStyle, '');
    assert.equal(mixin.annoStageClass(), '');

    // In Zeichenwerkzeugen gibt es keinen Hover-Rahmen.
    mixin.annoTool = 'pen';
    mixin.annoPointerMove.call(mixin, { pointerId: 1, pointerType: 'mouse', clientX: 150, clientY: 225 });
    assert.equal(mixin.annoHoverStyle, '');
  });
});

test('das Maßband entsteht erst mit der eingetragenen Länge', () => {
  withFakeStorage(() => {
    withFakeCanvas(({ host }) => {
      const mixin = imageAnnotatorMixin();
      mixin.$nextTick = (run) => run();
      mixin.$refs = {
        annoLayer: host,
        annoPreview: host,
        annoStage: {
          setPointerCapture() {},
          releasePointerCapture() {},
          getBoundingClientRect: () => ({ left: 0, top: 0, width: 1000, height: 800 }),
        },
      };
      mixin.annoSpace = { w: 1000, h: 800 };
      mixin.annoOpen = true;
      mixin.annoSelectTool.call(mixin, { currentTarget: { dataset: { tool: 'measure' } } });

      const draw = () => {
        mixin.annoPointerDown.call(mixin, {
          pointerId: 1, pointerType: 'mouse', button: 0,
          clientX: 100, clientY: 400, target: {}, preventDefault() {},
        });
        mixin.annoPointerMove.call(mixin, {
          pointerId: 1, pointerType: 'mouse', clientX: 700, clientY: 400, preventDefault() {},
        });
        mixin.annoPointerUp.call(mixin, { pointerId: 1 });
      };

      // Nach dem Zug steht das Band noch außerhalb des Dokuments: kein
      // Element, kein Undo-Schritt, nur die Frage nach der Länge.
      draw();
      assert.equal(mixin.annoLengthOpen, true);
      assert.equal(mixin.annoItems.length, 0);
      assert.equal(mixin.annoHistory.length, 0);

      mixin.annoLengthDraft = ' 3,20  m ';
      mixin.annoConfirmLength();
      assert.equal(mixin.annoLengthOpen, false);
      assert.deepEqual(
        [mixin.annoItems.length, mixin.annoItems[0].t, mixin.annoItems[0].text],
        [1, 'dim', '3,20 m'],
      );
      assert.deepEqual(
        [mixin.annoItems[0].x1, mixin.annoItems[0].x2, mixin.annoItems[0].bw, mixin.annoItems[0].bh],
        [100, 700, 120, 50],
      );
      assert.equal(mixin.annoHistory.length, 1);

      // Abbrechen verwirft das frisch gezogene Band ganz.
      draw();
      mixin.annoCancelLength();
      assert.equal(mixin.annoItems.length, 1);

      // Doppelklick auf ein vorhandenes Band öffnet die Länge zum Ändern;
      // eine geleerte Länge nimmt nur die Beschriftung weg.
      mixin.annoSelectedId = mixin.annoItems[0].id;
      mixin.annoEditSelectedText();
      assert.equal(mixin.annoLengthDraft, '3,20 m');
      mixin.annoLengthDraft = '';
      mixin.annoConfirmLength();
      assert.equal(mixin.annoItems.length, 1);
      assert.deepEqual(
        [mixin.annoItems[0].text, mixin.annoItems[0].bw, mixin.annoItems[0].bh],
        ['', 0, 0],
      );

      // Endpunkt-Griffe wie bei der Linie, kein Rahmengriff.
      assert.equal(mixin.annoHasHandle('p1'), true);
      assert.equal(mixin.annoHasHandle('p2'), true);
      assert.equal(mixin.annoHasHandle('se'), false);
      mixin.annoResizeHandle(mixin.annoItems[0], 'p2', { x: 500, y: 300 });
      assert.deepEqual([mixin.annoItems[0].x2, mixin.annoItems[0].y2], [500, 300]);
      mixin.annoTranslate(mixin.annoItems[0], 10, -5);
      assert.deepEqual([mixin.annoItems[0].x1, mixin.annoItems[0].y1], [110, 395]);
    });
  });
});

test('Ziehgriffe skalieren jeden Elementtyp', () => {
  withFakeStorage(() => {
    withFakeCanvas(({ host }) => {
      const mixin = imageAnnotatorMixin();
      mixin.$refs = { annoLayer: host, annoPreview: host };

      const rect = { id: 'rect0001', t: 'rect', c: '#2563eb', w: 4, x: 10, y: 10, rw: 100, rh: 50, f: null };
      mixin.annoResizeHandle(rect, 'se', { x: 210, y: 110 });
      assert.deepEqual([rect.rw, rect.rh], [200, 100]);
      mixin.annoResizeHandle(rect, 'nw', { x: 0, y: 0 });
      assert.deepEqual([rect.x, rect.y, rect.rw, rect.rh], [0, 0, 210, 110]);

      const lineItem = { id: 'abcd1234', t: 'line', c: '#e11d48', w: 4, x1: 10, y1: 20, x2: 30, y2: 40, head: 'end' };
      mixin.annoResizeHandle(lineItem, 'p1', { x: 5, y: 8 });
      assert.deepEqual([lineItem.x1, lineItem.y1], [5, 8]);
      mixin.annoResizeHandle(lineItem, 'p2', { x: 100, y: 200 });
      assert.deepEqual([lineItem.x2, lineItem.y2], [100, 200]);

      const pen = { id: 'pen00001', t: 'pen', c: '#111827', w: 6, p: [[100, 100], [200, 100], [100, 200]] };
      mixin.annoResizeHandle(pen, 'se', { x: 300, y: 300 });
      assert.deepEqual(pen.p, [[100, 100], [300, 100], [100, 300]]);

      const textItem = {
        id: 'text0001', t: 'text', c: '#111827', x: 100, y: 100,
        s: 40, bw: 200, bh: 50, f: null, text: 'Hallo',
      };
      mixin.annoResizeHandle(textItem, 'se', { x: 400, y: 200 });
      assert.equal(textItem.s, 60);
      assert.equal(textItem.bw, 120);
      assert.equal(textItem.bh, 75);

      const markerItem = { id: 'mark0001', t: 'marker', c: '#2563eb', x: 500, y: 400, r: 40, n: 1 };
      mixin.annoResizeHandle(markerItem, 'se', { x: 540, y: 400 });
      assert.equal(markerItem.r, 40);
      mixin.annoResizeHandle(markerItem, 'se', { x: 580, y: 400 });
      assert.equal(markerItem.r, 80);

      // Welcher Typ bietet welche Griffe?
      mixin.annoSpace = { w: 1000, h: 800 };
      mixin.annoItems = [lineItem];
      mixin.annoSelectedId = 'abcd1234';
      assert.equal(mixin.annoHasHandle('p1'), true);
      assert.equal(mixin.annoHasHandle('se'), false);
      mixin.annoItems = [rect];
      mixin.annoSelectedId = 'rect0001';
      assert.equal(mixin.annoHasHandle('nw'), true);
      assert.equal(mixin.annoHasHandle('p2'), false);
      assert.equal(mixin.annoHasHandle('se'), true);
    });
  });
});
