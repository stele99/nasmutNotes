import { apiFetch } from './api.js';

/**
 * Export ausgewählter Notizbücher als ZIP (FR-EXP-03). Der Download läuft über
 * einen gewöhnlichen GET-Aufruf, damit der Browser das Archiv selbst auf die
 * Platte streamt, statt es im Speicher zu halten.
 */
export function noteExport() {
  return {
    open: false,
    loading: false,
    busy: false,
    error: '',
    notebooks: [],
    selected: [],

    async openDialog() {
      this.open = true;
      this.error = '';
      this.loading = true;
      try {
        const data = await apiFetch('/api/export/notebooks');
        this.notebooks = (data.notebooks || []).map((notebook) => ({
          ...notebook,
          key: notebook.id === null ? 'unassigned' : String(notebook.id),
        }));
        // Beim Öffnen alles vorwählen - der häufigste Fall ist der Gesamtexport.
        this.selected = this.notebooks
          .filter((notebook) => notebook.page_count > 0)
          .map((notebook) => notebook.key);
      } catch (error) {
        this.error = error.message || 'Die Notizbücher konnten nicht geladen werden.';
      } finally {
        this.loading = false;
      }
    },

    closeDialog() {
      if (this.busy) {
        return;
      }
      this.open = false;
    },

    isSelected(notebook) {
      return this.selected.includes(notebook.key);
    },

    toggle(notebook) {
      this.selected = this.isSelected(notebook)
        ? this.selected.filter((key) => key !== notebook.key)
        : [...this.selected, notebook.key];
    },

    selectAll() {
      this.selected = this.notebooks
        .filter((notebook) => notebook.page_count > 0)
        .map((notebook) => notebook.key);
    },

    selectNone() {
      this.selected = [];
    },

    selectedPageCount() {
      return this.notebooks
        .filter((notebook) => this.isSelected(notebook))
        .reduce((total, notebook) => total + Number(notebook.page_count || 0), 0);
    },

    canExport() {
      return !this.busy && this.selected.length > 0;
    },

    summaryLabel() {
      if (this.selected.length === 0) {
        return 'Nichts ausgewählt';
      }

      return `${this.selected.length} Notizbuch/Notizbücher · ${this.selectedPageCount()} Seite(n)`;
    },

    exportLabel() {
      return this.busy ? 'Archiv wird erstellt…' : 'Exportieren';
    },

    countLabel(notebook) {
      return `${Number(notebook.page_count || 0)} Seite(n)`;
    },

    /**
     * Ein echter Seitenaufruf statt fetch(): Bei `Content-Disposition:
     * attachment` bleibt die Seite stehen und der Browser übernimmt den
     * Download samt Fortschrittsanzeige.
     */
    startExport() {
      if (!this.canExport()) {
        return;
      }
      const ids = this.selected.filter((key) => key !== 'unassigned');
      const parameters = new URLSearchParams();
      if (ids.length > 0) {
        parameters.set('notebooks', ids.join(','));
      }
      if (this.selected.includes('unassigned')) {
        parameters.set('unassigned', '1');
      }

      this.busy = true;
      window.location.href = `/api/export/workspace?${parameters.toString()}`;
      // Der Download blockiert die Seite nicht; der Knopf darf gleich wieder frei sein.
      window.setTimeout(() => {
        this.busy = false;
        this.open = false;
      }, 1500);
    },
  };
}
