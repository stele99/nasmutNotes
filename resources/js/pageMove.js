import { apiFetch } from './api.js';
import { showToast } from './toast.js';

/**
 * „Verschieben" im Menü einer geöffneten Seite: Zielnotizbuch wählen, ohne
 * die Seitenleiste zu brauchen. Auf dem Handy gibt es kein Ziehen und Ablegen
 * auf ein Notizbuch - bisher ließ sich eine Notiz dort gar nicht umhängen.
 *
 * Verschoben wird über denselben Endpunkt wie beim Ziehen in der Seitenleiste
 * (`POST /api/pages/move`). Zur Wahl stehen eigene Notizbücher und geteilte
 * mit Schreibrecht. Wechselt die Seite damit den Eigentümer (Ziel ist ein
 * geteiltes Notizbuch), verlangt der Server eine Bestätigung - dieselbe
 * Rückfrage wie in pageList.js.
 *
 * Die aufnehmende Komponente bringt `pageId` mit und ruft `initPageMove()`
 * mit ihrem Wurzelelement auf; dessen `data-page-notebook-id` ist das
 * aktuelle Notizbuch (leer: keins).
 */
export function pageMoveMixin() {
  return {
    moveDialogOpen: false,
    moveNotebooks: [],
    moveTargetId: '',
    moveCurrentNotebookId: '',
    moveLoading: false,
    movingPage: false,
    moveError: '',

    initPageMove(pageRoot) {
      this.moveCurrentNotebookId = pageRoot?.dataset.pageNotebookId || '';
    },

    async openMoveDialog() {
      if (this.movingPage) {
        return;
      }
      if (!navigator.onLine) {
        window.alert('Verschieben ist offline nicht möglich.');
        return;
      }
      // Negative Kennung: offline angelegt und noch nicht übertragen.
      if (Number(this.pageId) < 0) {
        window.alert('Diese Seite ist noch nicht übertragen und kann deshalb nicht verschoben werden.');
        return;
      }

      this.moveError = '';
      this.moveTargetId = this.moveCurrentNotebookId;
      this.moveDialogOpen = true;
      this.moveLoading = true;
      try {
        const data = await apiFetch('/api/notebooks');
        this.moveNotebooks = (data.notebooks || []).filter(
          (notebook) => notebook.is_owner !== false || notebook.share_permission === 'write',
        );
      } catch (error) {
        this.moveError = error.message || 'Die Notizbücher konnten nicht geladen werden.';
      } finally {
        this.moveLoading = false;
      }
    },

    closeMoveDialog() {
      if (this.movingPage) {
        return;
      }
      this.moveDialogOpen = false;
      this.moveError = '';
    },

    /** Geteilte Notizbücher tragen den Eigentümer im Namen - dorthin wechselt die Seite. */
    moveNotebookLabel(notebook) {
      if (notebook.is_owner === false) {
        return notebook.owner_name
          ? `${notebook.name} · geteilt von ${notebook.owner_name}`
          : `${notebook.name} · geteilt`;
      }

      return notebook.name;
    },

    isCurrentMoveTarget(notebookId) {
      return String(notebookId) === String(this.moveCurrentNotebookId);
    },

    isSelectedMoveTarget(notebookId) {
      return String(notebookId) === String(this.moveTargetId);
    },

    selectMoveTarget(notebookId) {
      this.moveTargetId = String(notebookId);
    },

    isMoveUnchanged() {
      return String(this.moveTargetId) === String(this.moveCurrentNotebookId);
    },

    async confirmMove() {
      if (this.movingPage || this.isMoveUnchanged()) {
        return;
      }

      const target = this.moveTargetId === '' ? null : Number(this.moveTargetId);
      const send = (confirmTransfer) => apiFetch('/api/pages/move', {
        method: 'POST',
        body: JSON.stringify({ page_ids: [Number(this.pageId)], notebook_id: target, confirm_transfer: confirmTransfer }),
      });

      this.movingPage = true;
      this.moveError = '';
      try {
        try {
          await send(false);
        } catch (error) {
          if (error.payload?.error?.code !== 'TRANSFER_CONFIRMATION_REQUIRED') {
            throw error;
          }
          if (!window.confirm(`${error.message}\n\nTrotzdem verschieben?`)) {
            return;
          }
          await send(true);
        }

        const notebook = this.moveNotebooks.find((candidate) => Number(candidate.id) === target);
        this.moveDialogOpen = false;
        window.dispatchEvent(new Event('pages-changed'));
        showToast(notebook ? `Nach „${notebook.name}" verschoben.` : 'Aus dem Notizbuch genommen.');

        // Breadcrumb, Eigentümer und Freigabe-Status stehen serverseitig im
        // Seitenkopf - nach dem Verschieben die Seite neu aufbauen. Scheitert
        // das, ist trotzdem verschoben; der Kopf stimmt dann nach dem
        // nächsten Öffnen.
        try {
          const page = await apiFetch(`/api/pages/${this.pageId}`);
          window.dispatchEvent(new CustomEvent('reload-page', { detail: page }));
        } catch {
          /* siehe oben */
        }
      } catch (error) {
        this.moveError = error.message || 'Die Seite konnte nicht verschoben werden.';
      } finally {
        this.movingPage = false;
      }
    },
  };
}
