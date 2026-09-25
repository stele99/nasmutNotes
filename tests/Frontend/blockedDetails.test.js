import assert from 'node:assert/strict';
import test from 'node:test';
import 'fake-indexeddb/auto';

globalThis.window = { addEventListener() {}, removeEventListener() {}, dispatchEvent() { return true; } };
globalThis.navigator = { onLine: true };

const { describeBlocked } = await import('../../resources/js/offline/blockedDetails.js');

const doc = (...paragraphs) => ({
  type: 'doc',
  content: paragraphs.map((text) => ({ type: 'paragraph', content: [{ type: 'text', text }] })),
});

function noteItem(overrides = {}) {
  return {
    id: 3,
    type: 'note.putContent',
    page_id: 365,
    status: 'blocked',
    last_error: 'Diese Freigabe ist nur lesend.',
    payload: { content: doc('Aufmaß Bad', 'Wand links 3,20 m'), version: 4 },
    ...overrides,
  };
}

test('eine gelöschte Seite wird benannt und bietet das Sichern als neue Notiz statt Wiederholen', () => {
  const details = describeBlocked(noteItem(), { page: null, pageStatus: 'missing', server: null });

  assert.equal(details.pageState, 'missing');
  assert.match(details.explanation, /gibt es auf dem Server nicht mehr/);
  assert.deepEqual(details.actions, { open: false, restorePage: false, saveAsCopy: true, retry: false });
  assert.match(details.localText, /Wand links 3,20 m/);
});

test('eine Seite im Papierkorb lässt sich wiederherstellen, der Vergleich zeigt beide Fassungen', () => {
  const details = describeBlocked(noteItem(), {
    page: { id: 365, title: 'Baustelle Müller', deleted_at: '2026-09-25T08:00:00Z', can_edit: false, is_shared: false },
    pageStatus: 'ok',
    server: { content: doc('Aufmaß Bad', 'Wand links 3,10 m'), updated_at: '2026-09-25T07:00:00Z', last_editor_name: 'Chef' },
  });

  assert.equal(details.pageTitle, 'Baustelle Müller');
  assert.equal(details.pageState, 'trashed');
  assert.equal(details.actions.restorePage, true);
  assert.equal(details.compare.mode, 'diff');
  const changed = details.compare.rows.filter((row) => row.type !== 'same').map((row) => `${row.type}:${row.text}`);
  assert.deepEqual(changed.sort(), ['added:Wand links 3,20 m', 'removed:Wand links 3,10 m']);
  assert.match(details.compare.serverMeta, /Chef/);
});

test('Nur-Lese-Freigabe: kein Wiederholen, aber Sichern als Kopie', () => {
  const details = describeBlocked(noteItem(), {
    page: { id: 365, title: 'Pläne', deleted_at: null, can_edit: false, is_shared: true },
    pageStatus: 'ok',
    server: null,
  });

  assert.equal(details.pageState, 'readonly');
  assert.equal(details.actions.retry, false);
  assert.equal(details.actions.saveAsCopy, true);
  assert.equal(details.actions.open, true);
});

test('Aufgabenkonflikt: Felder lokal und Server nebeneinander, Wiederholen heißt „Meine Fassung übernehmen“', () => {
  const details = describeBlocked({
    id: 4,
    type: 'task.update',
    page_id: 12,
    last_error: 'Aufgabe „Fliesen“: Jemand hat zwischenzeitlich dasselbe geändert (title).',
    payload: { task_id: 9, fields: { title: 'Fliesen abholen', due_date: '2026-10-01' } },
  }, {
    page: { id: 12, title: 'Baustelle', deleted_at: null, can_edit: true },
    pageStatus: 'ok',
    server: { id: 9, title: 'Fliesen reklamieren', due_date: '2026-10-01' },
  });

  assert.equal(details.retryLabel, 'Meine Fassung übernehmen');
  assert.deepEqual(details.compare.rows, [
    { label: 'Titel', local: 'Fliesen abholen', server: 'Fliesen reklamieren', changed: true },
    { label: 'Fällig', local: '01.10.2026', server: '01.10.2026', changed: false },
  ]);
});

test('Logbuch: Spaltenwerte werden lesbar verglichen', () => {
  const details = describeBlocked({
    id: 5,
    type: 'log.updateEntry',
    page_id: 20,
    last_error: 'Logbuch-Eintrag: Jemand hat zwischenzeitlich dasselbe geändert (Stunden).',
    payload: { entry_id: 7, occurred_at: '2026-09-25T06:00:00.000Z', values: { 1: '7,5' } },
  }, {
    page: { id: 20, title: 'Stundenzettel', deleted_at: null, can_edit: true },
    pageStatus: 'ok',
    server: {
      columns: [{ id: 1, name: 'Stunden', type: 'hours', is_numeric: true }],
      entry: { id: 7, occurred_at: '2026-09-25T06:00:00.000Z', values: { 1: { number: 6, text: null } } },
    },
  });

  const hours = details.compare.rows.find((row) => row.label === 'Stunden');
  assert.deepEqual(hours, { label: 'Stunden', local: '7,5 h', server: '6 h', changed: true });
});
