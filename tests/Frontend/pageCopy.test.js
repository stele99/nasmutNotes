import assert from 'node:assert/strict';
import test from 'node:test';

globalThis.document = { querySelector: () => null };

const { pageCopyMixin } = await import('../../resources/js/pageCopy.js');

/**
 * Der Mixin selbst ist DOM-frei; nur `window` (Ereignisse, alert) und
 * `navigator.onLine` müssen gestellt werden. Zurückgegeben werden die
 * abgesetzten Ereignisse und Meldungen, damit die Tests sie prüfen können.
 */
function makeComponent({ pageId = 7, shared = false, online = true } = {}) {
  const events = [];
  const alerts = [];
  globalThis.window = {
    dispatchEvent(event) {
      events.push(event);
      return true;
    },
    alert(message) {
      alerts.push(message);
    },
    CustomEvent,
    Event,
  };
  globalThis.navigator = { onLine: online };

  const component = { pageId, ...pageCopyMixin() };
  component.initPageCopy({ dataset: { pageIsShared: shared ? '1' : '0' } });

  return { component, events, alerts };
}

function jsonResponse(body, status = 201) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

test('kopiert eine eigene Notiz ohne Rückfrage und ohne notebook_id', async () => {
  const { component, events } = makeComponent();
  let sent = null;
  globalThis.fetch = async (url, options) => {
    sent = { url, body: options.body, method: options.method };
    return jsonResponse({ id: 12, title: 'Kopie von Bericht' });
  };

  await component.copyPage();

  assert.equal(sent.url, '/api/pages/7/duplicate');
  assert.equal(sent.method, 'POST');
  assert.equal(sent.body, '{}');
  assert.equal(component.copyDialogOpen, false);
  assert.equal(component.copyingPage, false);
  assert.deepEqual(
    events.map((event) => event.type),
    ['pages-changed', 'toast:show', 'navigate-page'],
  );
  assert.equal(events[2].detail.id, 12);
});

test('fragt bei einer geteilten Notiz erst nach dem eigenen Notizbuch', async () => {
  const { component } = makeComponent({ shared: true });
  const calls = [];
  globalThis.fetch = async (url, options) => {
    calls.push(url);
    if (url === '/api/notebooks') {
      return jsonResponse({
        notebooks: [
          { id: 3, name: 'Eigenes', is_owner: true },
          { id: 4, name: 'Fremdes', is_owner: false },
        ],
      }, 200);
    }
    return jsonResponse({ id: 20, title: 'Kopie von Geteilt', notebook_id: Number(JSON.parse(options.body).notebook_id) });
  };

  await component.copyPage();

  assert.equal(component.copyDialogOpen, true);
  assert.deepEqual(calls, ['/api/notebooks']);
  // Fremde, nur geteilte Notizbücher stehen nicht zur Wahl.
  assert.deepEqual(component.copyDialogNotebooks.map((notebook) => notebook.id), [3]);

  component.copyDialogNotebookId = '3';
  await component.copyPageToSelectedNotebook();

  assert.equal(calls[1], '/api/pages/7/duplicate');
  assert.equal(component.copyDialogOpen, false);
});

test('schickt für „Ohne Notizbuch" ausdrücklich null', async () => {
  const { component } = makeComponent({ shared: true });
  let body = null;
  globalThis.fetch = async (url, options) => {
    if (url === '/api/notebooks') {
      return jsonResponse({ notebooks: [] }, 200);
    }
    body = options.body;
    return jsonResponse({ id: 21, title: 'Kopie von Geteilt' });
  };

  await component.copyPage();
  await component.copyPageToSelectedNotebook();

  assert.equal(body, '{"notebook_id":null}');
});

test('kopiert weder offline noch aus einer ungesyncten Notiz heraus', async () => {
  globalThis.fetch = async () => {
    throw new Error('Es darf keine Anfrage entstehen.');
  };

  const offline = makeComponent({ online: false });
  await offline.component.copyPage();
  assert.equal(offline.alerts.length, 1);
  assert.match(offline.alerts[0], /offline/);

  const unsynced = makeComponent({ pageId: -3 });
  await unsynced.component.copyPage();
  assert.equal(unsynced.alerts.length, 1);
  assert.match(unsynced.alerts[0], /übertragen/);
});

test('meldet einen Fehler im Dialog statt ihn zu verschlucken', async () => {
  const { component, alerts } = makeComponent({ shared: true });
  globalThis.fetch = async (url) => {
    if (url === '/api/notebooks') {
      return jsonResponse({ notebooks: [] }, 200);
    }
    return jsonResponse({ error: { message: 'Das Speicherkontingent ist erschöpft.' } }, 422);
  };

  await component.copyPage();
  await component.copyPageToSelectedNotebook();

  assert.equal(component.copyDialogOpen, true);
  assert.equal(component.copyError, 'Das Speicherkontingent ist erschöpft.');
  assert.deepEqual(alerts, []);
});
