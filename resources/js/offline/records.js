import { apiFetch } from '../api.js';
import * as db from './db.js';

/**
 * Offline-Erfassung für Aufgaben und Logbuch-Einträge.
 *
 * Auf der Baustelle ohne Netz sollen Stunden, Material und erledigte Aufgaben
 * trotzdem sofort erfasst werden. Jede Änderung landet als Eintrag in der
 * gemeinsamen Outbox (siehe runtime.js) und wird beim nächsten Sync
 * übertragen. Bis dahin legt `applyTaskOps`/`applyLogOps` die ausstehenden
 * Änderungen über den zuletzt bekannten Stand, sodass die Oberfläche sie
 * sofort zeigt - online wie offline.
 *
 * Grundsätze:
 * - Je Aufgabe bzw. Eintrag gibt es höchstens einen offenen Änderungseintrag;
 *   weitere Änderungen werden hineingemischt. Die Basisfassung (`base`) bleibt
 *   die des ersten Offline-Bearbeitens - sie ist der Vergleichswert der
 *   Drei-Wege-Zusammenführung beim Sync.
 * - Ein offline angelegtes Objekt, das vor dem Sync bearbeitet oder gelöscht
 *   wird, ändert bzw. entfernt nur seinen Anlage-Eintrag.
 * - Neue Objekte tragen eine `client_uuid`: Kommt dieselbe Anlage nach einem
 *   Verbindungsabbruch doppelt an, legt der Server sie nur einmal an.
 */

export const TASK_TYPES = ['task.create', 'task.update', 'task.delete'];
export const LOG_TYPES = ['log.createEntry', 'log.updateEntry', 'log.deleteEntry'];
const RECORD_TYPES = new Set([...TASK_TYPES, ...LOG_TYPES]);

/** Einträge, die noch übertragen werden sollen (auch blockierte zählen als ausstehend). */
const OPEN_STATUSES = new Set(['pending', 'blocked', 'error']);

export function isRecordType(type) {
  return RECORD_TYPES.has(String(type || ''));
}

function nowIso() {
  return new Date().toISOString();
}

function uuid() {
  return crypto.randomUUID();
}

function same(left, right) {
  return JSON.stringify(left ?? null) === JSON.stringify(right ?? null);
}

function notifyChanged(pageId) {
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent('offline-records-changed', { detail: { pageId: Number(pageId) } }));
  }
}

/**
 * Drei-Wege-Zusammenführung je Feld. `base` ist der Stand, den der Nutzer
 * bearbeitet hat, `mine` seine Änderungen, `theirs` der aktuelle Serverstand.
 *
 * - Server hat das Feld nicht angefasst → meine Änderung gilt.
 * - Server steht bereits auf meinem Wert → nichts zu tun.
 * - Beide haben dasselbe Feld verschieden geändert → Konflikt.
 *
 * @param {Record<string, unknown>} base
 * @param {Record<string, unknown>} mine
 * @param {Record<string, unknown>} theirs
 * @returns {{ merged: Record<string, unknown>, conflicts: string[] }}
 */
export function mergeFields(base, mine, theirs) {
  const merged = {};
  const conflicts = [];
  for (const [key, value] of Object.entries(mine || {})) {
    const theirValue = theirs?.[key];
    // Nicht selbst geändert (der Dialog schickt alle Felder mit): Hier gilt,
    // was auf dem Server steht.
    if (base && Object.hasOwn(base, key) && same(base[key], value)) {
      continue;
    }
    if (same(theirValue, value)) {
      continue;
    }
    if (same(theirValue, base?.[key])) {
      merged[key] = value;
      continue;
    }
    conflicts.push(key);
  }

  return { merged, conflicts };
}

function isOpenFor(item, type, key, id) {
  return item.type === type && OPEN_STATUSES.has(String(item.status)) && Number(item.payload?.[key]) === Number(id);
}

function newEntry(type, pageId, payload) {
  return {
    type,
    page_id: Number(pageId),
    payload,
    status: 'pending',
    created_at: nowIso(),
    updated_at: nowIso(),
    retries: 0,
    revision: 1,
  };
}

function touch(item, payload) {
  return {
    ...item,
    payload,
    status: 'pending',
    last_error: null,
    updated_at: nowIso(),
    revision: Number(item.revision || 0) + 1,
  };
}

// ------------------------------------------------------------------ Aufgaben

const TASK_FIELDS = ['title', 'description', 'responsible', 'link', 'is_done', 'due_date'];

function taskSnapshot(task) {
  const snapshot = {};
  for (const field of TASK_FIELDS) {
    snapshot[field] = task?.[field] ?? (field === 'is_done' ? false : null);
  }

  return snapshot;
}

/**
 * Offline angelegte Aufgabe. Liefert die lokale Darstellung mit negativer
 * Kennung, die bis zum Sync im Board steht.
 *
 * @param {number} pageId
 * @param {{ id: number, name?: string }} category
 * @param {string} title
 */
export async function queueTaskCreate(pageId, category, title) {
  const localId = await db.nextLocalRecordId();
  const payload = {
    local_id: localId,
    category_id: Number(category.id),
    client_uuid: uuid(),
    fields: { title, is_done: false },
    label: `Aufgabe „${title}“`,
  };
  await db.withOutbox((items, outbox) => outbox.add(newEntry('task.create', pageId, payload)));
  notifyChanged(pageId);

  return localTask(payload);
}

function localTask(payload) {
  return {
    id: Number(payload.local_id),
    category_id: Number(payload.category_id),
    title: '',
    description: null,
    responsible: null,
    link: null,
    is_done: false,
    due_date: null,
    priority: null,
    version: 0,
    position: Number.MAX_SAFE_INTEGER,
    ...payload.fields,
    pending: true,
  };
}

/**
 * Änderung an einer Aufgabe merken. `task` ist der Stand, den der Nutzer vor
 * sich hatte (inklusive `version`), `fields` die geänderten Felder.
 *
 * @param {number} pageId
 * @param {Record<string, unknown>} task
 * @param {Record<string, unknown>} fields
 */
export async function queueTaskUpdate(pageId, task, fields) {
  const taskId = Number(task.id);
  await db.withOutbox((items, outbox) => {
    if (taskId < 0) {
      const create = items.find((item) => isOpenFor(item, 'task.create', 'local_id', taskId));
      if (create) {
        const label = `Aufgabe „${fields.title ?? create.payload.fields.title}“`;
        outbox.put(touch(create, { ...create.payload, label, fields: { ...create.payload.fields, ...fields } }));
      }
      return null;
    }
    const existing = items.find((item) => isOpenFor(item, 'task.update', 'task_id', taskId));
    if (existing) {
      const merged = { ...existing.payload.fields, ...fields };
      outbox.put(touch(existing, { ...existing.payload, fields: merged, label: `Aufgabe „${merged.title ?? task.title}“` }));
      return null;
    }
    return outbox.add(newEntry('task.update', pageId, {
      task_id: taskId,
      version: Number(task.version || 0),
      base: taskSnapshot(task),
      fields: { ...fields },
      label: `Aufgabe „${fields.title ?? task.title}“`,
    }));
  });
  notifyChanged(pageId);
}

/** @param {number} pageId @param {Record<string, unknown>} task */
export async function queueTaskDelete(pageId, task) {
  const taskId = Number(task.id);
  await db.withOutbox((items, outbox) => {
    if (taskId < 0) {
      const create = items.find((item) => isOpenFor(item, 'task.create', 'local_id', taskId));
      if (create) {
        outbox.delete(create.id);
      }
      return null;
    }
    for (const item of items) {
      if (isOpenFor(item, 'task.update', 'task_id', taskId)) {
        outbox.delete(item.id);
      }
    }
    return outbox.add(newEntry('task.delete', pageId, { task_id: taskId, label: `Aufgabe „${task.title}“ löschen` }));
  });
  notifyChanged(pageId);
}

/**
 * Legt die ausstehenden Aufgabenänderungen einer Seite über den bekannten
 * Stand. Die Eingabe bleibt unverändert.
 *
 * @param {number} pageId
 * @param {Record<string, unknown>[]} categories
 * @param {Record<string, unknown>[]} outboxItems
 */
export function applyTaskOps(pageId, categories, outboxItems) {
  const result = (categories || []).map((category) => ({
    ...category,
    tasks: (category.tasks || []).map((task) => ({ ...task })),
  }));
  const pending = outboxItems.filter(
    (item) => TASK_TYPES.includes(item.type) && Number(item.page_id) === Number(pageId) && OPEN_STATUSES.has(String(item.status)),
  );
  const findTask = (taskId) => {
    for (const category of result) {
      const task = category.tasks.find((candidate) => Number(candidate.id) === Number(taskId));
      if (task) {
        return { category, task };
      }
    }
    return null;
  };

  for (const item of pending) {
    if (item.type === 'task.create') {
      const category = result.find((candidate) => Number(candidate.id) === Number(item.payload.category_id));
      if (category && !findTask(item.payload.local_id)) {
        category.tasks.push(localTask(item.payload));
      }
    } else if (item.type === 'task.update') {
      const found = findTask(item.payload.task_id);
      if (found) {
        Object.assign(found.task, item.payload.fields, { pending: true });
      }
    } else if (item.type === 'task.delete') {
      const found = findTask(item.payload.task_id);
      if (found) {
        found.category.tasks = found.category.tasks.filter((task) => task !== found.task);
      }
    }
  }

  return result;
}

/**
 * Nimmt ein offline gemerktes Löschen zurück, solange es noch nicht
 * übertragen ist. Liefert false, wenn es dafür zu spät ist.
 *
 * @param {'task.delete'|'log.deleteEntry'} type
 * @param {number} pageId
 * @param {number} id
 */
export async function cancelQueuedDelete(type, pageId, id) {
  const key = type === 'task.delete' ? 'task_id' : 'entry_id';
  const removed = await db.withOutbox((items, outbox) => {
    const entry = items.find((item) => item.status === 'pending' && isOpenFor(item, type, key, id));
    if (!entry) {
      return false;
    }
    outbox.delete(entry.id);
    return true;
  });
  notifyChanged(pageId);

  return removed === true;
}

// ----------------------------------------------------------- Logbuch-Einträge

/**
 * Anzeigeform eines offline eingegebenen Werts - dieselbe Struktur, die der
 * Server liefert, damit Zellen und Summen sofort stimmen. Die endgültige
 * Prüfung und Aufbereitung (Anschrift, Sterne) übernimmt der Server beim Sync.
 */
export function displayValue(column, raw) {
  if (raw === '' || raw === null || raw === undefined) {
    return null;
  }
  if (column.type === 'location') {
    const value = typeof raw === 'object' ? raw : { label: String(raw) };
    return {
      text: value.label ?? null,
      number: null,
      lat: value.lat ?? null,
      lon: value.lon ?? null,
    };
  }
  if (column.is_numeric || column.type === 'rating') {
    const number = Number(String(raw).replace(',', '.'));
    return Number.isFinite(number) ? { text: null, number, lat: null, lon: null } : null;
  }

  return { text: String(raw), number: null, lat: null, lon: null };
}

function displayValues(columns, rawValues) {
  const values = {};
  for (const column of columns || []) {
    const key = String(column.id);
    if (Object.hasOwn(rawValues || {}, key)) {
      const value = displayValue(column, rawValues[key]);
      if (value !== null) {
        values[key] = value;
      }
    }
  }

  return values;
}

/**
 * @param {number} pageId
 * @param {{ occurred_at: string, values: Record<string, unknown> }} input
 */
export async function queueLogEntryCreate(pageId, input) {
  const localId = await db.nextLocalRecordId();
  const payload = {
    local_id: localId,
    client_uuid: uuid(),
    occurred_at: input.occurred_at,
    values: { ...input.values },
    label: 'Logbuch-Eintrag',
  };
  await db.withOutbox((items, outbox) => outbox.add(newEntry('log.createEntry', pageId, payload)));
  notifyChanged(pageId);
}

/**
 * @param {number} pageId
 * @param {Record<string, unknown>} entry Stand vor der Bearbeitung (mit `version`)
 * @param {{ occurred_at: string, values: Record<string, unknown> }} input
 * @param {Record<string, unknown>} baseRaw Eingabewerte, wie sie vor der Bearbeitung im Dialog standen
 */
export async function queueLogEntryUpdate(pageId, entry, input, baseRaw) {
  const entryId = Number(entry.id);
  await db.withOutbox((items, outbox) => {
    if (entryId < 0) {
      const create = items.find((item) => isOpenFor(item, 'log.createEntry', 'local_id', entryId));
      if (create) {
        outbox.put(touch(create, {
          ...create.payload,
          occurred_at: input.occurred_at,
          values: { ...create.payload.values, ...input.values },
        }));
      }
      return null;
    }
    const existing = items.find((item) => isOpenFor(item, 'log.updateEntry', 'entry_id', entryId));
    if (existing) {
      outbox.put(touch(existing, {
        ...existing.payload,
        occurred_at: input.occurred_at,
        values: { ...existing.payload.values, ...input.values },
      }));
      return null;
    }
    return outbox.add(newEntry('log.updateEntry', pageId, {
      entry_id: entryId,
      version: Number(entry.version || 1),
      base: { occurred_at: entry.occurred_at, values: { ...baseRaw } },
      occurred_at: input.occurred_at,
      values: { ...input.values },
      label: 'Logbuch-Eintrag ändern',
    }));
  });
  notifyChanged(pageId);
}

/** @param {number} pageId @param {Record<string, unknown>} entry */
export async function queueLogEntryDelete(pageId, entry) {
  const entryId = Number(entry.id);
  await db.withOutbox((items, outbox) => {
    if (entryId < 0) {
      const create = items.find((item) => isOpenFor(item, 'log.createEntry', 'local_id', entryId));
      if (create) {
        outbox.delete(create.id);
      }
      return null;
    }
    for (const item of items) {
      if (isOpenFor(item, 'log.updateEntry', 'entry_id', entryId)) {
        outbox.delete(item.id);
      }
    }
    return outbox.add(newEntry('log.deleteEntry', pageId, { entry_id: entryId, label: 'Logbuch-Eintrag löschen' }));
  });
  notifyChanged(pageId);
}

/**
 * Ausstehende Logbuch-Änderungen über den bekannten Stand legen.
 *
 * @param {number} pageId
 * @param {{ columns: Record<string, unknown>[], entries: Record<string, unknown>[] }} board
 * @param {Record<string, unknown>[]} outboxItems
 */
export function applyLogOps(pageId, board, outboxItems) {
  const columns = board?.columns || [];
  let entries = (board?.entries || []).map((entry) => ({ ...entry, values: { ...(entry.values || {}) } }));
  const pending = outboxItems.filter(
    (item) => LOG_TYPES.includes(item.type) && Number(item.page_id) === Number(pageId) && OPEN_STATUSES.has(String(item.status)),
  );

  for (const item of pending) {
    if (item.type === 'log.createEntry') {
      if (!entries.some((entry) => Number(entry.id) === Number(item.payload.local_id))) {
        entries.unshift({
          id: Number(item.payload.local_id),
          version: 0,
          occurred_at: item.payload.occurred_at,
          created_at: item.created_at,
          updated_at: item.updated_at,
          created_by_name: null,
          values: displayValues(columns, item.payload.values),
          pending: true,
        });
      }
    } else if (item.type === 'log.updateEntry') {
      const entry = entries.find((candidate) => Number(candidate.id) === Number(item.payload.entry_id));
      if (entry) {
        const changed = displayValues(columns, item.payload.values);
        for (const key of Object.keys(item.payload.values || {})) {
          if (changed[key]) {
            entry.values[key] = changed[key];
          } else {
            delete entry.values[key];
          }
        }
        entry.occurred_at = item.payload.occurred_at;
        entry.pending = true;
      }
    } else if (item.type === 'log.deleteEntry') {
      entries = entries.filter((entry) => Number(entry.id) !== Number(item.payload.entry_id));
    }
  }

  // Ausstehende Einträge einsortieren: neueste oben, wie der Server ohne
  // gewählte Sortierung liefert. Ohne ausstehende Änderungen bleibt die vom
  // Server gewählte Reihenfolge unangetastet.
  if (pending.length > 0) {
    entries.sort((left, right) => String(right.occurred_at).localeCompare(String(left.occurred_at)));
  }

  return { ...board, entries };
}

// ---------------------------------------------------------------------- Sync

function conflictError(message) {
  const error = new Error(message);
  error.status = 409;

  return error;
}

/** Eintrag mit der Kennung aus dem aktuellen Board der Seite holen. */
async function currentServerTask(pageId, taskId) {
  const board = await apiFetch(`/api/pages/${pageId}/board`);
  for (const category of board.categories || []) {
    const task = (category.tasks || []).find((candidate) => Number(candidate.id) === Number(taskId));
    if (task) {
      return task;
    }
  }

  return null;
}

async function syncTaskUpdate(item) {
  const { task_id: taskId, fields, base } = item.payload;
  const send = (version, body) => apiFetch(`/api/tasks/${taskId}`, {
    method: 'PATCH',
    body: JSON.stringify({ ...body, version }),
  });
  try {
    await send(item.payload.version, fields);
  } catch (error) {
    if (error.status === 404) {
      // Inzwischen gelöscht - die Änderung hat kein Ziel mehr.
      throw conflictError(`${item.payload.label}: Die Aufgabe wurde inzwischen gelöscht.`);
    }
    if (error.status !== 409 || !error.payload?.current) {
      throw error;
    }
    const current = error.payload.current;
    // „Wiederholen“ nach einem gemeldeten Konflikt: bewusst überschreiben.
    if (item.payload.force === true) {
      await send(current.version, fields);
      return;
    }
    const { merged, conflicts } = mergeFields(base, fields, current);
    if (conflicts.length > 0) {
      throw conflictError(
        `${item.payload.label}: Jemand hat zwischenzeitlich dasselbe geändert (${conflicts.join(', ')}). `
        + 'Verwerfen übernimmt den Serverstand, Wiederholen überschreibt ihn.',
      );
    }
    if (Object.keys(merged).length > 0) {
      await send(current.version, merged);
    }
  }
}

async function syncTaskItem(item) {
  if (item.type === 'task.create') {
    const { category_id: categoryId, client_uuid: clientUuid, fields } = item.payload;
    // Alle offline gesetzten Felder gehen mit der Anlage hinaus.
    // allow_duplicate: Die Rückfrage nach doppelten Titeln ist offline nicht
    // möglich; die Aufgabe wurde bewusst angelegt.
    await apiFetch(`/api/categories/${categoryId}/tasks`, {
      method: 'POST',
      body: JSON.stringify({ ...fields, client_uuid: clientUuid, allow_duplicate: true }),
    });
    return;
  }
  if (item.type === 'task.update') {
    await syncTaskUpdate(item);
    return;
  }
  try {
    await apiFetch(`/api/tasks/${item.payload.task_id}`, { method: 'DELETE' });
  } catch (error) {
    if (error.status !== 404) {
      throw error;
    }
  }
}

function valuesOf(entry) {
  return entry?.values || {};
}

async function syncLogItem(item) {
  if (item.type === 'log.createEntry') {
    await apiFetch(`/api/pages/${item.page_id}/log/entries`, {
      method: 'POST',
      body: JSON.stringify({
        occurred_at: item.payload.occurred_at,
        values: item.payload.values,
        client_uuid: item.payload.client_uuid,
      }),
    });
    return;
  }
  if (item.type === 'log.deleteEntry') {
    try {
      await apiFetch(`/api/log-entries/${item.payload.entry_id}`, { method: 'DELETE' });
    } catch (error) {
      if (error.status !== 404) {
        throw error;
      }
    }
    return;
  }

  const { entry_id: entryId, base } = item.payload;
  const send = (version, body) => apiFetch(`/api/log-entries/${entryId}`, {
    method: 'PATCH',
    body: JSON.stringify({ ...body, version }),
  });
  try {
    await send(item.payload.version, { occurred_at: item.payload.occurred_at, values: item.payload.values });
  } catch (error) {
    if (error.status === 404) {
      throw conflictError('Logbuch-Eintrag: Der Eintrag wurde inzwischen gelöscht.');
    }
    if (error.status !== 409) {
      throw error;
    }
    const current = error.payload?.current;
    if (!current) {
      throw conflictError('Logbuch-Eintrag: Der Eintrag wurde inzwischen gelöscht.');
    }
    if (item.payload.force === true) {
      await send(Number(current.version), { occurred_at: item.payload.occurred_at, values: item.payload.values });
      return;
    }
    // Vergleich über die Eingabeform: Der Server liefert Anzeigewerte, die
    // Basis stammt aus dem Dialog. Ein Feld gilt als unverändert, wenn der
    // Server es seit dem Laden nicht angefasst hat - die Werte werden dazu in
    // dieselbe Anzeigeform gebracht.
    const board = await apiFetch(`/api/pages/${item.page_id}/log`);
    const columns = board.columns || [];
    const toDisplay = (raw) => displayValues(columns, raw);
    const mine = toDisplay(item.payload.values);
    const baseDisplay = toDisplay(base.values);
    const theirs = valuesOf(current);
    const keys = Object.keys(item.payload.values || {});
    const conflicts = [];
    const mergedRaw = {};
    for (const key of keys) {
      const my = comparable(mine[key]);
      const their = comparable(theirs[key]);
      const was = comparable(baseDisplay[key]);
      if (same(my, was) || same(their, my)) {
        continue;
      }
      if (same(their, was)) {
        mergedRaw[key] = item.payload.values[key];
        continue;
      }
      const column = columns.find((candidate) => String(candidate.id) === key);
      conflicts.push(column ? column.name : key);
    }
    const timeChanged = !same(item.payload.occurred_at, base.occurred_at);
    const theirTimeChanged = !same(current.occurred_at, base.occurred_at);
    if (timeChanged && theirTimeChanged && !same(current.occurred_at, item.payload.occurred_at)) {
      conflicts.push('Zeitpunkt');
    }
    if (conflicts.length > 0) {
      throw conflictError(
        `Logbuch-Eintrag: Jemand hat zwischenzeitlich dasselbe geändert (${conflicts.join(', ')}). `
        + 'Verwerfen übernimmt den Serverstand, Wiederholen überschreibt ihn.',
      );
    }
    const body = { values: mergedRaw };
    if (timeChanged) {
      body.occurred_at = item.payload.occurred_at;
    }
    if (Object.keys(mergedRaw).length > 0 || timeChanged) {
      await send(Number(current.version), body);
    }
  }
}

/** Nur die inhaltlich tragenden Teile eines Werts vergleichen. */
function comparable(value) {
  if (!value) {
    return null;
  }
  if (value.number !== null && value.number !== undefined) {
    return { number: Number(value.number) };
  }
  if (value.lat !== null && value.lat !== undefined) {
    return { lat: Number(value.lat).toFixed(5), lon: Number(value.lon).toFixed(5) };
  }

  return { text: value.text ?? null };
}

/**
 * Überträgt einen Aufgaben- oder Logbuch-Eintrag der Outbox. Wirft bei
 * Fehlern; ein nicht auflösbarer Konflikt kommt als Fehler mit Status 409
 * und wird von der Runtime als blockiert markiert.
 *
 * @param {Record<string, unknown>} item
 */
export async function syncRecordItem(item) {
  if (TASK_TYPES.includes(item.type)) {
    await syncTaskItem(item);
  } else {
    await syncLogItem(item);
  }
  notifyChanged(item.page_id);
}

/** Ausstehende Aufgaben-/Logbuch-Einträge der Outbox (für die Überlagerung). */
export async function pendingRecordItems() {
  try {
    return (await db.listOutboxUnresolved()).filter((item) => isRecordType(item.type));
  } catch {
    return [];
  }
}
