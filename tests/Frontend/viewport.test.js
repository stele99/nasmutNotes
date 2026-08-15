import assert from 'node:assert/strict';
import test from 'node:test';

import { initViewportMetrics } from '../../resources/js/viewport.js';

function createWindow({ innerHeight, height, offsetTop, shellTop = 0 }) {
  const listeners = { viewport: [], window: [] };
  const properties = new Map();
  const timers = [];
  // Der Scroll-Container rückt um den gesetzten Ausgleich nach - wie im
  // Browser, wo der Außenabstand der Shell ihn verschiebt.
  const scroller = {
    getBoundingClientRect() {
      const shift = Number.parseInt(properties.get('--viewport-shift') || '0', 10);
      return { top: shellTop + shift };
    },
  };

  return {
    innerHeight,
    visualViewport: {
      height,
      offsetTop,
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

test('makes up for an application top edge above the visible area', () => {
  // Safari hat verschoben: Der sichtbare Ausschnitt beginnt 60px tiefer als die
  // Oberkante der Anwendung.
  const win = createWindow({ innerHeight: 800, height: 460, offsetTop: 60, shellTop: 0 });

  initViewportMetrics(win);

  assert.equal(win.properties.get('--viewport-shift'), '60px');
});

test('takes the compensation back when it is no longer needed', () => {
  const win = createWindow({ innerHeight: 800, height: 460, offsetTop: 60, shellTop: 0 });

  initViewportMetrics(win);
  assert.equal(win.properties.get('--viewport-shift'), '60px');

  // Safari nimmt seinen Versatz zurück, ohne das zu melden: Ohne Nachmessen
  // bliebe der Ausgleich als Leerraum am oberen Rand stehen.
  win.visualViewport.offsetTop = 0;
  win.notifyViewport();

  assert.equal(win.properties.get('--viewport-shift'), '0px');
});

test('leaves the compensation alone while it fits', () => {
  const win = createWindow({ innerHeight: 800, height: 460, offsetTop: 0, shellTop: 0 });

  initViewportMetrics(win);

  assert.equal(win.properties.has('--viewport-shift'), false);
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

test('stays silent without the visual viewport API', () => {
  const win = createWindow({ innerHeight: 800, height: 800, offsetTop: 0 });
  win.visualViewport = undefined;

  assert.doesNotThrow(() => initViewportMetrics(win));
  assert.equal(win.properties.size, 0);
});
