/**
 * Abruf einer Seite für den Wechsel innerhalb der App (pageList.js).
 *
 * - Nur <main>: `?_partial=main` liefert Titel und Inhaltsbereich statt des
 *   ganzen Dokuments. Die Hülle (Notizbuchleiste, Schublade, Dialoge) steht
 *   schon im Dokument und machte zwei Drittel der Antwort aus.
 * - Vorwärmen: Der Abruf beginnt beim Berühren eines Eintrags, nicht erst beim
 *   Klick. Wird daraus ein Scrollen, bricht `pointercancel` ihn ab.
 */

const WARM_TTL_MS = 10_000;

/** Die Sitzung ist abgelaufen: Die Antwort ist die Anmeldeseite. */
export class SessionRedirectError extends Error {}

export function fragmentUrl(url) {
  const target = new URL(url, window.location.origin);
  target.searchParams.set('_partial', 'main');

  return target.pathname + target.search;
}

/**
 * @param {string} url Adresse der Seite ohne `_partial`
 * @param {AbortSignal} [signal]
 * @returns {Promise<string>} HTML mit <title> und <main>
 */
export async function fetchFragment(url, signal) {
  const response = await fetch(fragmentUrl(url), {
    credentials: 'same-origin',
    signal,
    headers: {
      Accept: 'text/html',
      'X-Requested-With': 'XMLHttpRequest',
    },
  });
  if (response.redirected) {
    throw new SessionRedirectError('Sitzung abgelaufen');
  }
  if (!response.ok) {
    const error = new Error(`Navigation fehlgeschlagen (${response.status})`);
    error.status = response.status;
    throw error;
  }

  return response.text();
}

/** @type {Map<string, { promise: Promise<string>, controller: AbortController, at: number }>} */
const warm = new Map();

/** Beginnt den Abruf vorab; ein zweiter Aufruf für dieselbe Adresse tut nichts. */
export function warmFragment(url) {
  const now = Date.now();
  for (const [key, entry] of warm) {
    if (now - entry.at > WARM_TTL_MS) {
      entry.controller.abort();
      warm.delete(key);
    }
  }
  if (warm.has(url)) {
    return;
  }
  const controller = new AbortController();
  const promise = fetchFragment(url, controller.signal);
  // Wird der Abruf nie abgeholt, soll sein Fehlschlag nicht als unbehandelt gelten.
  promise.catch(() => undefined);
  warm.set(url, { promise, controller, at: now });
}

/** Bricht einen Vorab-Abruf ab - der Finger scrollt, statt zu tippen. */
export function coolFragment(url) {
  const entry = warm.get(url);
  if (entry) {
    entry.controller.abort();
    warm.delete(url);
  }
}

/**
 * Übernimmt einen laufenden Vorab-Abruf für die Navigation, sonst null.
 *
 * @returns {{ promise: Promise<string>, controller: AbortController }|null}
 */
export function takeWarmFragment(url) {
  const entry = warm.get(url);
  warm.delete(url);
  if (!entry) {
    return null;
  }
  if (Date.now() - entry.at > WARM_TTL_MS) {
    entry.controller.abort();
    return null;
  }

  return entry;
}

/** Wartet höchstens `ms`; null, wenn die Zeit vorher abläuft. */
export function within(promise, ms) {
  let timer = 0;
  const timeout = new Promise((resolve) => {
    timer = window.setTimeout(() => resolve(null), ms);
  });

  return Promise.race([promise.then((value) => ({ value })), timeout])
    .finally(() => window.clearTimeout(timer));
}
