const DB_NAME = 'shareinfo-offline';
// Das Schema gehört dieser Datei: public/sw.js öffnet dieselbe Datenbank ohne
// Versionsangabe und löst deshalb nie ein eigenes Upgrade aus.
const DB_VERSION = 5;

/** @type {Promise<IDBDatabase>|null} */
let dbPromise = null;

/**
 * @param {string} name
 * @param {IDBTransactionMode} mode
 * @returns {Promise<IDBObjectStore>}
 */
async function store(name, mode = 'readonly') {
  const db = await openDb();
  return db.transaction(name, mode).objectStore(name);
}

export function openDb() {
  if (dbPromise) {
    return dbPromise;
  }

  dbPromise = new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION);
    request.onerror = () => reject(request.error ?? new Error('IndexedDB konnte nicht geöffnet werden.'));
    // Ohne onblocked würde ein Versionsupgrade, das ein anderer Tab offenhält,
    // weder success noch error feuern - das Promise bliebe für immer offen.
    request.onblocked = () => reject(new Error('IndexedDB ist durch einen anderen Tab blockiert.'));
    request.onsuccess = () => {
      const db = request.result;
      // Upgrades aus anderen Tabs nicht blockieren.
      db.onversionchange = () => {
        db.close();
        dbPromise = null;
      };
      resolve(db);
    };
    request.onupgradeneeded = (event) => {
      const db = request.result;
      if (!db.objectStoreNames.contains('meta')) {
        db.createObjectStore('meta', { keyPath: 'key' });
      }
      if (!db.objectStoreNames.contains('pages')) {
        const pages = db.createObjectStore('pages', { keyPath: 'id' });
        pages.createIndex('by_updated', 'updated_at');
      }
      if (!db.objectStoreNames.contains('notebooks')) {
        db.createObjectStore('notebooks', { keyPath: 'id' });
      }
      // Older page rows predate notebooks. Keep their established meaning as
      // unassigned instead of leaving callers to distinguish an absent field.
      if (event.oldVersion < 4) {
        const pages = request.transaction.objectStore('pages');
        const cursor = pages.openCursor();
        cursor.onsuccess = () => {
          const current = cursor.result;
          if (!current) {
            return;
          }
          if (!Object.hasOwn(current.value, 'notebook_id') || current.value.notebook_id === undefined) {
            current.update({ ...current.value, notebook_id: null });
          }
          current.continue();
        };
      }
      if (event.oldVersion < 5) {
        const pages = request.transaction.objectStore('pages');
        const cursor = pages.openCursor();
        cursor.onsuccess = () => {
          const current = cursor.result;
          if (!current) return;
          if (!Object.hasOwn(current.value, 'is_encrypted')) {
            current.update({ ...current.value, is_encrypted: false });
          }
          current.continue();
        };
      }
      if (!db.objectStoreNames.contains('note_contents')) {
        db.createObjectStore('note_contents', { keyPath: 'page_id' });
      }
      if (!db.objectStoreNames.contains('boards')) {
        db.createObjectStore('boards', { keyPath: 'page_id' });
      }
      if (!db.objectStoreNames.contains('documents')) {
        db.createObjectStore('documents', { keyPath: 'url' });
      }
      if (!db.objectStoreNames.contains('outbox')) {
        const outbox = db.createObjectStore('outbox', { keyPath: 'id', autoIncrement: true });
        outbox.createIndex('by_status', 'status');
        outbox.createIndex('by_page', 'page_id');
      }
      if (!db.objectStoreNames.contains('attachment_drafts')) {
        const drafts = db.createObjectStore('attachment_drafts', { keyPath: 'id' });
        drafts.createIndex('by_page', 'page_id');
      }
      // Nur die Metadaten der Dateianhänge; die Dateien selbst liegen im
      // Cache-Storage, damit der Service Worker sie ausliefern kann.
      if (!db.objectStoreNames.contains('page_attachments')) {
        db.createObjectStore('page_attachments', { keyPath: 'page_id' });
      }
    };
  });

  return dbPromise;
}

/**
 * @template T
 * @param {IDBRequest<T>} request
 * @returns {Promise<T>}
 */
function req(request) {
  return new Promise((resolve, reject) => {
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error ?? new Error('IndexedDB-Anfrage fehlgeschlagen.'));
  });
}

/**
 * @param {string} key
 * @param {unknown} value
 */
export async function metaSet(key, value) {
  const s = await store('meta', 'readwrite');
  await req(s.put({ key, value }));
}

/**
 * @param {string} key
 * @param {unknown} fallback
 */
export async function metaGet(key, fallback = null) {
  const s = await store('meta');
  const row = await req(s.get(key));
  return row ? row.value : fallback;
}

/** @param {Record<string, unknown>[]} pages */
export async function putPages(pages) {
  const db = await openDb();
  const tx = db.transaction('pages', 'readwrite');
  const s = tx.objectStore('pages');
  for (const page of pages) {
    s.put(page);
  }
  await txDone(tx);
}

/** @returns {Promise<Record<string, unknown>[]>} */
export async function getAllPages() {
  const s = await store('pages');
  const pages = await req(s.getAll());
  return pages.sort((a, b) => String(b.updated_at || '').localeCompare(String(a.updated_at || '')));
}

/** @param {number} id */
export async function getPage(id) {
  const s = await store('pages');
  return req(s.get(id));
}

/** Remove every local copy belonging to a page after a confirmed delete. */
export async function deletePageData(pageId) {
  const database = await openDb();
  const numericId = Number(pageId);
  const names = [
    'pages',
    'note_contents',
    'boards',
    'documents',
    'page_attachments',
    'outbox',
    'attachment_drafts',
  ];
  const tx = database.transaction(names, 'readwrite');
  const done = txDone(tx);

  tx.objectStore('pages').delete(numericId);
  tx.objectStore('note_contents').delete(numericId);
  tx.objectStore('boards').delete(numericId);
  tx.objectStore('documents').delete(`/app/page/${numericId}`);
  tx.objectStore('page_attachments').delete(numericId);

  const outbox = tx.objectStore('outbox');
  const outboxEntries = await req(outbox.getAll());
  for (const entry of outboxEntries) {
    if (Number(entry.page_id) === numericId) outbox.delete(entry.id);
  }

  const drafts = tx.objectStore('attachment_drafts');
  const draftIds = await req(drafts.index('by_page').getAllKeys(numericId));
  for (const id of draftIds) drafts.delete(id);

  await done;
}

/** @param {Record<string, unknown>[]} notebooks */
export async function putNotebooks(notebooks) {
  if (!Array.isArray(notebooks)) {
    return;
  }
  const database = await openDb();
  const tx = database.transaction('notebooks', 'readwrite');
  const s = tx.objectStore('notebooks');
  s.clear();
  for (const notebook of notebooks) {
    s.put(notebook);
  }
  await txDone(tx);
}

/** @returns {Promise<Record<string, unknown>[]>} */
export async function getAllNotebooks() {
  const s = await store('notebooks');
  return req(s.getAll());
}

/**
 * Nur die Schlüssel, nicht die Werte: Der Prefetch muss vor jedem Durchgang
 * wissen, was bereits lokal liegt. Über `getAll()` kämen dabei sämtliche
 * Notizinhalte in den Arbeitsspeicher - bei Limit „Alle" zehntausende Dokumente.
 *
 * @returns {Promise<{ notes: Set<number>, boards: Set<number>, documents: Set<string> }>}
 */
export async function getCachedKeys() {
  const database = await openDb();
  const tx = database.transaction(['note_contents', 'boards', 'documents']);
  const [notes, boards, documents] = await Promise.all([
    req(tx.objectStore('note_contents').getAllKeys()),
    req(tx.objectStore('boards').getAllKeys()),
    req(tx.objectStore('documents').getAllKeys()),
  ]);

  return {
    notes: new Set(notes.map(Number)),
    boards: new Set(boards.map(Number)),
    documents: new Set(documents.map(String)),
  };
}

/** @param {number[]} keepIds */
export async function prunePages(keepIds) {
  const keep = new Set(keepIds.map(Number));
  const db = await openDb();
  const tx = db.transaction(
    ['pages', 'note_contents', 'boards', 'documents', 'page_attachments'],
    'readwrite',
  );
  const pages = tx.objectStore('pages');
  const notes = tx.objectStore('note_contents');
  const boards = tx.objectStore('boards');
  const docs = tx.objectStore('documents');
  const files = tx.objectStore('page_attachments');
  const all = await req(pages.getAll());
  for (const page of all) {
    if (keep.has(Number(page.id))) {
      continue;
    }
    pages.delete(page.id);
    notes.delete(page.id);
    boards.delete(page.id);
    files.delete(Number(page.id));
    docs.delete(`/app/page/${page.id}`);
  }
  await txDone(tx);
}

/** @param {Record<string, unknown>} content */
export async function putNoteContent(content) {
  const s = await store('note_contents', 'readwrite');
  await req(s.put(content));
}

/** @param {number} pageId */
export async function getNoteContent(pageId) {
  const s = await store('note_contents');
  return req(s.get(pageId));
}

/** @returns {Promise<Record<string, unknown>[]>} */
export async function getAllNoteContents() {
  const s = await store('note_contents');
  return req(s.getAll());
}

/** @param {Record<string, unknown>} board */
export async function putBoard(board) {
  const s = await store('boards', 'readwrite');
  await req(s.put(board));
}

/** @param {number} pageId */
export async function getBoard(pageId) {
  const s = await store('boards');
  return req(s.get(pageId));
}

/** @returns {Promise<Record<string, unknown>[]>} */
export async function getAllBoards() {
  const s = await store('boards');
  return req(s.getAll());
}

/** @param {number} pageId @param {Record<string, unknown>[]} attachments */
export async function putPageAttachments(pageId, attachments) {
  const s = await store('page_attachments', 'readwrite');
  await req(s.put({
    page_id: Number(pageId),
    attachments,
    cached_at: new Date().toISOString(),
  }));
}

/** @param {number} pageId @returns {Promise<Record<string, unknown>[]>} */
export async function getPageAttachments(pageId) {
  const s = await store('page_attachments');
  const row = await req(s.get(Number(pageId)));

  return Array.isArray(row?.attachments) ? row.attachments : [];
}

/** @returns {Promise<Record<string, unknown>[]>} */
export async function getAllPageAttachments() {
  const s = await store('page_attachments');
  const rows = await req(s.getAll());

  return rows.flatMap((row) => (Array.isArray(row.attachments) ? row.attachments : []));
}

/** @param {string} url @param {string} html */
export async function putDocument(url, html) {
  const s = await store('documents', 'readwrite');
  await req(s.put({ url, html, cached_at: new Date().toISOString() }));
}

/** @param {string} url */
export async function getDocument(url) {
  const s = await store('documents');
  const row = await req(s.get(url));
  return row?.html ?? null;
}

/** @param {Record<string, unknown>} entry */
export async function enqueueOutbox(entry) {
  const s = await store('outbox', 'readwrite');
  return req(s.add({
    ...entry,
    status: 'pending',
    created_at: new Date().toISOString(),
    retries: 0,
  }));
}

/**
 * Stores only the newest local content per note while retaining the original
 * server version as the optimistic-lock base.
 *
 * @param {number} pageId
 * @param {Record<string, unknown>} payload
 */
export async function upsertNoteOutbox(pageId, payload) {
  const db = await openDb();
  const tx = db.transaction('outbox', 'readwrite');
  const s = tx.objectStore('outbox');
  const all = await req(s.getAll());
  const matches = all
    .filter((item) => item.type === 'note.putContent' && Number(item.page_id) === Number(pageId))
    .sort((a, b) => Number(a.id) - Number(b.id));
  const operation = payload.operation || 'save';
  const expectedState = payload.expected_encryption_state || 'plain';
  if (matches.some((item) => (item.payload?.operation || 'save') !== operation
    || (item.payload?.expected_encryption_state || 'plain') !== expectedState)) {
    tx.abort();
    throw new Error('Änderungen unterschiedlicher Verschlüsselungszustände dürfen nicht zusammengeführt werden.');
  }

  if (matches.length === 0) {
    const id = await req(s.add({
      type: 'note.putContent',
      page_id: Number(pageId),
      payload,
      status: 'pending',
      created_at: new Date().toISOString(),
      updated_at: new Date().toISOString(),
      retries: 0,
      revision: 1,
    }));
    await txDone(tx);
    return id;
  }

  const first = matches[0];
  const last = matches[matches.length - 1];
  const conflict = matches.find(
    (item) => item.status === 'conflict'
      || item.last_error === 'VERSION_CONFLICT'
      || item.conflict,
  );
  const baseVersion = Number(first.payload?.version ?? payload.version ?? 1);
  const forceSnapshot = matches.some((item) => item.payload?.force_snapshot === true)
    || payload.force_snapshot === true;
  const revision = Math.max(...matches.map((item) => Number(item.revision || 0)), 0) + 1;

  await req(s.put({
    ...first,
    payload: {
      ...payload,
      version: baseVersion,
      force_snapshot: forceSnapshot,
    },
    status: conflict ? 'conflict' : 'pending',
    conflict: conflict?.conflict ?? null,
    last_error: conflict?.last_error ?? null,
    updated_at: new Date().toISOString(),
    retries: Number(last.retries || 0),
    revision,
  }));
  for (const duplicate of matches.slice(1)) {
    s.delete(duplicate.id);
  }
  await txDone(tx);

  return first.id;
}

/**
 * Atomically persists a note draft and its coalesced outbox operation.
 *
 * @param {number} pageId
 * @param {Record<string, unknown>} contentRow
 * @param {Record<string, unknown>} payload
 * @returns {Promise<{id: number, revision: number, status: string}>}
 */
export async function saveNoteAndUpsertOutbox(pageId, contentRow, payload) {
  const database = await openDb();
  const tx = database.transaction(['note_contents', 'outbox'], 'readwrite');
  const done = txDone(tx);
  const notes = tx.objectStore('note_contents');
  const outbox = tx.objectStore('outbox');
  const all = await req(outbox.getAll());
  const matches = all
    .filter((item) => item.type === 'note.putContent' && Number(item.page_id) === Number(pageId))
    .sort((a, b) => Number(a.id) - Number(b.id));
  const operation = payload.operation || 'save';
  const expectedState = payload.expected_encryption_state || 'plain';
  if (matches.some((item) => (item.payload?.operation || 'save') !== operation
    || (item.payload?.expected_encryption_state || 'plain') !== expectedState)) {
    tx.abort();
    try {
      await done;
    } catch {
      /* expected abort */
    }
    throw new Error('Lokale Änderungen gehören zu einem anderen Verschlüsselungszustand.');
  }
  const first = matches[0] || null;
  const last = matches[matches.length - 1] || null;
  const conflict = matches.find(
    (item) => item.status === 'conflict'
      || item.last_error === 'VERSION_CONFLICT'
      || item.conflict,
  );
  const revision = Math.max(...matches.map((item) => Number(item.revision || 0)), 0) + 1;
  const baseVersion = Number(first?.payload?.version ?? payload.version ?? 1);
  const forceSnapshot = matches.some((item) => item.payload?.force_snapshot === true)
    || payload.force_snapshot === true;
  const entry = {
    ...(first || {}),
    type: 'note.putContent',
    page_id: Number(pageId),
    payload: {
      ...payload,
      version: baseVersion,
      force_snapshot: forceSnapshot,
    },
    status: conflict ? 'conflict' : 'pending',
    conflict: conflict?.conflict ?? null,
    last_error: conflict?.last_error ?? null,
    created_at: first?.created_at || new Date().toISOString(),
    updated_at: new Date().toISOString(),
    retries: Number(last?.retries || 0),
    revision,
  };

  let id;
  if (first) {
    id = Number(first.id);
    outbox.put({ ...entry, id });
  } else {
    id = Number(await req(outbox.add(entry)));
  }
  for (const duplicate of matches.slice(1)) {
    outbox.delete(duplicate.id);
  }
  notes.put({ ...contentRow, page_id: Number(pageId), dirty: true, local_revision: revision });
  await done;

  return { id, revision, status: entry.status };
}

/**
 * Deletes only the exact revision sent to the server. A newer local revision
 * remains queued and is rebased to the returned server version.
 *
 * @param {number} id
 * @param {number} expectedRevision
 * @param {Record<string, unknown>} serverRow
 */
export async function completeNoteSync(id, expectedRevision, serverRow) {
  const database = await openDb();
  const tx = database.transaction(['note_contents', 'outbox'], 'readwrite');
  const done = txDone(tx);
  const notes = tx.objectStore('note_contents');
  const outbox = tx.objectStore('outbox');
  const current = await req(outbox.get(Number(id)));

  if (!current) {
    await done;
    return { completed: false, newerRevision: false };
  }

  if (Number(current.revision || 0) === Number(expectedRevision)) {
    outbox.delete(Number(id));
    notes.put({
      page_id: Number(current.page_id),
      content: serverRow.content,
      version: Number(serverRow.version),
      encryption_state: serverRow.encryption_state || current.payload?.expected_encryption_state || 'plain',
      updated_at: serverRow.updated_at || null,
      last_editor_name: serverRow.last_editor_name || null,
      dirty: false,
      cached_at: new Date().toISOString(),
      local_revision: Number(expectedRevision),
    });
    await done;
    return { completed: true, newerRevision: false };
  }

  outbox.put({
    ...current,
    payload: {
      ...current.payload,
      version: Number(serverRow.version),
      force_snapshot: false,
    },
    status: 'pending',
    conflict: null,
    last_error: null,
    retries: 0,
    updated_at: new Date().toISOString(),
  });
  const currentNote = await req(notes.get(Number(current.page_id)));
  if (currentNote) {
    notes.put({
      ...currentNote,
      version: Number(serverRow.version),
      encryption_state: serverRow.encryption_state || current.payload?.expected_encryption_state || 'plain',
      dirty: true,
      cached_at: new Date().toISOString(),
    });
  }
  await done;
  return { completed: false, newerRevision: true };
}

/**
 * Caches a server row only when no local operation exists for the page.
 *
 * @param {number} pageId
 * @param {Record<string, unknown>} row
 */
export async function putNoteContentIfClean(pageId, row) {
  const database = await openDb();
  const tx = database.transaction(['note_contents', 'outbox'], 'readwrite');
  const done = txDone(tx);
  const outbox = tx.objectStore('outbox');
  const all = await req(outbox.getAll());
  const dirty = all.some(
    (item) => item.type === 'note.putContent'
      && Number(item.page_id) === Number(pageId)
      && ['pending', 'conflict', 'blocked', 'error'].includes(item.status),
  );
  if (!dirty) {
    tx.objectStore('note_contents').put({
      ...row,
      page_id: Number(pageId),
      dirty: false,
      cached_at: new Date().toISOString(),
    });
  }
  await done;
  return !dirty;
}

/**
 * Replaces persistent browser copies after a server-confirmed state transition.
 * Removing the queue atomically prevents a stale plaintext save from following
 * a successful encryption request.
 */
export async function replaceNoteEncryptionState(pageId, row, encrypted) {
  const database = await openDb();
  const names = ['pages', 'note_contents', 'documents', 'outbox', 'attachment_drafts', 'page_attachments'];
  const tx = database.transaction(names, 'readwrite');
  const done = txDone(tx);
  const numericId = Number(pageId);
  const outbox = tx.objectStore('outbox');
  const entries = await req(outbox.getAll());
  for (const entry of entries) {
    if (entry.type === 'note.putContent' && Number(entry.page_id) === numericId) {
      outbox.delete(entry.id);
    }
  }

  const drafts = tx.objectStore('attachment_drafts');
  const draftIds = await req(drafts.index('by_page').getAllKeys(numericId));
  for (const id of draftIds) drafts.delete(id);

  tx.objectStore('note_contents').put({
    ...row,
    page_id: numericId,
    version: Number(row.version),
    encryption_state: encrypted ? 'encrypted' : 'plain',
    dirty: false,
    cached_at: new Date().toISOString(),
  });
  const pages = tx.objectStore('pages');
  const page = await req(pages.get(numericId));
  if (page) {
    pages.put({
      ...page,
      is_encrypted: encrypted,
      preview: encrypted ? 'Verschlüsselte Notiz' : page.preview,
      updated_at: row.updated_at || page.updated_at,
    });
  }
  if (encrypted) {
    tx.objectStore('documents').delete(`/app/page/${numericId}`);
    tx.objectStore('page_attachments').delete(numericId);
  }
  await done;
}

/** @param {number} id @param {Record<string, unknown>|null} conflict */
export async function markNoteConflict(id, conflict) {
  const database = await openDb();
  const tx = database.transaction('outbox', 'readwrite');
  const done = txDone(tx);
  const outbox = tx.objectStore('outbox');
  const current = await req(outbox.get(Number(id)));
  if (current) {
    outbox.put({
      ...current,
      status: 'conflict',
      last_error: 'VERSION_CONFLICT',
      conflict,
      retries: Number(current.retries || 0) + 1,
    });
  }
  await done;
  return current;
}

/** @param {number} id @param {number} expectedRevision @param {string} message */
export async function markOutboxBlocked(id, expectedRevision, message) {
  const database = await openDb();
  const tx = database.transaction('outbox', 'readwrite');
  const done = txDone(tx);
  const outbox = tx.objectStore('outbox');
  const current = await req(outbox.get(Number(id)));
  let blocked = false;
  if (current && Number(current.revision || 0) === Number(expectedRevision)) {
    outbox.put({
      ...current,
      status: 'blocked',
      last_error: message,
      retries: Number(current.retries || 0) + 1,
    });
    blocked = true;
  }
  await done;
  return blocked;
}

/**
 * Records a transient sync failure only when it still belongs to the revision
 * that was sent. A newer local edit must keep its own retry state.
 *
 * @param {number} id
 * @param {number} expectedRevision
 * @param {string} message
 * @returns {Promise<number|null>}
 */
export async function markOutboxRetry(id, expectedRevision, message) {
  const database = await openDb();
  const tx = database.transaction('outbox', 'readwrite');
  const done = txDone(tx);
  const outbox = tx.objectStore('outbox');
  const current = await req(outbox.get(Number(id)));
  let retries = null;
  if (current && Number(current.revision || 0) === Number(expectedRevision)) {
    retries = Number(current.retries || 0) + 1;
    outbox.put({
      ...current,
      status: 'pending',
      last_error: message,
      retries,
    });
  }
  await done;
  return retries;
}

/** @param {string} name @param {string} owner @param {number} ttlMs */
export async function acquireLease(name, owner, ttlMs) {
  const database = await openDb();
  const tx = database.transaction('meta', 'readwrite');
  const done = txDone(tx);
  const meta = tx.objectStore('meta');
  const key = `lease:${name}`;
  const current = await req(meta.get(key));
  const now = Date.now();
  if (current?.value?.owner !== owner && Number(current?.value?.expires_at || 0) > now) {
    await done;
    return false;
  }
  meta.put({ key, value: { owner, expires_at: now + ttlMs } });
  await done;
  return true;
}

/** @param {string} name @param {string} owner */
export async function releaseLease(name, owner) {
  const database = await openDb();
  const tx = database.transaction('meta', 'readwrite');
  const done = txDone(tx);
  const meta = tx.objectStore('meta');
  const key = `lease:${name}`;
  const current = await req(meta.get(key));
  if (current?.value?.owner === owner) {
    meta.delete(key);
  }
  await done;
}

/** @returns {Promise<Record<string, unknown>[]>} */
export async function listOutboxPending() {
  const s = await store('outbox');
  const all = await req(s.getAll());
  return all
    .filter((item) => item.status === 'pending')
    .sort((a, b) => Number(a.id) - Number(b.id));
}

/** @returns {Promise<Record<string, unknown>[]>} */
export async function listOutboxConflicts() {
  const s = await store('outbox');
  const all = await req(s.getAll());
  return all
    .filter((item) => item.status === 'conflict')
    .sort((a, b) => Number(a.id) - Number(b.id));
}

/** @returns {Promise<Record<string, unknown>[]>} */
export async function listOutboxUnresolved() {
  const s = await store('outbox');
  const all = await req(s.getAll());
  return all
    .filter((item) => ['pending', 'conflict', 'blocked', 'error'].includes(item.status))
    .sort((a, b) => Number(a.id) - Number(b.id));
}

/** @param {number} id */
export async function getOutbox(id) {
  const s = await store('outbox');
  return req(s.get(id));
}

/** @param {number} pageId */
export async function getNoteOutboxForPage(pageId) {
  const unresolved = await listOutboxUnresolved();
  return unresolved.find(
    (item) => item.type === 'note.putContent' && Number(item.page_id) === Number(pageId),
  ) ?? null;
}

/** @param {number} id */
export async function deleteOutbox(id) {
  const s = await store('outbox', 'readwrite');
  await req(s.delete(id));
}

/** @param {number} id @param {number} expectedRevision */
export async function deleteOutboxIfRevision(id, expectedRevision) {
  const database = await openDb();
  const tx = database.transaction('outbox', 'readwrite');
  const done = txDone(tx);
  const outbox = tx.objectStore('outbox');
  const current = await req(outbox.get(Number(id)));
  const matches = current && Number(current.revision || 0) === Number(expectedRevision);
  if (matches) {
    outbox.delete(Number(id));
  }
  await done;
  return Boolean(matches);
}

/** @param {number} id @param {Partial<Record<string, unknown>>} patch */
export async function patchOutbox(id, patch) {
  const s = await store('outbox', 'readwrite');
  const current = await req(s.get(id));
  if (!current) {
    return;
  }
  await req(s.put({ ...current, ...patch }));
}

export async function countOutboxPending() {
  const pending = await listOutboxUnresolved();
  return pending.length;
}

/** @param {Record<string, unknown>} draft */
export async function putAttachmentDraft(draft) {
  const database = await openDb();
  const tx = database.transaction('attachment_drafts', 'readwrite');
  const done = txDone(tx);
  tx.objectStore('attachment_drafts').put(draft);
  await done;
}

/** @param {string} id */
export async function getAttachmentDraft(id) {
  const s = await store('attachment_drafts');
  return req(s.get(id));
}

/** @param {string} id @param {Partial<Record<string, unknown>>} patch */
export async function patchAttachmentDraft(id, patch) {
  const database = await openDb();
  const tx = database.transaction('attachment_drafts', 'readwrite');
  const done = txDone(tx);
  const s = tx.objectStore('attachment_drafts');
  const current = await req(s.get(id));
  if (!current) {
    await done;
    return;
  }
  s.put({ ...current, ...patch });
  await done;
}

/** @param {number} pageId */
export async function listAttachmentDraftsForPage(pageId) {
  const s = await store('attachment_drafts');
  return req(s.index('by_page').getAll(Number(pageId)));
}

/** @param {string} id */
export async function deleteAttachmentDraft(id) {
  const database = await openDb();
  const tx = database.transaction('attachment_drafts', 'readwrite');
  const done = txDone(tx);
  tx.objectStore('attachment_drafts').delete(id);
  await done;
}

/** @param {number} pageId */
export async function deleteAttachmentDraftsForPage(pageId) {
  const drafts = await listAttachmentDraftsForPage(pageId);
  const db = await openDb();
  const tx = db.transaction('attachment_drafts', 'readwrite');
  const s = tx.objectStore('attachment_drafts');
  for (const draft of drafts) {
    s.delete(draft.id);
  }
  await txDone(tx);
}

export async function clearAllOfflineData() {
  const db = await openDb();
  const names = [
    'meta',
    'pages',
    'notebooks',
    'note_contents',
    'boards',
    'documents',
    'outbox',
    'attachment_drafts',
    'page_attachments',
  ];
  const tx = db.transaction(names, 'readwrite');
  await Promise.all(names.map((name) => req(tx.objectStore(name).clear())));
  await txDone(tx);
}

/** @param {IDBTransaction} tx */
function txDone(tx) {
  return new Promise((resolve, reject) => {
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error ?? new Error('IndexedDB-Transaktion fehlgeschlagen.'));
    tx.onabort = () => reject(tx.error ?? new Error('IndexedDB-Transaktion abgebrochen.'));
  });
}

export async function estimateUsage() {
  if (!navigator.storage?.estimate) {
    return { usage: 0, quota: 0 };
  }
  const estimate = await navigator.storage.estimate();
  return { usage: estimate.usage || 0, quota: estimate.quota || 0 };
}
