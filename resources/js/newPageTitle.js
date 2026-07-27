/**
 * Eine frisch angelegte Seite trägt zunächst nur „Neue Notiz" bzw. „Neue
 * Task-Seite". Damit der eigene Name direkt eingetippt werden kann, öffnet die
 * Zielseite die Titelbearbeitung selbst und markiert den Vorschlag komplett.
 *
 * Der Merker liegt in der sessionStorage: Die Zielseite wird normalerweise über
 * die SPA-Navigation eingesetzt, im Rückfall aber über einen vollen
 * Seitenwechsel geladen - eine Eigenschaft am Fensterobjekt ginge dabei
 * verloren.
 */
const STORAGE_KEY = 'newPageTitleEdit';

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
