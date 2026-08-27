/**
 * Globaler Fokus-Trap für alle Modals (NFR-A11Y-03). Statt jeden der
 * zahlreichen Dialoge einzeln zu verkabeln, hört ein einziger Tab-Listener auf
 * Dokumentebene zu und hält den Fokus innerhalb des sichtbaren Dialogs mit
 * der höchsten z-index-Ebene. Erkennung ausschließlich über
 * role="dialog"[aria-modal="true"], das alle Overlays im Markup tragen.
 */

const FOCUSABLE_SELECTOR = [
  'a[href]',
  'button:not([disabled])',
  'textarea:not([disabled])',
  'input:not([disabled]):not([type="hidden"])',
  'select:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(', ');

function isVisible(element) {
  return element.offsetWidth > 0 || element.offsetHeight > 0 || element.getClientRects().length > 0;
}

function topmostOpenDialog() {
  const dialogs = Array.from(document.querySelectorAll('[role="dialog"][aria-modal="true"]'))
    .filter(isVisible);
  if (dialogs.length === 0) {
    return null;
  }

  // Bei mehreren gleichzeitig offenen Dialogen (z. B. Bild-Betrachter über
  // einer Notiz) gilt der mit dem höchsten z-index als oberster.
  return dialogs.reduce((topmost, current) => {
    if (!topmost) return current;
    const topmostZ = Number(window.getComputedStyle(topmost).zIndex) || 0;
    const currentZ = Number(window.getComputedStyle(current).zIndex) || 0;
    return currentZ >= topmostZ ? current : topmost;
  }, null);
}

function focusableElements(container) {
  return Array.from(container.querySelectorAll(FOCUSABLE_SELECTOR)).filter(isVisible);
}

function handleTrapKeydown(event) {
  if (event.key !== 'Tab') {
    return;
  }
  const dialog = topmostOpenDialog();
  if (!dialog) {
    return;
  }

  const focusable = focusableElements(dialog);
  if (focusable.length === 0) {
    event.preventDefault();
    return;
  }

  const first = focusable[0];
  const last = focusable[focusable.length - 1];
  const active = document.activeElement;

  if (!dialog.contains(active)) {
    event.preventDefault();
    (event.shiftKey ? last : first).focus();
    return;
  }

  if (event.shiftKey && active === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && active === last) {
    event.preventDefault();
    first.focus();
  }
}

export function initFocusTrap() {
  // Capture-Phase: greift auch dann, wenn ein Dialog selbst keinen eigenen
  // Tab-Handler registriert (die Mehrheit der Fälle).
  document.addEventListener('keydown', handleTrapKeydown, true);
}
