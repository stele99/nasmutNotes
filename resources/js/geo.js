/**
 * Aufnahmeort von Notizen (FR-NOTE-25). Freiwillig und je Gerät einstellbar:
 * Standardmäßig wird er nur auf Klick gesetzt, auf Wunsch schon beim Anlegen.
 */

const MODE_KEY = 'notes-location-mode';

/** Vorgänger dieser Einstellung: reines Ein/Aus. */
const LEGACY_KEY = 'notes-location-capture';

export const LOCATION_MODES = ['manual', 'auto'];

/** Nach dieser Zeit entsteht die Notiz lieber ohne Ort als gar nicht. */
const LOOKUP_TIMEOUT_MS = 8000;

export function isLocationSupported() {
  return typeof navigator !== 'undefined' && 'geolocation' in navigator;
}

/**
 * `manual`: Der Ort kommt erst, wenn er auf der Notiz angefordert wird.
 * `auto`: Schon beim Anlegen einer Notiz wird er mitgeschickt.
 */
export function getLocationMode() {
  try {
    const stored = window.localStorage.getItem(MODE_KEY);
    if (LOCATION_MODES.includes(stored)) {
      return stored;
    }

    return window.localStorage.getItem(LEGACY_KEY) === '1' ? 'auto' : 'manual';
  } catch {
    return 'manual';
  }
}

export function setLocationMode(mode) {
  try {
    window.localStorage.setItem(MODE_KEY, LOCATION_MODES.includes(mode) ? mode : 'manual');
    window.localStorage.removeItem(LEGACY_KEY);
  } catch {
    /* Ohne localStorage bleibt es bei der Voreinstellung "manual". */
  }
}

/**
 * Fragt den Ortungsdienst. Jeder Fehlschlag - abgelehnt, kein Signal,
 * Zeitüberschreitung - endet in `null`; der Aufrufer entscheidet, ob das eine
 * Meldung wert ist.
 *
 * @returns {Promise<{ lat: number, lon: number, accuracy: number|null }|null>}
 */
export function requestLocation() {
  if (!isLocationSupported()) {
    return Promise.resolve(null);
  }

  return new Promise((resolve) => {
    navigator.geolocation.getCurrentPosition(
      (position) => resolve({
        lat: position.coords.latitude,
        lon: position.coords.longitude,
        accuracy: Number.isFinite(position.coords.accuracy) ? position.coords.accuracy : null,
      }),
      () => resolve(null),
      { enableHighAccuracy: false, timeout: LOOKUP_TIMEOUT_MS, maximumAge: 60_000 },
    );
  });
}

/**
 * Beim Anlegen einer Notiz - nur in der automatischen Betriebsart. Sonst bleibt
 * die Notiz zunächst ohne Ort und bekommt ihn auf Klick.
 */
export function captureLocationOnCreate() {
  return getLocationMode() === 'auto' ? requestLocation() : Promise.resolve(null);
}

/**
 * Liest einen von Hand gesetzten Ort: entweder ein Koordinatenpaar oder ein
 * kopierter Kartenlink. Damit lässt sich der Ort einer Notiz auf einen anderen
 * verschieben, ohne dass die Anwendung eine Karte einbinden muss.
 *
 * Erkannt werden u. a.:
 *   48.775846, 9.182932
 *   https://www.openstreetmap.org/?mlat=48.7758&mlon=9.1829#map=16/48.7758/9.1829
 *   https://www.google.com/maps/@48.7758,9.1829,15z
 *   geo:48.7758,9.1829
 *
 * @returns {{ lat: number, lon: number, accuracy: null }|null}
 */
export function parseLocationInput(value) {
  const text = String(value ?? '').trim();
  if (text === '') {
    return null;
  }

  // Ausdrücklich benannte Parameter zuerst: In einem OSM-Link steht hinter
  // `#map=` der Kartenausschnitt, `mlat`/`mlon` dagegen die gesuchte Marke.
  const marker = text.match(/[?&]mlat=(-?\d+(?:\.\d+)?)[^#]*?[?&]mlon=(-?\d+(?:\.\d+)?)/i);
  const pair = marker ?? text.match(/(-?\d{1,3}(?:\.\d+)?)\s*[,/]\s*(-?\d{1,3}(?:\.\d+)?)/);
  if (!pair) {
    return null;
  }

  const lat = Number.parseFloat(pair[1]);
  const lon = Number.parseFloat(pair[2]);
  if (!Number.isFinite(lat) || !Number.isFinite(lon) || Math.abs(lat) > 90 || Math.abs(lon) > 180) {
    return null;
  }

  return { lat, lon, accuracy: null };
}

/**
 * Angezeigt wird die ermittelte Anschrift; die Koordinaten stehen daneben auf
 * zwei Nachkommastellen gekürzt (gespeichert bleiben sie vollständig).
 */
export function locationLabel(location) {
  if (!location) {
    return '';
  }

  const parts = [];
  if (location.label) {
    parts.push(location.label);
  }
  parts.push(`${Number(location.lat).toFixed(2)}, ${Number(location.lon).toFixed(2)}`);
  if (!location.label && location.accuracy) {
    parts.push(`±${Math.round(Number(location.accuracy))} m`);
  }

  return parts.join(' · ');
}

/**
 * Link zum Nachschlagen eines einzelnen Ortes bei OpenStreetMap (öffnet in
 * einem neuen Tab). Die Umkreissuche (FR-NOTE-27) zeigt zusätzlich eine
 * eingebettete Karte, deren Kacheln aber über den Server laufen - siehe
 * `nearbySearch.js` und `MapTileProxy`.
 */
export function locationMapUrl(location) {
  if (!location) {
    return '';
  }
  const lat = Number(location.lat);
  const lon = Number(location.lon);

  return `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lon}#map=16/${lat}/${lon}`;
}
