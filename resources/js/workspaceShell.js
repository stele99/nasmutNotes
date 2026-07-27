import { apiFetch } from './api.js';
import {
  cacheNotebooks,
  clearOfflineData,
  getCachedNotebooks,
} from './offline/runtime.js';

const NOTEBOOK_RAIL_WIDTH_KEY = 'nasmut-notes-notebook-rail-width';
const NOTEBOOK_RAIL_MIN_WIDTH = 220;
const NOTEBOOK_RAIL_MAX_WIDTH = 420;

export function workspaceShell() {
  return {
    sidebarOpen: false,
    notebookDrawerOpen: false,
    notebookRailWidth: 256,
    notebookRailResizing: false,
    notebookRailMoveHandler: null,
    notebookRailEndHandler: null,
    mobileSwipeStart: null,
    mobileLevel: 'books',
    notebooks: [],
    activeCollection: 'home',
    activeNotebookId: null,
    dropTargetNotebookId: null,
    notebookDialogOpen: false,
    notebookDialogMode: 'create',
    notebookDialogNotebookId: null,
    openNotebookMenuId: null,
    notebookName: '',
    notebookColor: '#2563eb',
    notebookIcon: 'book-open',
    notebookColors: [
      { value: '#2563eb', label: 'Blau' },
      { value: '#7c3aed', label: 'Violett' },
      { value: '#db2777', label: 'Pink' },
      { value: '#dc2626', label: 'Rot' },
      { value: '#ea580c', label: 'Orange' },
      { value: '#ca8a04', label: 'Gelb' },
      { value: '#16a34a', label: 'Grün' },
      { value: '#0891b2', label: 'Türkis' },
      { value: '#475569', label: 'Schiefer' },
      { value: '#78716c', label: 'Stein' },
    ],
    notebookError: '',
    notebookSaving: false,

    async init() {
      this.restoreNotebookRailWidth();
      await this.refreshNotebooks();
    },

    restoreNotebookRailWidth() {
      try {
        const stored = Number(window.localStorage.getItem(NOTEBOOK_RAIL_WIDTH_KEY));
        if (Number.isFinite(stored) && stored >= NOTEBOOK_RAIL_MIN_WIDTH) {
          this.notebookRailWidth = Math.min(NOTEBOOK_RAIL_MAX_WIDTH, stored);
        }
      } catch {
        /* Browser-Speicher ist optional. */
      }
    },

    notebookRailStyle() {
      return `width: ${this.notebookRailWidth}px;`;
    },

    startNotebookResize(event) {
      if (!window.matchMedia('(min-width: 1280px)').matches) {
        return;
      }
      event.preventDefault();
      const startX = event.clientX;
      const startWidth = this.notebookRailWidth;
      this.notebookRailResizing = true;
      document.body.classList.add('is-resizing-sidebar');

      this.notebookRailMoveHandler = (moveEvent) => {
        this.notebookRailWidth = Math.min(
          NOTEBOOK_RAIL_MAX_WIDTH,
          Math.max(NOTEBOOK_RAIL_MIN_WIDTH, startWidth + moveEvent.clientX - startX),
        );
      };
      this.notebookRailEndHandler = () => this.stopNotebookResize();
      window.addEventListener('pointermove', this.notebookRailMoveHandler);
      window.addEventListener('pointerup', this.notebookRailEndHandler, { once: true });
      window.addEventListener('pointercancel', this.notebookRailEndHandler, { once: true });
    },

    stopNotebookResize() {
      if (this.notebookRailMoveHandler) {
        window.removeEventListener('pointermove', this.notebookRailMoveHandler);
      }
      if (this.notebookRailEndHandler) {
        window.removeEventListener('pointerup', this.notebookRailEndHandler);
        window.removeEventListener('pointercancel', this.notebookRailEndHandler);
      }
      this.notebookRailMoveHandler = null;
      this.notebookRailEndHandler = null;
      this.notebookRailResizing = false;
      document.body.classList.remove('is-resizing-sidebar');
      try {
        window.localStorage.setItem(NOTEBOOK_RAIL_WIDTH_KEY, String(this.notebookRailWidth));
      } catch {
        /* Browser-Speicher ist optional. */
      }
    },

    resizeNotebookRailBy(delta) {
      this.notebookRailWidth = Math.min(
        NOTEBOOK_RAIL_MAX_WIDTH,
        Math.max(NOTEBOOK_RAIL_MIN_WIDTH, this.notebookRailWidth + delta),
      );
      try {
        window.localStorage.setItem(NOTEBOOK_RAIL_WIDTH_KEY, String(this.notebookRailWidth));
      } catch {
        /* Browser-Speicher ist optional. */
      }
    },

    async refreshNotebooks() {
      try {
        const data = await apiFetch('/api/notebooks');
        this.notebooks = data.notebooks || [];
        await cacheNotebooks(this.notebooks);
      } catch (error) {
        console.warn('Notizbuecher konnten nicht geladen werden.', error);
        this.notebooks = await getCachedNotebooks();
      }
    },

    collectionLabel() {
      if (this.activeCollection === 'favorites') return 'Favoriten';
      if (this.activeCollection === 'unassigned') return 'Nicht zugewiesen';
      if (this.activeCollection === 'shared') return 'Geteilt';
      if (this.activeCollection === 'trash') return 'Papierkorb';
      if (this.activeCollection === 'notebook') {
        return this.notebooks.find((notebook) => Number(notebook.id) === Number(this.activeNotebookId))?.name || 'Notizbuch';
      }
      return 'Alle Seiten';
    },

    activeNotebook() {
      return this.notebooks.find(
        (notebook) => Number(notebook.id) === Number(this.activeNotebookId),
      ) || null;
    },

    activeNotebookIconIs(icon) {
      return (this.activeNotebook()?.icon || 'book-open') === icon;
    },

    activeNotebookIconStyle() {
      return `color: ${this.activeNotebook()?.color || 'var(--color-text-muted)'};`;
    },

    selectCollection(collection, notebookId = null) {
      this.activeCollection = collection;
      this.activeNotebookId = notebookId;
      this.notebookDrawerOpen = false;
      this.mobileLevel = 'pages';
      this.openNotebookMenuId = null;
      window.dispatchEvent(new CustomEvent('collection-changed', {
        detail: { collection, notebookId },
      }));
    },

    navigateHome() {
      this.selectCollection('home');
      window.dispatchEvent(new Event('navigate-home'));
    },

    setDropTargetNotebook(notebookId) {
      this.dropTargetNotebookId = notebookId === null ? 'unassigned' : String(notebookId);
    },

    clearDropTarget() {
      this.dropTargetNotebookId = null;
    },

    draggedPageIds(event) {
      let pageIds = [];
      try {
        const payload = event.dataTransfer?.getData('application/x-nasmut-pages');
        pageIds = payload ? JSON.parse(payload) : [];
      } catch {
        pageIds = [];
      }
      if (!Array.isArray(pageIds) || pageIds.length === 0) {
        const pageId = Number(event.dataTransfer?.getData('text/plain') || 0);
        pageIds = pageId ? [pageId] : [];
      }

      return pageIds.map(Number).filter((pageId) => pageId > 0);
    },

    dropPageOnNotebook(notebookId, event) {
      const pageIds = this.draggedPageIds(event);
      if (pageIds.length === 0) {
        this.clearDropTarget();
        return;
      }

      this.clearDropTarget();
      window.dispatchEvent(new CustomEvent('page-drop-move', {
        detail: { pageIds, notebookId },
      }));
    },

    setTrashDropTarget() {
      this.dropTargetNotebookId = 'trash';
    },

    isTrashDropTarget() {
      return this.dropTargetNotebookId === 'trash';
    },

    dropPagesOnTrash(event) {
      const pageIds = this.draggedPageIds(event);
      this.clearDropTarget();
      if (pageIds.length > 0) {
        window.dispatchEvent(new CustomEvent('page-drop-trash', {
          detail: { pageIds },
        }));
      }
    },

    openNavigation() {
      this.sidebarOpen = true;
      this.mobileLevel = 'books';
    },

    openNavigationToPages() {
      this.sidebarOpen = true;
      this.mobileLevel = 'pages';
    },

    startMobileSwipe(event) {
      if (!window.matchMedia('(max-width: 767px)').matches
        || !window.location.pathname.startsWith('/app/page/')
        || event.touches?.length !== 1) {
        this.mobileSwipeStart = null;
        return;
      }
      const target = event.target instanceof Element ? event.target : null;
      if (target?.closest('a, button, input, textarea, select, [contenteditable="true"], .ProseMirror, img')) {
        this.mobileSwipeStart = null;
        return;
      }
      const touch = event.touches[0];
      this.mobileSwipeStart = { x: touch.clientX, y: touch.clientY };
    },

    endMobileSwipe(event) {
      if (!this.mobileSwipeStart || event.changedTouches?.length !== 1) {
        this.mobileSwipeStart = null;
        return;
      }
      const touch = event.changedTouches[0];
      const deltaX = touch.clientX - this.mobileSwipeStart.x;
      const deltaY = touch.clientY - this.mobileSwipeStart.y;
      this.mobileSwipeStart = null;
      if (deltaX > -80 || Math.abs(deltaY) > Math.abs(deltaX) * 0.6) {
        return;
      }

      if (!this.sidebarOpen) {
        this.openNavigationToPages();
      } else if (this.mobileLevel === 'pages') {
        this.mobileLevel = 'books';
      }
    },

    openNotebookDialog() {
      if (!navigator.onLine) {
        window.alert('Notizbuecher koennen nur online angelegt werden.');
        return;
      }
      this.notebookDialogMode = 'create';
      this.notebookDialogNotebookId = null;
      this.notebookName = '';
      this.notebookColor = '#2563eb';
      this.notebookIcon = 'book-open';
      this.notebookError = '';
      this.notebookDialogOpen = true;
      this.$nextTick(() => this.$refs.notebookName?.focus());
    },

    openNotebookMenu(notebookId) {
      this.openNotebookMenuId = Number(this.openNotebookMenuId) === Number(notebookId)
        ? null
        : Number(notebookId);
    },

    closeNotebookMenu() {
      this.openNotebookMenuId = null;
    },

    isNotebookMenuOpen(notebookId) {
      return this.openNotebookMenuId === Number(notebookId);
    },

    isNotebookDropTarget(notebookId) {
      return this.dropTargetNotebookId === String(notebookId);
    },

    isUnassignedDropTarget() {
      return this.dropTargetNotebookId === 'unassigned';
    },

    isActiveNotebook(notebookId) {
      return this.activeCollection === 'notebook'
        && Number(this.activeNotebookId) === Number(notebookId);
    },

    openRenameNotebookDialog(notebook) {
      if (!navigator.onLine) {
        return;
      }
      this.closeNotebookMenu();
      this.notebookDialogMode = 'rename';
      this.notebookDialogNotebookId = Number(notebook.id);
      this.notebookName = notebook.name;
      this.notebookColor = notebook.color || '#2563eb';
      this.notebookIcon = notebook.icon || 'book-open';
      this.notebookError = '';
      this.notebookDialogOpen = true;
      this.$nextTick(() => this.$refs.notebookName?.focus());
    },

    closeNotebookDialog() {
      if (!this.notebookSaving) {
        this.notebookDialogOpen = false;
      }
    },

    async saveNotebook() {
      const name = this.notebookName.trim();
      if (!name) {
        this.notebookError = 'Bitte gib einen Namen ein.';
        return;
      }

      this.notebookSaving = true;
      this.notebookError = '';
      try {
        const isRename = this.notebookDialogMode === 'rename';
        const notebook = isRename
          ? await apiFetch(`/api/notebooks/${this.notebookDialogNotebookId}`, {
            method: 'PATCH', body: JSON.stringify({
              name, color: this.notebookColor, icon: this.notebookIcon,
            }),
          })
          : await apiFetch('/api/notebooks', {
            method: 'POST', body: JSON.stringify({
              name, color: this.notebookColor, icon: this.notebookIcon,
            }),
          });
        await this.refreshNotebooks();
        this.notebookDialogOpen = false;
        if (!isRename) {
          this.selectCollection('notebook', notebook.id);
        }
      } catch (error) {
        this.notebookError = error.message || (this.notebookDialogMode === 'rename'
          ? 'Das Notizbuch konnte nicht umbenannt werden.'
          : 'Das Notizbuch konnte nicht angelegt werden.');
      } finally {
        this.notebookSaving = false;
      }
    },

    selectNotebookColor(color) {
      this.notebookColor = color;
    },

    selectNotebookIcon(icon) {
      this.notebookIcon = icon;
    },

    isNotebookColorSelected(color) {
      return this.notebookColor === color;
    },

    isNotebookIconSelected(icon) {
      return this.notebookIcon === icon;
    },

    notebookColorStyle(color) {
      return `background-color: ${color};`;
    },

    notebookIconStyle(notebook) {
      return `color: ${notebook.color || '#2563eb'};`;
    },

    selectedNotebookIconStyle() {
      return `color: ${this.notebookColor};`;
    },

    async deleteNotebook(notebook) {
      if (!navigator.onLine) {
        return;
      }
      if (!window.confirm(`"${notebook.name}" loeschen? Die enthaltenen Seiten werden Nicht zugewiesen.`)) return;
      this.closeNotebookMenu();
      await apiFetch(`/api/notebooks/${notebook.id}`, { method: 'DELETE' });
      if (this.activeCollection === 'notebook' && Number(this.activeNotebookId) === Number(notebook.id)) {
        this.selectCollection('unassigned');
      }
      await this.refreshNotebooks();
      window.dispatchEvent(new Event('pages-changed'));
    },

    async logout() {
      try {
        await clearOfflineData({ unregisterWorker: true });
      } catch {
        /* Continue logout when local storage is unavailable. */
      }
      await apiFetch('/auth/logout', { method: 'POST' });
      window.location.href = '/';
    },

    /**
     * Mobil deckt die Seitenleiste den ganzen Bildschirm ab - der Klick auf die
     * Überlagerung greift dort nicht mehr. Geschlossen wird deshalb über den
     * Schalter im Kopf der Leiste und nach jeder Navigation (Event aus
     * `pageList`, das bis hier hochblubbert).
     */
    closeSidebar() {
      this.sidebarOpen = false;
      this.notebookDrawerOpen = false;
    },
  };
}
