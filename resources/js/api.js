const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

/** @type {Promise<string>|null} */
let tokenRefresh = null;

export function csrfToken() {
  const meta = document.querySelector('meta[name="csrf-token"]');
  return meta ? meta.getAttribute('content') : '';
}

function setCsrfToken(token) {
  let meta = document.querySelector('meta[name="csrf-token"]');
  if (!meta) {
    meta = document.createElement('meta');
    meta.setAttribute('name', 'csrf-token');
    document.head.appendChild(meta);
  }
  meta.setAttribute('content', token);
}

/**
 * Holt ein frisches CSRF-Token vom Server. Offline gecachtes HTML kann ein
 * abgelaufenes Token im <meta>-Tag transportieren – ohne Refresh würde jeder
 * spätere Schreibzugriff (vor allem der Outbox-Sync) dauerhaft 403 liefern.
 *
 * @returns {Promise<string>}
 */
export function refreshCsrfToken() {
  if (!tokenRefresh) {
    tokenRefresh = fetch('/api/session', {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' },
    })
      .then((response) => (response.ok ? response.json() : null))
      .then((data) => {
        const token = data?.csrf_token || '';
        if (token) {
          setCsrfToken(token);
        }
        return token;
      })
      .catch(() => '')
      .finally(() => {
        tokenRefresh = null;
      });
  }

  return tokenRefresh;
}

export async function apiFetch(url, options = {}, allowCsrfRetry = true) {
  const headers = Object.assign(
    {
      Accept: 'application/json',
      'X-CSRF-Token': csrfToken(),
    },
    options.headers || {},
  );

  if (options.body && !(options.body instanceof FormData) && !headers['Content-Type']) {
    headers['Content-Type'] = 'application/json';
  }

  const response = await fetch(url, {
    credentials: 'same-origin',
    ...options,
    headers,
  });

  if (response.status === 204) {
    return null;
  }

  const contentType = response.headers.get('Content-Type') || '';
  const data = contentType.includes('application/json') ? await response.json() : null;

  if (!response.ok) {
    const method = (options.method || 'GET').toUpperCase();
    if (
      response.status === 403
      && data?.error?.code === 'CSRF_FAILED'
      && allowCsrfRetry
      && !SAFE_METHODS.includes(method)
    ) {
      const token = await refreshCsrfToken();
      if (token) {
        return apiFetch(url, options, false);
      }
    }

    const message = data?.error?.message || `Anfrage fehlgeschlagen (${response.status})`;
    const error = new Error(message);
    error.status = response.status;
    error.payload = data;
    throw error;
  }

  return data;
}
