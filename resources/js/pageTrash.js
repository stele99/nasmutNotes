import { apiFetch } from './api.js';

/**
 * Papierkorb-Knopf in der Werkzeugleiste einer geöffneten Seite. Wird in die
 * Alpine-Komponenten aller Seitentypen gemischt - Notiz, Aufgabenliste und
 * Logbuch löschen gleich.
 *
 * Die aufnehmende Komponente bringt `pageId`, `canEditPage` und
 * `savedPageTitle` mit.
 */
export function pageTrashMixin() {
  return {
    /**
     * Seite in den Papierkorb legen. Danach gibt es hier nichts mehr zu
     * zeigen, deshalb zurück zur Übersicht - über dieselben Ereignisse, mit
     * denen auch die Seitenleiste ihre Liste auffrischt und navigiert.
     */
    async trashPage() {
      if (!this.canEditPage) {
        return;
      }
      if (!navigator.onLine) {
        window.alert('Löschen ist offline nicht möglich.');
        return;
      }
      if (!window.confirm(`„${this.savedPageTitle}" in den Papierkorb verschieben?`)) {
        return;
      }

      try {
        await apiFetch(`/api/pages/${this.pageId}`, { method: 'DELETE' });
      } catch (error) {
        window.alert(error.message || 'Die Seite konnte nicht gelöscht werden.');
        return;
      }
      window.dispatchEvent(new Event('pages-changed'));
      window.dispatchEvent(new Event('navigate-home'));
    },
  };
}
