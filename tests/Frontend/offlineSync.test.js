import assert from 'node:assert/strict';
import test from 'node:test';
import 'fake-indexeddb/auto';

/**
 * Regressionstests für den Outbox-Sync einer offline angelegten Notiz mit
 * Bildern (FR-OFFLINE). Der Runtime-Code selbst ist DOM-frei; gestellt werden
 * nur `window`, `navigator`, `fetch` und die Bildvermessung.
 */
const events = new Map();
globalThis.window = {
  addEventListener(type, fn) {
    events.set(type, [...(events.get(type) || []), fn]);
  },
  removeEventListener() {},
  dispatchEvent(event) {
    for (const fn of events.get(event.type) || []) fn(event);
    return true;
  },
  CustomEvent,
  Event,
  localStorage: { length: 0, key: () => null, getItem: () => null, setItem() {}, removeItem() {} },
};
globalThis.localStorage = window.localStorage;
globalThis.document = { querySelector: () => null, readyState: 'complete' };
globalThis.navigator = { onLine: true, storage: {}, locks: { request: (_name, fn) => fn() } };
globalThis.createImageBitmap = async () => ({ width: 4, height: 2, close() {} });
globalThis.window.createImageBitmap = globalThis.createImageBitmap;

// Der BroadcastChannel des Runtime-Moduls hielte den Testprozess offen.
globalThis.BroadcastChannel = undefined;

const runtime = await import('../../resources/js/offline/runtime.js');
const db = await import('../../resources/js/offline/db.js');

const requests = [];
function respondWith(handler) {
  globalThis.fetch = async (url, options = {}) => {
    requests.push(`${(options.method || 'GET').toUpperCase()} ${url}`);
    return handler(url, options);
  };
}
function json(body, status = 200) {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}
function offline() {
  respondWith(async () => {
    throw new TypeError('offline');
  });
}

const IMAGE_SRC = `/api/attachments/${'a'.repeat(64)}`;
let nextServerId = 500;

/** Notiz offline anlegen, ein Bild einfügen und den Inhalt lokal speichern. */
async function createOfflineNoteWithImage(title) {
  offline();
  const page = await runtime.createPageOffline({ type: 'note', title, notebookId: null, location: null });
  const file = new File([new Uint8Array([1, 2, 3, 4])], 'foto.png', { type: 'image/png' });
  const attachment = await runtime.saveOfflineAttachment(file, page.id);
  await runtime.saveNoteOffline(page.id, {
    type: 'doc',
    content: [{ type: 'image', attrs: { src: attachment.src, width: 4, height: 2 } }],
  }, 1);

  return { page, attachment };
}

/** Server, der alles annimmt; `pageFails` lässt das Anlegen dauerhaft scheitern. */
function server({ pageFails = false } = {}) {
  respondWith(async (url, options) => {
    if (url === '/api/pages' && options.method === 'POST') {
      return pageFails
        ? json({ error: { code: 'NOT_FOUND', message: 'Notizbuch nicht gefunden.' } }, 404)
        : json({ id: (nextServerId += 1), type: 'note', title: 'x', updated_at: null }, 201);
    }
    if (/\/attachments$/.test(url)) {
      return Number(url.split('/')[3]) > 0
        ? json({ src: IMAGE_SRC, width: 4, height: 2 }, 201)
        : json({ error: { message: 'Seite nicht gefunden.' } }, 404);
    }
    if (/\/content$/.test(url)) {
      return json({ content: JSON.parse(options.body).content, version: 2, encryption_state: 'plain' });
    }
    return json({}, 200);
  });
}

async function outbox() {
  return (await db.listOutboxUnresolved()).map((item) => ({
    type: item.type,
    page_id: Number(item.page_id),
    status: item.status,
    error: item.last_error || '',
  }));
}

test.beforeEach(async () => {
  requests.length = 0;
  await db.clearAllOfflineData();
});

test('lädt die Bilder einer offline angelegten Notiz nach dem Anlegen der Seite hoch', async () => {
  const { page } = await createOfflineNoteWithImage('Urlaub');
  assert.ok(Number(page.id) < 0);

  server();
  assert.deepEqual(await runtime.syncOutbox(), { synced: 2, conflicts: 0, errors: 0 });

  const serverId = Number(requests[1].split('/')[3]);
  assert.ok(serverId > 0, 'Der Bild-Upload muss an die Server-Kennung gehen.');
  assert.deepEqual(requests, [
    'POST /api/pages',
    `POST /api/pages/${serverId}/attachments`,
    `PUT /api/pages/${serverId}/content`,
  ]);
  assert.deepEqual(await outbox(), []);
});

test('nennt das gescheiterte Anlegen als Ursache, statt Bilder gegen die Temporär-Kennung zu laden', async () => {
  await createOfflineNoteWithImage('Urlaub');

  server({ pageFails: true });
  await runtime.syncOutbox();

  // Ohne die Sperre lief hier ein POST auf /api/pages/-1/attachments, und die
  // Notiz wurde mit einer Meldung über das Bild blockiert.
  assert.deepEqual(requests, ['POST /api/pages']);
  assert.deepEqual(await outbox(), [
    { type: 'page.create', page_id: -1, status: 'blocked', error: 'Notizbuch nicht gefunden.' },
    {
      type: 'note.putContent',
      page_id: -1,
      status: 'blocked',
      error: 'Die Seite konnte nicht angelegt werden: Notizbuch nicht gefunden.',
    },
  ]);
});

test('blockiert eine Notiz mit verlorenem Bildentwurf, ohne die übrige Warteschlange aufzuhalten', async () => {
  await createOfflineNoteWithImage('Mit Bild');
  offline();
  const plain = await runtime.createPageOffline({ type: 'note', title: 'Ohne Bild', notebookId: null, location: null });
  await runtime.saveNoteOffline(plain.id, {
    type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'B' }] }],
  }, 1);

  // Der Entwurf ist weg (verdrängte IndexedDB, gelöschte Offline-Daten).
  for (const draft of await db.listAttachmentDraftsForPage(-1)) {
    await db.deleteAttachmentDraft(draft.id);
  }

  server();
  const result = await runtime.syncOutbox();

  // Die zweite Notiz darf nicht hinter dem defekten Eintrag hängen bleiben.
  assert.equal(result.errors, 1);
  assert.ok(requests.some((entry) => /^PUT \/api\/pages\/\d+\/content$/.test(entry)));
  const queue = await outbox();
  assert.equal(queue.length, 1);
  assert.equal(queue[0].status, 'blocked');
  assert.match(queue[0].error, /Bild ist nicht mehr verfügbar/);
});

test('zieht eine übersehene Kennungsumstellung nach, statt den Eintrag zu blockieren', async () => {
  await createOfflineNoteWithImage('Urlaub');
  // Zustand nach einem Remapping, das genau diesen Inhaltseintrag nicht
  // erwischt hat: Der Create-Eintrag ist erledigt und hält die Zuordnung
  // Temporär-Kennung -> Server-Kennung, der Inhaltseintrag steht noch auf der
  // Temporär-Kennung.
  const [create, note] = await db.listOutboxPending();
  await db.patchOutbox(Number(create.id), {
    status: 'done',
    page_id: 777,
    payload: { ...create.payload, local_page_id: -1, server_page_id: 777 },
  });
  assert.equal(Number(note.page_id), -1);

  server();
  await runtime.syncOutbox();

  assert.deepEqual(requests, ['POST /api/pages/777/attachments', 'PUT /api/pages/777/content']);
  assert.deepEqual(await outbox(), []);
});
