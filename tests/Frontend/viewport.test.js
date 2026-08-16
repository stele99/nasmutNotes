import assert from 'node:assert/strict';
import test from 'node:test';

import { initViewportMetrics, viewportShift } from '../../resources/js/viewport.js';

function createWindow({ innerHeight, height, offsetTop, pageTop = offsetTop, scrollY = 0, scrollerTop = 0 }) {
  const listeners = { viewport: [], window: [] };
  const properties = new Map();
  const timers = [];
  // Der Scroll-Container bleibt, wo er ist - ausgeglichen wird nur die klebende
  // Leiste in ihm. Seine Oberkante ändert sich durch den Ausgleich also nicht.
  const scroller = { getBoundingClientRect: () => ({ top: scrollerTop }) };

  return {
    innerHeight,
    scrollY,
    visualViewport: {
      height,
      offsetTop,
      pageTop,
      addEventListener(name, handler) {
        listeners.viewport.push([name, handler]);
      },
    },
    document: {
      querySelector() {
        return scroller;
      },
      documentElement: {
        dataset: {},
        style: {
          setProperty(name, value) {
            properties.set(name, value);
          },
        },
      },
    },
    addEventListener(name, handler) {
      listeners.window.push([name, handler]);
    },
    // Synchron, damit der Test ohne Frame-Takt auskommt.
    requestAnimationFrame(callback) {
      callback();
      return 1;
    },
    // Nur aufzeichnen: Das Nachmessen wird im Test von Hand ausgelöst.
    setTimeout(callback) {
      timers.push(callback);
      return timers.length;
    },
    clearTimeout() {},
    properties,
    listeners,
    timers,
    notifyViewport() {
      for (const [, handler] of listeners.viewport) {
        handler();
      }
    },
    get root() {
      return this.document.documentElement;
    },
  };
}

test('reports the height covered by the keyboard', () => {
  const win = createWindow({ innerHeight: 800, height: 460, offsetTop: 60 });

  initViewportMetrics(win);

  assert.equal(win.properties.get('--keyboard-inset'), '340px');
  assert.equal(win.root.dataset.keyboard, 'open');
});

test('ignores the address bar sliding in and out', () => {
  const win = createWindow({ innerHeight: 800, height: 740, offsetTop: 0 });

  initViewportMetrics(win);

  assert.equal(win.properties.get('--keyboard-inset'), '0px');
  assert.equal(win.root.dataset.keyboard, 'closed');
});

test('never reports a negative inset', () => {
  const win = createWindow({ innerHeight: 800, height: 820, offsetTop: 0 });

  initViewportMetrics(win);

  assert.equal(win.properties.get('--keyboard-inset'), '0px');
});

test('counts the keyboard regardless of how far Safari shifted the view', () => {
  // Ein mitgerechneter Versatz drückte die Summe unter die Schwelle: Die
  // Tastatur galt dann als eingeklappt und die Anwendung reichte hinter sie.
  const wide = createWindow({ innerHeight: 800, height: 420, offsetTop: 330 });

  initViewportMetrics(wide);

  assert.equal(wide.properties.get('--keyboard-inset'), '380px');
  assert.equal(wide.root.dataset.keyboard, 'open');
});

test('recalculates when the visual viewport changes', () => {
  const win = createWindow({ innerHeight: 800, height: 800, offsetTop: 0 });

  initViewportMetrics(win);
  assert.equal(win.properties.get('--keyboard-inset'), '0px');

  win.visualViewport.height = 460;
  win.notifyViewport();

  assert.equal(win.properties.get('--keyboard-inset'), '340px');
  assert.equal(win.root.dataset.keyboard, 'open');
});

test('measures again after the keyboard animation settles', () => {
  const win = createWindow({ innerHeight: 800, height: 800, offsetTop: 0 });

  initViewportMetrics(win);
  assert.equal(win.timers.length, 0, 'kein Nachmessen ohne Zustandswechsel');

  // Zwischenstand während der Animation: Safari schickt zum Endstand nicht
  // zuverlässig ein weiteres Ereignis.
  win.visualViewport.height = 600;
  win.notifyViewport();
  assert.equal(win.properties.get('--keyboard-inset'), '200px');
  assert.equal(win.timers.length, 1);

  win.visualViewport.height = 460;
  win.timers.pop()();

  assert.equal(win.properties.get('--keyboard-inset'), '340px');
});

test('measures how far Safari moved the visible area', () => {
  const win = createWindow({ innerHeight: 800, height: 460, offsetTop: 140 });

  initViewportMetrics(win);

  assert.equal(win.properties.get('--viewport-shift'), '140px');
  assert.equal(viewportShift(), 140);
});

test('counts the shift once, however Safari reports it', () => {
  // Safari meldet dieselbe Verschiebung doppelt: als offsetTop und dadurch,
  // dass es das Dokument mitscrollt. Wurde beides addiert, stand die Leiste um
  // die doppelte Strecke tiefer - mitten im Text.
  const win = createWindow({
    innerHeight: 800,
    height: 460,
    offsetTop: 140,
    pageTop: 140,
    scrollY: 140,
    scrollerTop: -140,
  });

  initViewportMetrics(win);

  assert.equal(win.properties.get('--viewport-shift'), '140px');
});

test('stays put instead of piling up on itself', () => {
  // Der Ausgleich verschiebt nur die Leiste, nie den Scroll-Container: Dessen
  // Oberkante bleibt gleich, die Messung wiederholt sich also unverändert.
  const win = createWindow({ innerHeight: 800, height: 460, offsetTop: 140 });

  initViewportMetrics(win);
  for (let round = 0; round < 5; round += 1) {
    win.notifyViewport();
  }

  assert.equal(win.properties.get('--viewport-shift'), '140px');
});

test('drops the shift once the keyboard is gone', () => {
  const win = createWindow({ innerHeight: 800, height: 460, offsetTop: 140 });

  initViewportMetrics(win);
  assert.equal(win.properties.get('--viewport-shift'), '140px');

  win.visualViewport.height = 800;
  win.visualViewport.offsetTop = 0;
  win.notifyViewport();

  assert.equal(win.properties.get('--viewport-shift'), '0px');
  assert.equal(viewportShift(), 0);
});

test('stays silent without the visual viewport API', () => {
  const win = createWindow({ innerHeight: 800, height: 800, offsetTop: 0 });
  win.visualViewport = undefined;

  assert.doesNotThrow(() => initViewportMetrics(win));
  assert.equal(win.properties.size, 0);
});
