import { apiFetch } from './api.js';

/**
 * Papierkorb: gelöschte Seiten bleiben `TRASH_RETENTION_DAYS` (Default 90)
 * erhalten und lassen sich einzeln wiederherstellen oder endgültig entfernen;
 * zusätzlich kann der Papierkorb komplett geleert werden (FR-WS-06).
 */
export function trashPanel() {
  return {
    open: false,
    pages: [],
    retentionDays: 90,
    loading: false,
    busy: false,
    error: '',

    async openDialog() {
      this.open = true;
      this.error = '';
      await this.refresh();
    },

    closeDialog() {
      if (this.busy) {
        return;
      }
      this.open = false;
    },

    async refresh() {
      if (!navigator.onLine) {
        this.error = 'Der Papierkorb ist offline nicht verfügbar.';
        return;
      }
      this.loading = true;
      try {
        const data = await apiFetch('/api/pages?trashed=1');
        this.pages = data.pages || [];
        this.retentionDays = Number(data.trash_retention_days || 90);
      } catch (error) {
        this.error = error.message || 'Der Papierkorb konnte nicht geladen werden.';
      } finally {
        this.loading = false;
      }
    },

    typeLabel(page) {
      return page.type === 'note' ? 'Notiz' : 'Aufgabenliste';
    },

    /** Verbleibende Tage bis zur endgültigen Löschung. */
    remainingLabel(page) {
      if (!page.deleted_at) {
        return '';
      }
      const deletedAt = new Date(page.deleted_at).getTime();
      const elapsedDays = Math.floor((Date.now() - deletedAt) / 86_400_000);
      const left = this.retentionDays - elapsedDays;

      if (left <= 0) {
        return 'wird demnächst endgültig gelöscht';
      }

      return left === 1 ? 'noch 1 Tag' : `noch ${left} Tage`;
    },

    async restore(page) {
      await this.run(() => apiFetch(`/api/pages/${page.id}/restore`, { method: 'POST' }));
    },

    async purge(page) {
      if (!window.confirm(`„${page.title}" endgültig löschen? Das lässt sich nicht rückgängig machen.`)) {
        return;
      }
      await this.run(() => apiFetch(`/api/pages/${page.id}/purge`, { method: 'DELETE' }));
    },

    async emptyTrash() {
      if (this.pages.length === 0) {
        return;
      }
      if (!window.confirm(
        `Papierkorb leeren? ${this.pages.length} Seite(n) werden endgültig gelöscht.`,
      )) {
        return;
      }
      await this.run(() => apiFetch('/api/pages/trash', { method: 'DELETE' }));
    },

    async run(action) {
      this.busy = true;
      this.error = '';
      try {
        await action();
        await this.refresh();
        // Die Seitenliste in der Leiste kennt den neuen Stand noch nicht.
        this.$dispatch('pages-changed');
      } catch (error) {
        this.error = error.message || 'Die Aktion ist fehlgeschlagen.';
      } finally {
        this.busy = false;
      }
    },
  };
}
