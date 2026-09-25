import assert from 'node:assert/strict';
import test from 'node:test';
import 'fake-indexeddb/auto';

/**
 * Offline-Erfassung von Aufgaben und Logbuch-Einträgen (records.js): lokale
 * Überlagerung, Zusammenfassen mehrerer Änderungen und die
 * Drei-Wege-Zusammenführung beim Sync.
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
globalThis.BroadcastChannel = undefined;

const runtime = await import('../../resources/js/offline/runtime.js');
const db = await import('../../resources/js/offline/db.js');
const records = await import('../../resources/js/offline/records.js');

const PAGE_ID = 7;
const requests = [];

function json(body, status = 200) {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function respondWith(handler) {
  globalThis.fetch = async (url, options = {}) => {
    const method = (options.method || 'GET').toUpperCase();
    requests.push({ method, url, body: options.body ? JSON.parse(options.body) : null });
    return handler(url, options, method);
  };
}

function board(tasks) {
  return [{ id: 1, name: 'Offen', tasks }];
}

function serverTask(overrides = {}) {
  return {
    id: 10,
    category_id: 1,
    title: 'Fliesen bestellen',
    description: null,
    responsible: null,
    link: null,
    is_done: false,
    due_date: null,
    version: 3,
    ...overrides,
  };
}

async function items() {
  return db.listOutboxUnresolved();
}

test.beforeEach(async () => {
  requests.length = 0;
  await db.clearAllOfflineData();
});

test('mergeFields übernimmt eigene Änderungen, solange der Server dasselbe Feld nicht geändert hat', () => {
  const base = { title: 'A', is_done: false };
  assert.deepEqual(
    records.mergeFields(base, { is_done: true }, { title: 'B', is_done: false }),
    { merged: { is_done: true }, conflicts: [] },
  );
  assert.deepEqual(
    records.mergeFields(base, { title: 'C' }, { title: 'B', is_done: false }),
    { merged: {}, conflicts: ['title'] },
  );
  assert.deepEqual(
    records.mergeFields(base, { title: 'B' }, { title: 'B', is_done: false }),
    { merged: {}, conflicts: [] },
  );
  // Unverändert mitgeschickte Felder überschreiben keine fremde Änderung.
  assert.deepEqual(
    records.mergeFields(base, { title: 'A', is_done: true }, { title: 'B', is_done: false }),
    { merged: { is_done: true }, conflicts: [] },
  );
});

test('mehrere Offline-Änderungen an einer Aufgabe bilden einen Eintrag mit der ursprünglichen Basis', async () => {
  const task = serverTask();
  await records.queueTaskUpdate(PAGE_ID, task, { is_done: true });
  await records.queueTaskUpdate(PAGE_ID, { ...task, is_done: true }, { title: 'Fliesen abholen' });

  const queued = await items();
  assert.equal(queued.length, 1);
  assert.deepEqual(queued[0].payload.fields, { is_done: true, title: 'Fliesen abholen' });
  assert.equal(queued[0].payload.version, 3);
  assert.equal(queued[0].payload.base.title, 'Fliesen bestellen');

  const [category] = records.applyTaskOps(PAGE_ID, board([task]), queued);
  assert.equal(category.tasks[0].title, 'Fliesen abholen');
  assert.equal(category.tasks[0].is_done, true);
  assert.equal(category.tasks[0].pending, true);
});

test('eine offline angelegte Aufgabe wird bis zum Sync nur in ihrem Anlage-Eintrag geändert oder verworfen', async () => {
  const local = await records.queueTaskCreate(PAGE_ID, { id: 1 }, 'Silikon');
  assert.ok(local.id < 0);

  await records.queueTaskUpdate(PAGE_ID, local, { is_done: true });
  let queued = await items();
  assert.equal(queued.length, 1);
  assert.equal(queued[0].type, 'task.create');
  assert.deepEqual(queued[0].payload.fields, { title: 'Silikon', is_done: true });

  const [category] = records.applyTaskOps(PAGE_ID, board([]), queued);
  assert.equal(category.tasks.length, 1);
  assert.equal(category.tasks[0].is_done, true);

  await records.queueTaskDelete(PAGE_ID, local);
  queued = await items();
  assert.deepEqual(queued, []);
});

test('beim Sync werden Änderungen verschiedener Felder zusammengeführt', async () => {
  const task = serverTask();
  await records.queueTaskUpdate(PAGE_ID, task, { is_done: true });

  let attempt = 0;
  respondWith(async (url, options, method) => {
    if (method === 'PATCH' && url === '/api/tasks/10') {
      attempt += 1;
      if (attempt === 1) {
        // Ein Kollege hat inzwischen den Titel geändert.
        return json({
          error: { code: 'VERSION_CONFLICT', message: 'Konflikt' },
          current: serverTask({ title: 'Fliesen bestellen (Bad)', version: 4 }),
        }, 409);
      }
      return json(serverTask({ title: 'Fliesen bestellen (Bad)', is_done: true, version: 5 }));
    }
    return json({}, 200);
  });

  const result = await runtime.syncOutbox();

  assert.equal(result.synced, 1);
  assert.deepEqual(requests.map((request) => request.body), [
    { is_done: true, version: 3 },
    { is_done: true, version: 4 },
  ]);
  assert.deepEqual(await items(), []);
});

test('ein echter Konflikt blockiert den Eintrag; Wiederholen setzt die eigene Fassung durch', async () => {
  const task = serverTask();
  await records.queueTaskUpdate(PAGE_ID, task, { title: 'Fliesen abholen' });

  respondWith(async (url, options, method) => {
    if (method === 'PATCH') {
      const body = JSON.parse(options.body);
      if (body.version === 3) {
        return json({
          error: { code: 'VERSION_CONFLICT', message: 'Konflikt' },
          current: serverTask({ title: 'Fliesen reklamieren', version: 4 }),
        }, 409);
      }
      return json(serverTask({ title: body.title, version: 5 }));
    }
    return json({}, 200);
  });

  await runtime.syncOutbox();
  const [blocked] = await items();
  assert.equal(blocked.status, 'blocked');
  assert.match(blocked.last_error, /dasselbe geändert \(title\)/);

  await runtime.retryBlockedEntry(Number(blocked.id));

  assert.deepEqual(requests.at(-1).body, { title: 'Fliesen abholen', version: 4 });
  assert.deepEqual(await items(), []);
});

test('ein offline erfasster Logbuch-Eintrag erscheint sofort und geht mit client_uuid hinaus', async () => {
  const columns = [
    { id: 1, name: 'Stunden', type: 'hours', is_numeric: true },
    { id: 2, name: 'Material', type: 'text', is_numeric: false },
  ];
  const serverBoard = {
    columns,
    entries: [{ id: 5, version: 1, occurred_at: '2026-09-20T07:00:00.000Z', values: {} }],
    entry_count: 1,
  };

  await records.queueLogEntryCreate(PAGE_ID, {
    occurred_at: '2026-09-25T06:30:00.000Z',
    values: { 1: '7,5', 2: 'Kleber' },
  });

  const shown = records.applyLogOps(PAGE_ID, serverBoard, await items());
  assert.equal(shown.entries.length, 2);
  assert.ok(shown.entries[0].id < 0, 'Der neue Eintrag steht oben.');
  assert.equal(shown.entries[0].values['1'].number, 7.5);
  assert.equal(shown.entries[0].values['2'].text, 'Kleber');

  respondWith(async () => json({ id: 6 }, 201));
  await runtime.syncOutbox();

  assert.equal(requests.length, 1);
  assert.equal(requests[0].url, `/api/pages/${PAGE_ID}/log/entries`);
  assert.match(requests[0].body.client_uuid, /^[0-9a-f-]{36}$/);
  assert.deepEqual(await items(), []);
});

test('ein offline gemerktes Löschen lässt sich vor dem Sync zurücknehmen', async () => {
  const entry = { id: 5, version: 2, occurred_at: '2026-09-20T07:00:00.000Z', values: {} };
  await records.queueLogEntryDelete(PAGE_ID, entry);

  const hidden = records.applyLogOps(PAGE_ID, { columns: [], entries: [entry] }, await items());
  assert.equal(hidden.entries.length, 0);

  assert.equal(await records.cancelQueuedDelete('log.deleteEntry', PAGE_ID, 5), true);
  assert.deepEqual(await items(), []);
  assert.equal(await records.cancelQueuedDelete('log.deleteEntry', PAGE_ID, 5), false);
});

test('eine inzwischen gelöschte Aufgabe blockiert das Abhaken mit verständlicher Meldung', async () => {
  await records.queueTaskUpdate(PAGE_ID, serverTask(), { is_done: true });
  respondWith(async () => json({ error: { code: 'NOT_FOUND', message: 'Task #10 nicht gefunden.' } }, 404));

  await runtime.syncOutbox();

  const [blocked] = await items();
  assert.equal(blocked.status, 'blocked');
  assert.match(blocked.last_error, /inzwischen gelöscht/);
});

test('Logbuch: Änderungen an verschiedenen Spalten werden beim Sync zusammengeführt', async () => {
  const columns = [
    { id: 1, name: 'Stunden', type: 'hours', is_numeric: true },
    { id: 2, name: 'Material', type: 'text', is_numeric: false },
  ];
  const entry = {
    id: 5,
    version: 2,
    occurred_at: '2026-09-20T07:00:00.000Z',
    values: { 1: { text: null, number: 4, lat: null, lon: null } },
  };
  // Offline: Stunden von 4 auf 6 korrigiert, Material unverändert leer.
  await records.queueLogEntryUpdate(
    PAGE_ID,
    entry,
    { occurred_at: entry.occurred_at, values: { 1: '6', 2: '' } },
    { 1: '4', 2: '' },
  );

  respondWith(async (url, options, method) => {
    if (method === 'GET') {
      return json({ columns, entries: [], entry_count: 0 });
    }
    const body = JSON.parse(options.body);
    if (body.version === 2) {
      // Inzwischen hat jemand das Material nachgetragen.
      return json({
        error: { code: 'VERSION_CONFLICT', message: 'Konflikt' },
        current: {
          ...entry,
          version: 3,
          values: { ...entry.values, 2: { text: 'Fugenmasse', number: null, lat: null, lon: null } },
        },
      }, 409);
    }
    return json({ ...entry, version: 4 });
  });

  await runtime.syncOutbox();

  // Nur die Stunden gehen hinaus - das Material des Kollegen bleibt stehen.
  assert.deepEqual(requests.at(-1).body, { values: { 1: '6' }, version: 3 });
  assert.deepEqual(await items(), []);
});
