import assert from 'node:assert/strict';
import test from 'node:test';

import { initViewportMetrics } from '../../resources/js/viewport.js';

function createWindow({ innerHeight, height, offsetTop, scrollY = 0 }) {
  const listeners = { viewport: [], window: [] };
  const properties = new Map();

  return {
    innerHeight,
    scrollY,
    visualViewport: {
      height,
      offsetTop,
      addEventListener(name, handler) {
        listeners.viewport.push([name, handler]);
      },
    },
    document: {
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
    properties,
    listeners,
    get root() {
      return this.document.documentElement;
    },
  };
}

test('reports the keyboard height and the shifted viewport', () => {
  const win = createWindow({ innerHeight: 800, height: 460, offsetTop: 60 });

  initViewportMetrics(win);

  assert.equal(win.properties.get('--keyboard-inset'), '280px');
  assert.equal(win.properties.get('--viewport-shift'), '60px');
  assert.equal(win.root.dataset.keyboard, 'open');
});

test('ignores the address bar sliding in and out', () => {
  const win = createWindow({ innerHeight: 800, height: 740, offsetTop: 0 });

  initViewportMetrics(win);

  assert.equal(win.properties.get('--keyboard-inset'), '0px');
  assert.equal(win.properties.get('--viewport-shift'), '0px');
  assert.equal(win.root.dataset.keyboard, 'closed');
});

test('counts a scrolled window as viewport shift', () => {
  const win = createWindow({ innerHeight: 800, height: 460, offsetTop: 0, scrollY: 60 });

  initViewportMetrics(win);

  assert.equal(win.properties.get('--keyboard-inset'), '340px');
  assert.equal(win.properties.get('--viewport-shift'), '60px');
});

test('never reports a negative inset', () => {
  const win = createWindow({ innerHeight: 800, height: 820, offsetTop: 0 });

  initViewportMetrics(win);

  assert.equal(win.properties.get('--keyboard-inset'), '0px');
  assert.equal(win.properties.get('--viewport-shift'), '0px');
});

test('recalculates when the visual viewport changes', () => {
  const win = createWindow({ innerHeight: 800, height: 800, offsetTop: 0 });

  initViewportMetrics(win);
  assert.equal(win.properties.get('--keyboard-inset'), '0px');

  win.visualViewport.height = 460;
  win.visualViewport.offsetTop = 60;
  for (const [, handler] of win.listeners.viewport) {
    handler();
  }

  assert.equal(win.properties.get('--keyboard-inset'), '280px');
  assert.equal(win.properties.get('--viewport-shift'), '60px');
});

test('stays silent without the visual viewport API', () => {
  const win = createWindow({ innerHeight: 800, height: 800, offsetTop: 0 });
  win.visualViewport = undefined;

  assert.doesNotThrow(() => initViewportMetrics(win));
  assert.equal(win.properties.size, 0);
});
