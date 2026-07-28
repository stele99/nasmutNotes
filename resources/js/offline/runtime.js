import { apiFetch } from '../api.js';
import * as db from './db.js';

export const CACHE_LIMITS = [100, 250, 500, 1000, 5000, 10000, 'all'];
const ATTACHMENT_CACHE = 'shareinfo-attachments-v1';
const CACHE_PREFIX = 'shareinfo-';
const SETTINGS_KEY = 'cache_limit';
const ATTACHMENT_LIMIT_KEY = 'attachment_max_bytes';
const DEFAULT_ATTACHMENT_MAX_BYTES = 250 * 1024;
const DEFAULT_LIMIT = 100;
const PREFETCH_CONCURRENCY = 4;
const PREFETCH_MIN_INTERVAL_MS = 5 * 60 * 1000;
const SYNC_LEASE_MS = 5 * 60 * 1000;
const RETRY_BASE_MS = 1000;
const RETRY_MAX_MS = 60 * 1000;
const RUNTIME_ID = crypto.randomUUID();
const syncChannel = typeof BroadcastChannel === 'function'
  ? new BroadcastChannel('shareinfo-offline-sync')
  : null;

/** @type {Set<(status: Record<string, unknown>) => void>} */
const listeners = new Set();

let initialized = false;
let syncing = false;
let prefetching = false;
let online = typeof navigator !== 'undefined' ? navigator.onLine : true;
let lastError = '';
let pendingCount = 0;
let conflictCount = 0;
let blockedCount = 0;
let prefetchProgress = { done: 0, total: 0 };
/** @type {AbortController|null} */
let prefetchAbort = null;
/** @type {Promise<void>|null} */
let reconnectRun = null;
let retryTimer = null;

function emit() {
  const status = getStatusSnapshot();
  for (const listener of listeners) {
    listener(status);
  }
  window.dispatchEvent(new CustomEvent('offline-status', { detail: status }));
}

async function refreshQueueState() {
  const unresolved = await db.listOutboxUnresolved();
  pendingCount = unresolved.filter((item) => item.status === 'pending').length;
  conflictCount = unresolved.filter((item) => item.status === 'conflict').length;
  blockedCount = unresolved.filter((item) => item.status === 'blocked').length;
  emit();
}

export function getStatusSnapshot() {
  return {
    online,
    syncing,
    prefetching,
    lastError,
    pendingCount,
    conflictCount,
    blockedCount,
    prefetchProgress: { ...prefetchProgress },
  };
}

export function onStatusChange(listener) {
  listeners.add(listener);
  listener(getStatusSnapshot());
  return () => listeners.delete(listener);
}

/**
 * Prevents two tabs from independently editing and overwriting the same local
 * draft. Returns null when another tab already owns the note lock.
 *
 * @param {number} pageId
 * @returns {Promise<(() => void)|null>}
 */
export async function acquireNoteEditLock(pageId) {
  if (!navigator.locks?.request) {
    const leaseName = `note-edit:${Number(pageId)}`;
    const owner = `${RUNTIME_ID}:${Number(pageId)}`;
    const acquired = await db.acquireLease(leaseName, owner, 15_000);
    if (!acquired) {
      return null;
    }
    const heartbeat = setInterval(() => {
      void db.acquireLease(leaseName, owner, 15_000);
    }, 5_000);
    return () => {
      clearInterval(heartbeat);
      void db.releaseLease(leaseName, owner);
    };
  }

  let releaseLock;
  const released = new Promise((resolve) => {
    releaseLock = resolve;
  });
  let reportAcquired;
  const acquired = new Promise((resolve) => {
    reportAcquired = resolve;
  });

  void navigator.locks.request(
    `shareinfo-note-edit:${Number(pageId)}`,
    { ifAvailable: true },
    async (lock) => {
      reportAcquired(Boolean(lock));
      if (lock) {
        await released;
      }
    },
  ).catch(() => reportAcquired(false));

  return await acquired ? () => releaseLock() : null;
}

export async function initOfflineRuntime() {
  if (initialized || typeof indexedDB === 'undefined') {
    return;
  }
  initialized = true;

  try {
    await db.openDb();
    const limit = normalizeCacheLimit(await db.metaGet(SETTINGS_KEY, DEFAULT_LIMIT));
    await db.metaSet(SETTINGS_KEY, limit);
    await normalizeLegacyNoteQueue();
    await refreshQueueState();
    if (syncChannel) {
      syncChannel.onmessage = (event) => {
        if (event.data?.type === 'note-sync') {
          dispatchLocalNoteSync(event.data.detail);
        }
        if (event.data?.type === 'queue-changed') {
          void refreshQueueState();
        }
      };
    }
  } catch (error) {
    // Ist IndexedDB nicht verfügbar (Privatmodus, blockiertes Upgrade), läuft die
    // App ohne Offline-Funktionen weiter statt gar nicht.
    lastError = error.message || 'Offline-Speicher nicht verfügbar.';
  }

  window.addEventListener('online', () => {
    online = true;
    emit();
    void reconnect();
  });
  window.addEventListener('offline', () => {
    online = false;
    clearSyncRetry();
    emit();
  });

  registerServiceWorker();
  emit();

  if (online) {
    void refreshOfflineConfig();
    void syncOutbox();
  }
}

/**
 * Faltet Altbestände aus der Zeit mehrerer Queue-Einträge pro Notiz zusammen.
 * Läuft bewusst nur bei echten Duplikaten: ein Upsert auf einem Einzeleintrag
 * würde dessen Status auf "pending" zurücksetzen und blockierte Einträge damit
 * wieder in die endlose Retry-Schleife schicken.
 */
async function normalizeLegacyNoteQueue() {
  const unresolved = await db.listOutboxUnresolved();
  const notes = unresolved.filter((item) => item.type === 'note.putContent');
  const byPage = new Map();
  for (const item of notes) {
    const pageId = Number(item.page_id);
    byPage.set(pageId, [...(byPage.get(pageId) ?? []), item]);
  }

  for (const [pageId, entries] of byPage) {
    if (entries.length > 1) {
      await db.upsertNoteOutbox(pageId, entries[entries.length - 1].payload);
    }
  }
}

/**
 * Nach dem Reconnect muss der Sync *vor* dem Prefetch laufen, sonst überschreibt
 * der Prefetch lokale, noch nicht übertragene Inhalte mit dem Serverstand.
 */
function reconnect() {
  if (!reconnectRun) {
    reconnectRun = (async () => {
      await syncOutbox();
      await prefetchSelected();
    })().finally(() => {
      reconnectRun = null;
    });
  }

  return reconnectRun;
}

function registerServiceWorker() {
  if (!('serviceWorker' in navigator)) {
    return;
  }
  const register = () => {
    navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {
      /* SW optional */
    });
  };
  // Der Offline-Init ist asynchron und kann nach dem load-Event fertig werden -
  // dann käme ein load-Listener zu spät und der Worker würde nie registriert.
  if (document.readyState === 'complete') {
    register();
  } else {
    window.addEventListener('load', register, { once: true });
  }
}

export async function getCacheLimit() {
  return normalizeCacheLimit(await db.metaGet(SETTINGS_KEY, DEFAULT_LIMIT));
}

/**
 * Grenze, bis zu der Anhänge und eingebettete Bilder automatisch offline
 * vorgehalten werden (FR-OFFLINE-06). Der Admin setzt sie serverseitig; der
 * zuletzt bekannte Wert liegt lokal, damit auch ein Start ohne Netz eine
 * verlässliche Grenze hat. 0 schaltet das Vorladen ab.
 *
 * @returns {Promise<number>}
 */
export async function getAttachmentMaxBytes() {
  const stored = Number(await db.metaGet(ATTACHMENT_LIMIT_KEY, DEFAULT_ATTACHMENT_MAX_BYTES));

  return Number.isFinite(stored) && stored >= 0 ? stored : DEFAULT_ATTACHMENT_MAX_BYTES;
}

/** @param {unknown} bytes */
export async function rememberAttachmentMaxBytes(bytes) {
  const value = Number(bytes);
  if (!Number.isFinite(value) || value < 0) {
    return;
  }
  await db.metaSet(ATTACHMENT_LIMIT_KEY, Math.floor(value));
}

/** Holt die serverseitigen Offline-Einstellungen; ohne Netz bleibt der letzte Stand. */
async function refreshOfflineConfig() {
  try {
    const session = await apiFetch('/api/session');
    await rememberAttachmentMaxBytes(session?.offline?.attachment_max_bytes);
  } catch {
    /* der zuletzt bekannte Wert gilt weiter */
  }
}

export function normalizeCacheLimit(limit) {
  if (limit === 'all') {
    return 'all';
  }
  const numeric = Number(limit);
  if (CACHE_LIMITS.includes(numeric)) {
    return numeric;
  }
  return DEFAULT_LIMIT;
}

/**
 * Der Nachlauf bleibt bewusst unerwartet: Ein erhöhtes Limit zieht einen
 * Download nach sich, der je nach Bestand Minuten dauert. Würde er hier
 * abgewartet, bliebe der Einstellungsdialog so lange blockiert - den Fortschritt
 * zeigt stattdessen die Statuszeile.
 */
export async function setCacheLimit(limit) {
  const normalized = normalizeCacheLimit(limit);
  await db.metaSet(SETTINGS_KEY, normalized);
  emit();
  if (online) {
    void prefetchSelected({ force: true });
  }
}

export async function getOfflineStats() {
  const [pages, notes, boards, unresolved, usage, limit, attachments] = await Promise.all([
    db.getAllPages(),
    db.getAllNoteContents(),
    db.getAllBoards(),
    db.listOutboxUnresolved(),
    db.estimateUsage(),
    getCacheLimit(),
    cachedAttachmentCounts(),
  ]);
  return {
    pageCount: pages.length,
    noteCount: notes.length,
    taskPageCount: boards.length,
    imageCount: attachments.images,
    fileCount: attachments.files,
    pendingSync: unresolved.filter((item) => item.status === 'pending').length,
    unresolved: unresolved.length,
    conflicts: unresolved.filter((item) => item.status === 'conflict').length,
    blocked: unresolved.filter((item) => item.status === 'blocked').length,
    limit,
    attachmentMaxBytes: await getAttachmentMaxBytes(),
    usageBytes: usage.usage,
    quotaBytes: usage.quota,
  };
}

async function cachedAttachmentCounts() {
  if (typeof caches === 'undefined') {
    return { images: 0, files: 0 };
  }
  const cache = await caches.open(ATTACHMENT_CACHE);
  let images = 0;
  let files = 0;
  for (const request of await cache.keys()) {
    const path = new URL(request.url).pathname;
    if (path.startsWith('/api/attachments/')) {
      images += 1;
    } else if (path.startsWith('/api/page-attachments/')) {
      files += 1;
    }
  }

  return { images, files };
}

/** @param {Record<string, unknown>[]} pages */
export async function cachePageList(pages) {
  await db.putPages(pages.map((page) => ({
    ...page,
    id: Number(page.id),
    cached_at: new Date().toISOString(),
  })));
}

export async function getCachedPages() {
  return db.getAllPages();
}

export async function getCachedPage(pageId) {
  return db.getPage(Number(pageId));
}

/** @param {Record<string, unknown>[]} notebooks */
export async function cacheNotebooks(notebooks) {
  if (!Array.isArray(notebooks)) {
    return;
  }
  await db.putNotebooks(notebooks.map((notebook) => ({
    ...notebook,
    id: Number(notebook.id),
    cached_at: new Date().toISOString(),
  })));
}

export async function getCachedNotebooks() {
  return db.getAllNotebooks();
}

/** @param {number} pageId @param {Record<string, unknown>} payload */
export async function cacheNoteContent(pageId, payload) {
  const row = {
    page_id: Number(pageId),
    content: payload.content,
    version: Number(payload.version || 1),
    updated_at: payload.updated_at || null,
    last_editor_name: payload.last_editor_name || null,
    dirty: Boolean(payload.dirty),
    cached_at: new Date().toISOString(),
  };
  let stored;
  if (payload.dirty) {
    await db.putNoteContent(row);
    stored = true;
  } else {
    stored = await db.putNoteContentIfClean(Number(pageId), row);
  }
  if (stored) {
    await cacheAttachmentsFromContent(payload.content);
  }
  return stored;
}

export async function readCachedNoteContent(pageId) {
  return db.getNoteContent(Number(pageId));
}

/** @param {number} pageId @param {Record<string, unknown>} payload */
export async function cacheBoard(pageId, payload) {
  await db.putBoard({
    page_id: Number(pageId),
    categories: payload.categories || [],
    cached_at: new Date().toISOString(),
  });
}

export async function readCachedBoard(pageId) {
  return db.getBoard(Number(pageId));
}

export async function cacheDocument(url, html) {
  await db.putDocument(url, html);
}

export async function readCachedDocument(url) {
  return db.getDocument(url);
}

/**
 * @param {number} pageId
 * @param {Record<string, unknown>} content
 * @param {number} version
 * @param {{ forceSnapshot?: boolean }} [options]
 */
export async function saveNoteOffline(pageId, content, version, options = {}) {
  const queued = await db.saveNoteAndUpsertOutbox(Number(pageId), {
    page_id: Number(pageId),
    content,
    version,
    dirty: true,
    updated_at: new Date().toISOString(),
    last_editor_name: null,
    cached_at: new Date().toISOString(),
  }, {
    content,
    version,
    force_snapshot: Boolean(options.forceSnapshot),
  });
  await cacheAttachmentsFromContent(content);
  await refreshQueueState();
  syncChannel?.postMessage({ type: 'queue-changed' });
  return queued;
}

export async function hasQueuedNoteChange(pageId) {
  return (await db.getNoteOutboxForPage(Number(pageId))) !== null;
}

/** Anzahl noch nicht übertragener Änderungen – für Warnungen vor dem Abmelden. */
export async function countUnsyncedChanges() {
  try {
    return (await db.listOutboxUnresolved()).length;
  } catch {
    return 0;
  }
}

export async function syncOutbox() {
  if (!online) {
    return { synced: 0, conflicts: 0, errors: 0 };
  }
  clearSyncRetry();
  return withOutboxLock(() => runSyncOutbox());
}

async function withOutboxLock(operation) {
  if (navigator.locks?.request) {
    return navigator.locks.request(
      'shareinfo-outbox-sync',
      () => operation(),
    );
  }

  let acquired = await db.acquireLease('outbox-sync', RUNTIME_ID, SYNC_LEASE_MS);
  while (!acquired) {
    await new Promise((resolve) => setTimeout(resolve, 250));
    acquired = await db.acquireLease('outbox-sync', RUNTIME_ID, SYNC_LEASE_MS);
  }
  const heartbeat = setInterval(() => {
    void db.acquireLease('outbox-sync', RUNTIME_ID, SYNC_LEASE_MS);
  }, 60_000);
  try {
    return await operation();
  } finally {
    clearInterval(heartbeat);
    await db.releaseLease('outbox-sync', RUNTIME_ID);
  }
}

async function runSyncOutbox() {
  if (!online || syncing) {
    return { synced: 0, conflicts: 0, errors: 0 };
  }
  syncing = true;
  lastError = '';
  emit();

  let synced = 0;
  let conflicts = 0;
  let errors = 0;
  let needsAnotherSync = false;

  try {
    const pending = await db.listOutboxPending();
    for (const item of pending) {
      try {
        if (item.type === 'note.putContent') {
          const sentRevision = Number(item.revision || 0);
          const sourceContent = item.payload.content;
          const content = await uploadOfflineAttachments(Number(item.page_id), item.payload.content);
          const result = await apiFetch(`/api/pages/${item.page_id}/content`, {
            method: 'PUT',
            body: JSON.stringify({
              content,
              version: item.payload.version,
              force_snapshot: item.payload.force_snapshot || false,
            }),
          });
          const completion = await db.completeNoteSync(Number(item.id), sentRevision, result);
          await cacheAttachmentsFromContent(result.content);
          if (completion.newerRevision) {
            needsAnotherSync = true;
          }
          dispatchNoteSync('synced', Number(item.page_id), result, Number(item.id), sourceContent);
          synced += 1;
        } else {
          await db.patchOutbox(Number(item.id), {
            status: 'blocked',
            last_error: `Nicht unterstützter Offline-Vorgang: ${item.type || 'unbekannt'}`,
          });
          errors += 1;
        }
      } catch (error) {
        if (error.status === 409) {
          conflicts += 1;
          const conflict = error.payload?.current || null;
          await db.markNoteConflict(Number(item.id), conflict);
          dispatchNoteSync('conflict', Number(item.page_id), conflict, Number(item.id));
        } else if ([400, 403, 404, 413, 422].includes(error.status)) {
          errors += 1;
          await db.markOutboxBlocked(
            Number(item.id),
            Number(item.revision || 0),
            error.message || 'Sync fehlgeschlagen',
          );
        } else {
          errors += 1;
          const retries = await db.markOutboxRetry(
            Number(item.id),
            Number(item.revision || 0),
            error.message || 'Sync offline/Netzwerkfehler',
          );
          const delay = retryDelay(retries ?? (Number(item.retries || 0) + 1), error.retryAfter);
          lastError = `${error.message || 'Sync offline/Netzwerkfehler'} Erneuter Versuch in ${Math.ceil(delay / 1000)} s.`;
          scheduleSyncRetry(delay);
          break;
        }
      }
    }
  } finally {
    syncing = false;
    await refreshQueueState();
    syncChannel?.postMessage({ type: 'queue-changed' });
  }

  if (needsAnotherSync && online) {
    setTimeout(() => void syncOutbox(), 0);
  }

  return { synced, conflicts, errors };
}

function retryDelay(retries, retryAfter) {
  const retryAfterSeconds = Number(retryAfter);
  if (Number.isFinite(retryAfterSeconds) && retryAfterSeconds >= 0) {
    return Math.min(RETRY_MAX_MS, Math.round(retryAfterSeconds * 1000));
  }
  const exponent = Math.min(Math.max(0, Number(retries) - 1), 6);
  const delay = Math.min(RETRY_MAX_MS, RETRY_BASE_MS * (2 ** exponent));

  return Math.round(delay * (0.75 + Math.random() * 0.5));
}

function scheduleSyncRetry(delay) {
  if (!online || retryTimer !== null) {
    return;
  }
  retryTimer = setTimeout(() => {
    retryTimer = null;
    void syncOutbox();
  }, delay);
}

function clearSyncRetry() {
  if (retryTimer !== null) {
    clearTimeout(retryTimer);
    retryTimer = null;
  }
}

function dispatchNoteSync(action, pageId, result, outboxId, sourceContent = null) {
  const detail = { action, pageId, result, outboxId, sourceContent };
  dispatchLocalNoteSync(detail);
  syncChannel?.postMessage({ type: 'note-sync', detail });
}

function dispatchLocalNoteSync(detail) {
  window.dispatchEvent(new CustomEvent('offline-note-sync', { detail }));
}

export async function listSyncConflicts() {
  const conflicts = await db.listOutboxConflicts();
  const result = [];
  for (const item of conflicts) {
    const page = await db.getPage(Number(item.page_id));
    result.push({
      id: Number(item.id),
      page_id: Number(item.page_id),
      title: page?.title || `Notiz #${item.page_id}`,
      local_content: item.payload?.content || null,
      server_content: item.conflict?.content || null,
      server_version: Number(item.conflict?.version || 0),
      created_at: item.created_at,
      // Zeitpunkt der letzten lokalen Bearbeitung; ältere Einträge kennen nur created_at.
      local_updated_at: item.updated_at || item.created_at || null,
      server_updated_at: item.conflict?.updated_at || null,
      server_editor_name: item.conflict?.last_editor_name || null,
    });
  }
  return result;
}

export async function resolveConflictKeepLocal(outboxId) {
  return withOutboxLock(() => resolveConflictKeepLocalLocked(outboxId));
}

async function resolveConflictKeepLocalLocked(outboxId) {
  const item = await db.getOutbox(Number(outboxId));
  if (!item || item.status !== 'conflict' || !item.conflict) {
    throw new Error('Der Konflikt ist nicht mehr vorhanden.');
  }
  if (!online) {
    throw new Error('Die Konfliktauflösung benötigt eine Verbindung.');
  }

  const sentRevision = Number(item.revision || 0);
  const sourceContent = item.payload.content;
  const content = await uploadOfflineAttachments(Number(item.page_id), item.payload.content);
  let result;
  try {
    result = await apiFetch(`/api/pages/${item.page_id}/content`, {
      method: 'PUT',
      body: JSON.stringify({
        content,
        version: Number(item.conflict.version),
        force_snapshot: true,
      }),
    });
  } catch (error) {
    if (error.status === 409) {
      const conflict = error.payload?.current || null;
      await db.markNoteConflict(Number(item.id), conflict);
      dispatchNoteSync('conflict', Number(item.page_id), conflict, Number(item.id));
      await refreshQueueState();
      throw new Error('Die Serverfassung wurde erneut geändert. Bitte vergleiche die Fassungen noch einmal.');
    }
    throw error;
  }
  const completion = await db.completeNoteSync(Number(item.id), sentRevision, result);
  await cacheAttachmentsFromContent(result.content);
  dispatchNoteSync(
    completion.completed ? 'resolved-local' : 'synced',
    Number(item.page_id),
    result,
    Number(item.id),
    sourceContent,
  );
  await refreshQueueState();
  syncChannel?.postMessage({ type: 'queue-changed' });
  if (completion.newerRevision) {
    setTimeout(() => void syncOutbox(), 0);
  }

  return result;
}

export async function resolveConflictUseServer(outboxId) {
  return withOutboxLock(() => resolveConflictUseServerLocked(outboxId));
}

async function resolveConflictUseServerLocked(outboxId) {
  const item = await db.getOutbox(Number(outboxId));
  if (!item || item.status !== 'conflict' || !item.conflict) {
    throw new Error('Der Konflikt ist nicht mehr vorhanden.');
  }

  const result = {
    content: item.conflict.content,
    version: Number(item.conflict.version),
    updated_at: item.conflict.updated_at || null,
    last_editor_name: item.conflict.last_editor_name || null,
  };
  const completion = await db.completeNoteSync(Number(item.id), Number(item.revision || 0), result);
  dispatchNoteSync(
    completion.completed ? 'resolved-server' : 'synced',
    Number(item.page_id),
    result,
    Number(item.id),
    item.payload.content,
  );
  await refreshQueueState();
  syncChannel?.postMessage({ type: 'queue-changed' });
  if (completion.newerRevision) {
    setTimeout(() => void syncOutbox(), 0);
  }

  return result;
}

/**
 * Blockierte Einträge (dauerhafte 4xx, z. B. entzogene Schreibrechte) werden vom
 * Sync bewusst übersprungen. Ohne diese beiden Aktionen gäbe es keinen Weg mehr
 * aus dem Zustand heraus.
 *
 * @returns {Promise<Record<string, unknown>[]>}
 */
export async function listBlockedEntries() {
  const unresolved = await db.listOutboxUnresolved();
  const result = [];
  for (const item of unresolved.filter((entry) => entry.status === 'blocked')) {
    const page = await db.getPage(Number(item.page_id));
    result.push({
      id: Number(item.id),
      page_id: Number(item.page_id),
      title: page?.title || `Notiz #${item.page_id}`,
      last_error: item.last_error || 'Sync fehlgeschlagen',
      retries: Number(item.retries || 0),
    });
  }

  return result;
}

/** @param {number} outboxId */
export async function retryBlockedEntry(outboxId) {
  await db.patchOutbox(Number(outboxId), { status: 'pending', last_error: null });
  await refreshQueueState();

  return syncOutbox();
}

/** @param {number} outboxId */
export async function discardBlockedEntry(outboxId) {
  const item = await db.getOutbox(Number(outboxId));
  if (!item) {
    return;
  }
  const deleted = await db.deleteOutboxIfRevision(Number(outboxId), Number(item.revision || 0));
  if (!deleted) {
    throw new Error('Der Eintrag wurde zwischenzeitlich geändert und nicht verworfen.');
  }
  if (online) {
    try {
      const content = await apiFetch(`/api/pages/${item.page_id}/content`);
      await cacheNoteContent(Number(item.page_id), { ...content, dirty: false });
      dispatchNoteSync('resolved-server', Number(item.page_id), content, Number(outboxId));
    } catch {
      /* Der nächste Prefetch holt den Stand nach. */
    }
  }
  await refreshQueueState();
}

export function cancelPrefetch() {
  prefetchAbort?.abort();
}

/**
 * Kennung des ausgelieferten Frontend-Builds. Der Dateiname des Einstiegsskripts
 * trägt einen Inhalts-Hash, ändert sich also mit jedem Deploy - im Dev-Betrieb
 * zeigt die Quelle auf den Vite-Server und bleibt konstant.
 */
function buildSignature() {
  const script = document.querySelector('script[type="module"][src*="/build/"]');

  return script ? new URL(script.src).pathname : 'dev';
}

/**
 * Entscheidet, ob eine Seite überhaupt geladen werden muss. Ohne diese Prüfung
 * holt jeder Durchgang - auch der nach einer Limit-Änderung - den kompletten
 * Bestand erneut, obwohl sich meist nur einzelne Seiten geändert haben.
 *
 * Neben dem Zeitstempel zählt die Zahl der Anhänge: Ein hinzugefügter oder
 * entfernter Anhang berührt den Änderungsstand der Seite selbst nicht.
 *
 * @param {Record<string, unknown>} page Serverstand aus der Seitenliste
 * @param {Map<number, Record<string, unknown>>} known Stand des letzten Durchgangs
 * @param {{ notes: Set<number>, boards: Set<number>, documents: Set<string> }} cached
 * @param {boolean} staleDocuments Gespeichertes Seiten-HTML stammt aus einem älteren Build
 */
function needsPrefetch(page, known, cached, staleDocuments) {
  const pageId = Number(page.id);
  const previous = known.get(pageId);
  if (!previous
    || String(previous.updated_at || '') !== String(page.updated_at || '')
    || Number(previous.attachment_count || 0) !== Number(page.attachment_count || 0)) {
    return true;
  }
  if (staleDocuments || !cached.documents.has(`/app/page/${pageId}`)) {
    return true;
  }

  return page.type === 'task' ? !cached.boards.has(pageId) : !cached.notes.has(pageId);
}

export async function prefetchSelected(options = {}) {
  if (!online || prefetching) {
    return;
  }
  const force = options.force === true;
  const lastPrefetchAt = Number(await db.metaGet('last_prefetch_at', 0));
  if (!force && Date.now() - lastPrefetchAt < PREFETCH_MIN_INTERVAL_MS) {
    return;
  }

  prefetching = true;
  lastError = '';
  prefetchAbort = new AbortController();
  const { signal } = prefetchAbort;
  emit();

  try {
    // Vor dem Laden: Ein zwischenzeitlich geändertes Admin-Limit soll schon für
    // diesen Durchgang gelten.
    await refreshOfflineConfig();
    const limit = await getCacheLimit();
    // Notebook metadata enables offline navigation. An older server may not
    // provide this endpoint yet; retain the previous local list in that case.
    try {
      const notebooks = await apiFetch('/api/notebooks');
      await cacheNotebooks(notebooks?.notebooks);
    } catch {
      /* Keep an existing notebook cache when the endpoint is unavailable. */
    }
    // Der bisherige Stand muss vor dem Überschreiben der Liste feststehen - er
    // ist der Vergleichswert, an dem das Delta hängt.
    const [knownPages, cachedKeys] = await Promise.all([db.getAllPages(), db.getCachedKeys()]);
    const known = new Map(knownPages.map((page) => [Number(page.id), page]));

    const data = await apiFetch('/api/pages?sort=updated');
    const pages = Array.isArray(data.pages) ? data.pages : [];
    await cachePageList(pages);

    const selected = limit === 'all' ? pages : pages.slice(0, Number(limit));
    const unresolved = await db.listOutboxUnresolved();
    const dirtyPageIds = new Set(unresolved.map((item) => Number(item.page_id)));
    const keepIds = selected.map((page) => Number(page.id));
    for (const pageId of dirtyPageIds) {
      keepIds.push(pageId);
    }
    await db.prunePages([...new Set(keepIds)]);

    // Nach einem Deploy ist das gespeicherte Seiten-HTML überholt. Ohne diesen
    // Abgleich bliebe es hängen, bis sich der Inhalt der Seite selbst ändert.
    const build = buildSignature();
    const staleDocuments = String(await db.metaGet('documents_build', '')) !== build;

    const outstanding = selected.filter(
      (page) => needsPrefetch(page, known, cachedKeys, staleDocuments),
    );
    prefetchProgress = { done: 0, total: outstanding.length };
    emit();

    await runPool(outstanding, async (page) => {
      try {
        if (!dirtyPageIds.has(Number(page.id))) {
          if (page.type === 'note') {
            const content = await apiFetch(`/api/pages/${page.id}/content`);
            const stored = await db.putNoteContentIfClean(Number(page.id), {
              page_id: Number(page.id),
              content: content.content,
              version: Number(content.version || 1),
              updated_at: content.updated_at || null,
              last_editor_name: content.last_editor_name || null,
            });
            if (stored) {
              await cacheAttachmentsFromContent(content.content);
            }
          } else if (page.type === 'task') {
            const board = await apiFetch(`/api/pages/${page.id}/board`);
            await cacheBoard(Number(page.id), board);
          }
        }
        // Dateianhänge hängen an der Seite, nicht am Dokument: Sie werden auch
        // für lokal geänderte Notizen aufgefrischt.
        if (page.type === 'note') {
          await prefetchPageAttachments(page);
        }
        const htmlResponse = await fetch(`/app/page/${page.id}`, {
          credentials: 'same-origin',
          signal,
          headers: {
            Accept: 'text/html',
            'X-Requested-With': 'XMLHttpRequest',
          },
        });
        // redirected = abgelaufene Sitzung -> es käme die Login-Seite in den Cache.
        if (htmlResponse.ok && !htmlResponse.redirected) {
          await cacheDocument(`/app/page/${page.id}`, await htmlResponse.text());
        }
      } catch (error) {
        if (error.name !== 'AbortError') {
          lastError = error.message || 'Prefetch teilweise fehlgeschlagen';
        }
      }
    }, signal);

    if (!signal.aborted) {
      await pruneAttachmentCache();
      await db.metaSet('last_prefetch_at', Date.now());
      await db.metaSet('documents_build', build);
    }
    if (navigator.storage?.persist) {
      try {
        await navigator.storage.persist();
      } catch {
        /* optional */
      }
    }
  } catch (error) {
    if (error.name !== 'AbortError') {
      lastError = error.message || 'Prefetch fehlgeschlagen';
    }
  } finally {
    prefetching = false;
    prefetchAbort = null;
    prefetchProgress = { done: 0, total: 0 };
    emit();
  }
}

/**
 * Frischt die Anhänge einer Seite auf. Seiten ohne Anhänge kosten dabei keine
 * Anfrage - bei Limit „Alle" wären das sonst zehntausende zusätzliche Requests.
 *
 * @param {Record<string, unknown>} page
 */
async function prefetchPageAttachments(page) {
  const pageId = Number(page.id);
  if (Number(page.attachment_count || 0) > 0) {
    const files = await apiFetch(`/api/pages/${pageId}/files`);
    await cachePageAttachments(pageId, files.attachments || []);

    return;
  }
  // Der letzte Anhang kann zwischenzeitlich entfernt worden sein.
  if ((await db.getPageAttachments(pageId)).length > 0) {
    await db.putPageAttachments(pageId, []);
  }
}

/**
 * Arbeitet die Auswahl mit begrenzter Parallelität ab. Bei Limit "Alle" wären es
 * sonst zehntausende strikt sequentielle Requests ohne Abbruchmöglichkeit.
 *
 * @param {Record<string, unknown>[]} items
 * @param {(item: Record<string, unknown>) => Promise<void>} worker
 * @param {AbortSignal} signal
 */
async function runPool(items, worker, signal) {
  let index = 0;
  const size = Math.min(PREFETCH_CONCURRENCY, items.length);
  const workers = Array.from({ length: size }, async () => {
    while (index < items.length) {
      if (signal.aborted) {
        return;
      }
      const item = items[index];
      index += 1;
      await worker(item);
      prefetchProgress.done += 1;
      emit();
    }
  });

  await Promise.all(workers);
}

/**
 * Entfernt Dateien, die von keiner gecachten Seite mehr referenziert werden,
 * sowie solche, die ein inzwischen gesenktes Limit überschreiten.
 */
async function pruneAttachmentCache() {
  if (typeof caches === 'undefined') {
    return;
  }
  const contents = await db.getAllNoteContents();
  const keep = new Set();
  for (const row of contents) {
    for (const url of extractAttachmentUrls(row.content)) {
      keep.add(url);
    }
  }
  for (const attachment of await db.getAllPageAttachments()) {
    if (typeof attachment?.url === 'string') {
      keep.add(attachment.url);
    }
  }

  const maxBytes = await getAttachmentMaxBytes();
  const cache = await caches.open(ATTACHMENT_CACHE);
  for (const request of await cache.keys()) {
    if (!keep.has(new URL(request.url).pathname)) {
      await cache.delete(request);
      continue;
    }
    const cached = await cache.match(request);
    if (Number(cached?.headers.get('Content-Length') || 0) > maxBytes) {
      await cache.delete(request);
    }
  }
}

/**
 * @param {{ unregisterWorker?: boolean }} [options]
 */
export async function clearOfflineData(options = {}) {
  cancelPrefetch();
  await db.clearAllOfflineData();
  if (typeof caches !== 'undefined') {
    // Über das Präfix statt über feste Namen: Eine Versionserhöhung im Service
    // Worker (Shell-Cache) hätte sonst gecachtes HTML der beendeten Sitzung
    // stehen lassen.
    const keys = await caches.keys();
    await Promise.all(
      keys.filter((key) => key.startsWith(CACHE_PREFIX)).map((key) => caches.delete(key)),
    );
  }
  // Beim Abmelden muss auch der Worker weg, sonst befüllt er den Shell-Cache
  // sofort wieder mit HTML der beendeten Sitzung.
  if (options.unregisterWorker === true && 'serviceWorker' in navigator) {
    try {
      const registrations = await navigator.serviceWorker.getRegistrations();
      await Promise.all(registrations.map((registration) => registration.unregister()));
    } catch {
      /* optional */
    }
  }
  try {
    const keys = [];
    for (let i = 0; i < localStorage.length; i += 1) {
      const key = localStorage.key(i);
      if (key && key.startsWith('notes-note-cache-')) {
        keys.push(key);
      }
    }
    keys.forEach((key) => localStorage.removeItem(key));
  } catch {
    /* ignore */
  }
  await refreshQueueState();
}

/** @param {File} file @param {number} pageId */
export async function saveOfflineAttachment(file, pageId) {
  if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) {
    throw new Error('Offline können nur PNG-, JPEG- und WebP-Bilder eingefügt werden.');
  }
  if (file.size < 1 || file.size > 10 * 1024 * 1024) {
    throw new Error('Das Bild darf maximal 10 MB groß sein.');
  }
  const id = crypto.randomUUID();
  const dimensions = await imageDimensions(file);
  if (dimensions.width * dimensions.height > 40_000_000) {
    throw new Error('Das Bild hat zu große Abmessungen.');
  }
  await db.putAttachmentDraft({
    id,
    page_id: Number(pageId),
    blob: file,
    file_name: file.name || 'offline-image',
    mime_type: file.type,
    width: dimensions.width,
    height: dimensions.height,
    byte_size: file.size,
    created_at: new Date().toISOString(),
    server_src: null,
  });

  return {
    src: `/offline-attachments/${id}`,
    width: dimensions.width,
    height: dimensions.height,
    mime_type: file.type,
    byte_size: file.size,
    offline: true,
  };
}

async function imageDimensions(file) {
  if ('createImageBitmap' in window) {
    const bitmap = await createImageBitmap(file);
    const dimensions = { width: bitmap.width, height: bitmap.height };
    bitmap.close();
    return dimensions;
  }

  const url = URL.createObjectURL(file);
  try {
    return await new Promise((resolve, reject) => {
      const image = new Image();
      image.onload = () => resolve({ width: image.naturalWidth, height: image.naturalHeight });
      image.onerror = () => reject(new Error('Bildabmessungen konnten nicht gelesen werden.'));
      image.src = url;
    });
  } finally {
    URL.revokeObjectURL(url);
  }
}

async function uploadOfflineAttachments(pageId, content) {
  const cloned = typeof structuredClone === 'function'
    ? structuredClone(content)
    : JSON.parse(JSON.stringify(content));
  const ids = extractOfflineAttachmentIds(cloned);
  if (ids.length === 0) {
    return cloned;
  }

  const replacements = new Map();
  for (const id of ids) {
    const draft = await db.getAttachmentDraft(id);
    if (!draft) {
      throw new Error('Ein lokal eingefügtes Bild ist nicht mehr verfügbar.');
    }
    let serverSrc = draft.server_src;
    if (!serverSrc) {
      const body = new FormData();
      body.append('file', draft.blob, draft.file_name || 'offline-image');
      const uploaded = await apiFetch(`/api/pages/${pageId}/attachments`, {
        method: 'POST',
        body,
      });
      serverSrc = uploaded.src;
      await db.patchAttachmentDraft(id, { server_src: serverSrc });
      await cacheUploadedAttachment(serverSrc, draft.blob, draft.mime_type);
    }
    replacements.set(id, serverSrc);
  }

  replaceOfflineAttachmentUrls(cloned, replacements);
  return cloned;
}

async function cacheUploadedAttachment(src, blob, mimeType) {
  if (typeof caches === 'undefined') {
    return;
  }
  const cache = await caches.open(ATTACHMENT_CACHE);
  await cache.put(src, new Response(blob, {
    headers: { 'Content-Type': mimeType || blob.type || 'application/octet-stream' },
  }));
}

function extractOfflineAttachmentIds(content) {
  const ids = new Set();
  walkContent(content, (node) => {
    if (node.type !== 'image' || typeof node.attrs?.src !== 'string') {
      return;
    }
    const match = node.attrs.src.match(/^\/offline-attachments\/([a-f0-9-]+)$/);
    if (match) {
      ids.add(match[1]);
    }
  });
  return [...ids];
}

function replaceOfflineAttachmentUrls(content, replacements) {
  walkContent(content, (node) => {
    if (node.type !== 'image' || typeof node.attrs?.src !== 'string') {
      return;
    }
    const match = node.attrs.src.match(/^\/offline-attachments\/([a-f0-9-]+)$/);
    if (match && replacements.has(match[1])) {
      node.attrs.src = replacements.get(match[1]);
    }
  });
}

function walkContent(node, callback) {
  if (!node || typeof node !== 'object') {
    return;
  }
  callback(node);
  if (Array.isArray(node.content)) {
    node.content.forEach((child) => walkContent(child, callback));
  }
}

/** @param {unknown} content */
export function extractAttachmentUrls(content) {
  const urls = new Set();
  walkContent(content, (node) => {
    if (node.type === 'image' && typeof node.attrs?.src === 'string') {
      const src = node.attrs.src;
      if (/^\/api\/attachments\/[a-f0-9]{64}$/.test(src)) {
        urls.add(src);
      }
    }
  });
  return [...urls];
}

async function cacheAttachmentsFromContent(content) {
  const urls = extractAttachmentUrls(content);
  if (urls.length === 0 || typeof caches === 'undefined') {
    return;
  }
  const maxBytes = await getAttachmentMaxBytes();
  const cache = await caches.open(ATTACHMENT_CACHE);
  for (const url of urls) {
    try {
      if (await cache.match(url)) {
        continue;
      }
      await cacheWithinLimit(cache, url, maxBytes);
    } catch {
      /* skip single attachment */
    }
  }
}

/**
 * Legt eine Datei nur ab, wenn sie das Admin-Limit einhält. Die Größe steht im
 * Content-Length-Header, also bevor der Körper gelesen wird - zu große Dateien
 * werden abgebrochen statt vollständig geladen. Fehlt der Header, entscheidet
 * die tatsächliche Größe.
 *
 * @param {Cache} cache
 * @param {string} url
 * @param {number} maxBytes
 * @param {number|null} knownSize
 * @returns {Promise<boolean>}
 */
async function cacheWithinLimit(cache, url, maxBytes, knownSize = null) {
  if (maxBytes <= 0 || (knownSize !== null && knownSize > maxBytes)) {
    return false;
  }

  const controller = new AbortController();
  try {
    const response = await fetch(url, { credentials: 'same-origin', signal: controller.signal });
    if (!response.ok) {
      await response.body?.cancel();

      return false;
    }
    if (Number(response.headers.get('Content-Length') || 0) > maxBytes) {
      controller.abort();

      return false;
    }
    const blob = await response.blob();
    if (blob.size > maxBytes) {
      return false;
    }
    await cache.put(url, new Response(blob, {
      headers: {
        'Content-Type': response.headers.get('Content-Type') || blob.type || 'application/octet-stream',
        'Content-Length': String(blob.size),
      },
    }));

    return true;
  } catch {
    return false;
  }
}

/**
 * Sichert die Metadaten der Dateianhänge einer Seite und lädt die Dateien bis
 * zum Admin-Limit mit (FR-OFFLINE-06). Größere Anhänge bleiben sichtbar,
 * brauchen zum Öffnen aber eine Verbindung.
 *
 * @param {number} pageId
 * @param {Record<string, unknown>[]} attachments
 */
export async function cachePageAttachments(pageId, attachments) {
  const list = Array.isArray(attachments) ? attachments : [];
  await db.putPageAttachments(Number(pageId), list);
  if (typeof caches === 'undefined') {
    return list;
  }

  const maxBytes = await getAttachmentMaxBytes();
  const cache = await caches.open(ATTACHMENT_CACHE);
  for (const attachment of list) {
    const url = typeof attachment?.url === 'string' ? attachment.url : '';
    if (!url || await cache.match(url)) {
      continue;
    }
    await cacheWithinLimit(cache, url, maxBytes, Number(attachment.byte_size || 0));
  }

  return list;
}

/** @param {string[]} urls */
export async function invalidateCachedImages(urls) {
  if (typeof caches === 'undefined') {
    return;
  }
  const cache = await caches.open(ATTACHMENT_CACHE);
  await Promise.all(urls.map((url) => cache.delete(url)));
}

export async function invalidateAllCachedImages() {
  if (typeof caches === 'undefined') {
    return;
  }
  const cache = await caches.open(ATTACHMENT_CACHE);
  for (const request of await cache.keys()) {
    if (new URL(request.url).pathname.startsWith('/api/attachments/')) {
      await cache.delete(request);
    }
  }
}

/** @param {number} pageId */
export async function readCachedPageAttachments(pageId) {
  return db.getPageAttachments(Number(pageId));
}

/**
 * Liegt die Datei lokal vor? Maßgeblich ist der Cache selbst, nicht die
 * gemeldete Größe - so stimmt die Anzeige auch nach einer Limitänderung.
 *
 * @param {string} url
 */
export async function isAvailableOffline(url) {
  if (!url || typeof caches === 'undefined') {
    return false;
  }
  try {
    const cache = await caches.open(ATTACHMENT_CACHE);

    return Boolean(await cache.match(url));
  } catch {
    return false;
  }
}

export { cacheAttachmentsFromContent };
