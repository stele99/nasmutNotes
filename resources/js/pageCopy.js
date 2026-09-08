import { apiFetch } from './api.js';
import { showToast } from './toast.js';

/**
 * „Kopie erstellen" in der Werkzeugleiste einer geöffneten Notiz
 * (FR-NOTE-28). Die Kopie entsteht serverseitig samt Bildern und
 * Dateianhängen (`PageCopyService`) und heißt „Kopie von <Titel>".
 *
 * Eine eigene Notiz wird ohne Rückfrage im selben Notizbuch dupliziert. Eine
 * geteilte Notiz hat im eigenen Workspace kein Notizbuch - die Zuordnung
 * gehört dem Eigentümer und bleibt Empfängern verborgen (`PageService`) -,
 * deshalb fragt dort ein kleiner Dialog nach dem Zielnotizbuch.
 *
 * Die aufnehmende Komponente bringt `pageId` mit und ruft `initPageCopy()`
 * mit ihrem Wurzelelement auf.
 */
export function pageCopyMixin() {
  return {
    copyingPage: false,
    copyDialogOpen: false,
    copyDialogNotebooks: [],
    copyDialogNotebookId: '',
    copyDialogLoading: false,
    copyError: '',
    copyFromShared: false,

    initPageCopy(pageRoot) {
      this.copyFromShared = pageRoot?.dataset.pageIsShared === '1';
    },

    async copyPage() {
      if (this.copyingPage) {
        return;
      }
      if (!navigator.onLine) {
        window.alert('Kopieren ist offline nicht möglich.');
        return;
      }
      // Negative Kennung: offline angelegt und noch nicht übertragen - der
      // Server kennt die Vorlage also noch gar nicht.
      if (Number(this.pageId) < 0) {
        window.alert('Diese Notiz ist noch nicht übertragen und kann deshalb nicht kopiert werden.');
        return;
      }

      if (!this.copyFromShared) {
        await this.runPageCopy(null, false);
        return;
      }

      this.copyError = '';
      this.copyDialogNotebookId = '';
      this.copyDialogOpen = true;
      await this.loadCopyNotebooks();
    },

    closeCopyDialog() {
      if (this.copyingPage) {
        return;
      }
      this.copyDialogOpen = false;
      this.copyError = '';
    },

    /** Nur eigene Notizbücher: In fremde, lediglich geteilte darf nicht kopiert werden. */
    async loadCopyNotebooks() {
      this.copyDialogLoading = true;
      try {
        const data = await apiFetch('/api/notebooks');
        this.copyDialogNotebooks = (data.notebooks || []).filter((notebook) => notebook.is_owner !== false);
      } catch (error) {
        this.copyError = error.message || 'Die Notizbücher konnten nicht geladen werden.';
      } finally {
        this.copyDialogLoading = false;
      }
    },

    async copyPageToSelectedNotebook() {
      await this.runPageCopy(this.copyDialogNotebookId === '' ? null : Number(this.copyDialogNotebookId), true);
    },

    /**
     * `withNotebook = false` lässt das Feld weg; der Server legt die Kopie
     * dann in dasselbe Notizbuch wie die Vorlage.
     */
    async runPageCopy(notebookId, withNotebook) {
      this.copyingPage = true;
      this.copyError = '';
      try {
        const page = await apiFetch(`/api/pages/${this.pageId}/duplicate`, {
          method: 'POST',
          body: JSON.stringify(withNotebook ? { notebook_id: notebookId } : {}),
        });
        this.copyDialogOpen = false;
        // Die Seitenleiste ist eine eigene Alpine-Komponente: Erst auffrischen,
        // dann dorthin wechseln - sonst fehlt die Kopie in der Liste.
        window.dispatchEvent(new Event('pages-changed'));
        showToast(`„${page.title}" wurde angelegt.`);
        window.dispatchEvent(new CustomEvent('navigate-page', { detail: page }));
      } catch (error) {
        const message = error.message || 'Die Kopie konnte nicht erstellt werden.';
        if (this.copyDialogOpen) {
          this.copyError = message;
        } else {
          window.alert(message);
        }
      } finally {
        this.copyingPage = false;
      }
    },
  };
}
