/**
 * Eine frisch angelegte Seite trägt zunächst nur „Neue Notiz", „Neue
 * Aufgabenliste" oder „Neues Logbuch". Damit der eigene Name direkt eingetippt werden kann, öffnet die
 * Zielseite die Titelbearbeitung selbst und markiert den Vorschlag komplett.
 *
 * Der Merker liegt in der sessionStorage: Die Zielseite wird normalerweise über
 * die SPA-Navigation eingesetzt, im Rückfall aber über einen vollen
 * Seitenwechsel geladen - eine Eigenschaft am Fensterobjekt ginge dabei
 * verloren.
 */
const STORAGE_KEY = 'newPageTitleEdit';

/** Bis dahin sollte die neue Seite stehen; sonst klappt die Tastatur wieder zu. */
const KEYBOARD_HOLD_MS = 10_000;

let keyboardProxy = null;
let keyboardHoldTimer = 0;

function onFocusMoved(event) {
  if (event.target !== keyboardProxy) {
    releaseKeyboardHold();
  }
}

/**
 * iOS öffnet die Bildschirmtastatur nur, wenn ein Feld noch im selben Antippen
 * den Fokus bekommt. Der Titel der neuen Seite ist erst nach Serveranfrage und
 * Seitenwechsel da - sein `focus()` markiert den Text dann zwar, die Tastatur
 * bleibt aber zu. Ein unsichtbares Hilfsfeld nimmt den Fokus deshalb sofort im
 * Antippen; wandert er später per Skript zum Titel (oder kurz zum Editor),
 * lässt iOS die Tastatur offen. Das Hilfsfeld verschwindet, sobald ein anderes
 * Element den Fokus hat.
 *
 * Muss synchron im Klick-Handler laufen, vor dem ersten `await`. Nur auf
 * Touchgeräten - mit Maus und Tastatur gibt es nichts aufzuklappen.
 */
export function holdKeyboardForTitleEdit() {
  if (typeof window.matchMedia !== 'function' || !window.matchMedia('(pointer: coarse)').matches) {
    return;
  }
  releaseKeyboardHold();

  const proxy = document.createElement('input');
  proxy.type = 'text';
  proxy.className = 'keyboard-proxy';
  proxy.tabIndex = -1;
  proxy.setAttribute('aria-hidden', 'true');
  proxy.setAttribute('autocomplete', 'off');
  document.body.appendChild(proxy);
  keyboardProxy = proxy;
  proxy.focus({ preventScroll: true });

  document.addEventListener('focusin', onFocusMoved);
  keyboardHoldTimer = window.setTimeout(releaseKeyboardHold, KEYBOARD_HOLD_MS);
}

/** Beendet das Offenhalten - auch, wenn das Anlegen scheitert. */
export function releaseKeyboardHold() {
  window.clearTimeout(keyboardHoldTimer);
  document.removeEventListener('focusin', onFocusMoved);
  const proxy = keyboardProxy;
  keyboardProxy = null;
  if (!proxy) {
    return;
  }
  // Hält das Hilfsfeld noch selbst den Fokus, ist nichts nachgefolgt - dann
  // soll die Tastatur auch wieder zugehen.
  if (document.activeElement === proxy) {
    proxy.blur();
  }
  proxy.remove();
}

export function markNewPageForTitleEdit(pageId) {
  try {
    window.sessionStorage.setItem(STORAGE_KEY, String(Number(pageId)));
  } catch {
    /* Privater Modus ohne sessionStorage: dann bleibt es beim Vorschlagstitel. */
  }
}

/**
 * Gibt genau einmal true zurück - der Titel soll nur beim ersten Öffnen der
 * neuen Seite markiert werden, nicht bei jeder späteren Rückkehr.
 */
export function consumeNewPageTitleEdit(pageId) {
  try {
    const stored = window.sessionStorage.getItem(STORAGE_KEY);
    if (stored === null || Number(stored) !== Number(pageId)) {
      return false;
    }
    window.sessionStorage.removeItem(STORAGE_KEY);

    return true;
  } catch {
    return false;
  }
}
