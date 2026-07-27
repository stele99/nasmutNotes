/* Shareinfo service worker: shell, assets, attachments */
const SHELL_CACHE = 'shareinfo-shell-v3';
const ATTACHMENT_CACHE = 'shareinfo-attachments-v1';
const SHELL_URLS = [
  '/app',
  '/favicon.svg',
  '/manifest.webmanifest',
  '/icon/favicon-16.png',
  '/icon/favicon-32.png',
  '/icon/apple-touch-icon.png',
  '/icon/icon-192.png',
  '/icon/icon-512.png',
  '/icon/icon-maskable-192.png',
  '/icon/icon-maskable-512.png',
];
const OFFLINE_DB = 'shareinfo-offline';
const ATTACHMENT_LIMIT_KEY = 'attachment_max_bytes';
const DEFAULT_ATTACHMENT_MAX_BYTES = 250 * 1024;
const LIMIT_TTL_MS = 60_000;

/** @type {{ value: number, readAt: number }|null} */
let attachmentLimit = null;

/* Hinweisbild für Anhänge, die das Offline-Limit überschreiten. Es geht als
   gültiges Bild heraus, damit im Notizfluss kein kaputtes Symbol steht. */
const OFFLINE_IMAGE_HINT = [
  '<svg xmlns="http://www.w3.org/2000/svg" width="480" height="270" viewBox="0 0 480 270"',
  ' role="img" aria-label="Bild nur mit Internetverbindung verfügbar">',
  '<rect width="480" height="270" rx="12" fill="#e5e7eb"/>',
  '<rect x="1" y="1" width="478" height="268" rx="11" fill="none" stroke="#9ca3af" stroke-dasharray="8 6"/>',
  '<g fill="none" stroke="#6b7280" stroke-width="9" stroke-linecap="round" stroke-linejoin="round">',
  '<path d="M196 118a30 30 0 0 1 57-9 24 24 0 0 1 29 30h-86a24 24 0 0 1 0-21z"/>',
  '<path d="M186 96l108 76"/>',
  '</g>',
  '<text x="240" y="196" text-anchor="middle" font-family="system-ui, sans-serif" font-size="19"',
  ' fill="#374151">Bild nur online verfügbar</text>',
  '<text x="240" y="222" text-anchor="middle" font-family="system-ui, sans-serif" font-size="15"',
  ' fill="#6b7280">Dafür ist eine Internetverbindung nötig.</text>',
  '</svg>',
].join('');

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(SHELL_CACHE);
    await Promise.all(SHELL_URLS.map((url) => cache.add(url).catch(() => undefined)));
    self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(
      keys
        .filter((key) => key.startsWith('shareinfo-') && key !== SHELL_CACHE && key !== ATTACHMENT_CACHE)
        .map((key) => caches.delete(key)),
    );
    self.clients.claim();
  })());
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) {
    return;
  }

  // Share-Tokens sind Bearer-Credentials. Öffentliche Freigaben dürfen nie in
  // den nutzerspezifischen Shell- oder Attachment-Cache gelangen.
  if (url.pathname.startsWith('/s/')) {
    return;
  }

  if (url.pathname.startsWith('/offline-attachments/')) {
    event.respondWith(offlineAttachmentStrategy(url.pathname));
    return;
  }

  if (url.pathname.startsWith('/api/attachments/') || url.pathname.startsWith('/api/page-attachments/')) {
    event.respondWith(attachmentStrategy(request));
    return;
  }

  if (url.pathname.startsWith('/build/')) {
    event.respondWith(cacheFirst(request, SHELL_CACHE));
    return;
  }

  if (request.mode === 'navigate' || request.headers.get('accept')?.includes('text/html')) {
    event.respondWith(networkFirstNavigation(request));
  }
});

async function offlineAttachmentStrategy(pathname) {
  const id = pathname.slice('/offline-attachments/'.length);
  if (!/^[a-f0-9-]+$/.test(id)) {
    return new Response('Not found', { status: 404 });
  }
  try {
    const draft = await getOfflineAttachment(id);
    if (!draft?.blob) {
      return new Response('Not found', { status: 404 });
    }
    return new Response(draft.blob, {
      headers: {
        'Content-Type': draft.mime_type || draft.blob.type || 'application/octet-stream',
        'Cache-Control': 'no-store',
      },
    });
  } catch (error) {
    return new Response('Offline image unavailable', { status: 503 });
  }
}

function getOfflineAttachment(id) {
  return readFromStore('attachment_drafts', id);
}

/**
 * Ohne Versionsangabe geöffnet: Der Worker löst nie selbst ein Upgrade aus, das
 * Schema gehört der Seite (resources/js/offline/db.js). Fehlt ein Speicher,
 * wurde die Datenbank noch nicht angelegt.
 */
function openOfflineDb() {
  return new Promise((resolve, reject) => {
    const open = indexedDB.open(OFFLINE_DB);
    open.onerror = () => reject(open.error);
    open.onblocked = () => reject(new Error('IndexedDB blockiert'));
    open.onsuccess = () => resolve(open.result);
  });
}

async function readFromStore(storeName, key) {
  const db = await openOfflineDb();
  try {
    if (!db.objectStoreNames.contains(storeName)) {
      return null;
    }

    return await new Promise((resolve, reject) => {
      const tx = db.transaction(storeName);
      const request = tx.objectStore(storeName).get(key);
      request.onsuccess = () => resolve(request.result ?? null);
      request.onerror = () => reject(request.error);
      tx.onabort = () => reject(tx.error);
    });
  } finally {
    db.close();
  }
}

/**
 * Vom Admin gesetzte Grenze, bis zu der Anhänge offline vorgehalten werden. Der
 * Wert wird kurz gepuffert, damit nicht jede Bildanfrage die Datenbank öffnet.
 */
async function currentAttachmentLimit() {
  if (attachmentLimit && Date.now() - attachmentLimit.readAt < LIMIT_TTL_MS) {
    return attachmentLimit.value;
  }

  let value = DEFAULT_ATTACHMENT_MAX_BYTES;
  try {
    const row = await readFromStore('meta', ATTACHMENT_LIMIT_KEY);
    const stored = Number(row?.value);
    if (Number.isFinite(stored) && stored >= 0) {
      value = stored;
    }
  } catch (error) {
    /* Vorgabewert gilt */
  }
  attachmentLimit = { value, readAt: Date.now() };

  return value;
}

/**
 * Anhänge sind unter ihrer URL unveränderlich (Bild-Token bzw. Anhang-ID),
 * deshalb zuerst aus dem Cache - das spart bei jeder Ansicht einen Roundtrip.
 * Neu geladene Dateien landen nur dann im Cache, wenn sie das Admin-Limit
 * einhalten; größere bleiben eine reine Online-Ressource.
 */
async function attachmentStrategy(request) {
  const cache = await caches.open(ATTACHMENT_CACHE);
  const cached = await cache.match(request);
  if (cached) {
    return cached;
  }

  try {
    const response = await fetch(request);
    if (!response.ok) {
      return response;
    }
    const maxBytes = await currentAttachmentLimit();
    const size = Number(response.headers.get('Content-Length') || 0);
    if (maxBytes > 0 && size > 0 && size <= maxBytes) {
      await cache.put(request, response.clone());
    }

    return response;
  } catch (error) {
    return offlineAttachmentResponse(request);
  }
}

/**
 * Ohne Netz und ohne lokale Kopie: Bilder bekommen einen sichtbaren Platzhalter
 * mit dem Hinweis auf die nötige Verbindung, alles andere eine 503-Antwort, die
 * die Oberfläche als Meldung zeigt.
 */
function offlineAttachmentResponse(request) {
  const wantsImage = request.destination === 'image'
    || (request.headers.get('accept') || '').includes('image/');

  if (wantsImage) {
    return new Response(OFFLINE_IMAGE_HINT, {
      headers: {
        'Content-Type': 'image/svg+xml; charset=utf-8',
        'Cache-Control': 'no-store',
      },
    });
  }

  return new Response('Für diesen Anhang ist eine Internetverbindung nötig.', {
    status: 503,
    statusText: 'Offline',
    headers: { 'Content-Type': 'text/plain; charset=utf-8' },
  });
}

async function cacheFirst(request, cacheName) {
  const cache = await caches.open(cacheName);
  const cached = await cache.match(request);
  if (cached) {
    return cached;
  }
  try {
    const response = await fetch(request);
    if (response.ok) {
      await cache.put(request, response.clone());
    }
    return response;
  } catch (error) {
    return cached || Response.error();
  }
}

async function networkFirstNavigation(request) {
  const cache = await caches.open(SHELL_CACHE);
  try {
    const response = await fetch(request);
    // Ein Redirect bedeutet hier abgelaufene Sitzung: die Antwort ist die
    // Login-Seite. Würde sie unter /app landen, bekäme der Nutzer offline
    // dauerhaft den Login statt seines Workspace.
    if (response.ok && !response.redirected) {
      await cache.put(request, response.clone());
    }
    return response;
  } catch (error) {
    const cached = await cache.match(request);
    if (cached) {
      return cached;
    }
    const shell = await cache.match('/app');
    if (shell) {
      return shell;
    }
    return new Response('Offline – bitte einmal online öffnen.', {
      status: 503,
      headers: { 'Content-Type': 'text/plain; charset=utf-8' },
    });
  }
}
