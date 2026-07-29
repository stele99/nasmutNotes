import { apiFetch } from './api.js';
import {
  cacheNotebooks,
  clearOfflineData,
  getCachedNotebooks,
} from './offline/runtime.js';

const NOTEBOOK_RAIL_WIDTH_KEY = 'nasmut-notes-notebook-rail-width';
const NOTEBOOK_RAIL_MIN_WIDTH = 220;
const NOTEBOOK_RAIL_MAX_WIDTH = 420;

/* Mobil bilden Notizbücher, Seitenliste und Seiteninhalt drei aufeinander
   folgende Ebenen, von denen immer nur die oberste sichtbar ist. Ab `md` liegen
   dieselben Bereiche nebeneinander, deshalb gilt der Stapel nur darunter. */
const MOBILE_BREAKPOINT = '(max-width: 767px)';
const MOBILE_VIEWS = ['books', 'pages', 'content'];
/** Strecke, ab der eine Wischgeste als Ebenenwechsel zählt. */
const SWIPE_MIN_DISTANCE = 60;
/** Ab hier steht fest, ob waagerecht oder senkrecht gewischt wird. */
const SWIPE_LOCK_DISTANCE = 12;
/**
 * Aus diesem Randstreifen heraus wischt der Nutzer die native Zurück-Geste,
 * nicht unsere eigene - das lässt sich von der Seite aus nicht unterdrücken,
 * iOS erkennt sie unterhalb der eigenen Touch-Events. Ohne diesen Ausschluss
 * würde derselbe Fingerzug zweimal "zurück" auslösen: einmal nativ, einmal
 * über unsere eigene Erkennung (siehe unten, beide rufen letztlich denselben
 * `history.back()` auf).
 */
const SWIPE_EDGE_ZONE = 24;

/** Die drei Ebenen selbst liegen fest im Ansichtsfenster - Dialoge ebenso. */
const MOBILE_PANEL_SELECTOR = '.page-sidebar, .notebook-drawer';

/**
 * Zwei Fälle behalten ihre Wischgeste: Dialoge und Vollbild-Betrachter, die als
 * eigene Ebene über dem Stapel liegen, sowie waagerecht scrollbare Bereiche wie
 * Kapitelreiter, Tabellen und Codeblöcke - deren Inhalt ließe sich sonst mobil
 * nicht mehr verschieben.
 */
function blocksSwipe(element, root) {
  for (let node = element; node && node !== root; node = node.parentElement) {
    const style = window.getComputedStyle(node);
    if (style.position === 'fixed' && !node.matches(MOBILE_PANEL_SELECTOR)) {
      return true;
    }
    if (node.scrollWidth - node.clientWidth > 4
      && (style.overflowX === 'auto' || style.overflowX === 'scroll')) {
      return true;
    }
  }

  return false;
}

/**
 * Länge der Sitzungs-Historie beim Laden dieser Seite - der Referenzwert, um
 * zu erkennen, ob in dieser Ladung schon eigene Verlaufseinträge existieren.
 * Ohne diese Prüfung würde `history.back()` bei einem Direktaufruf (z. B.
 * geteilter Link, frisch installierte App) die App verlassen, weil es dort
 * noch nichts zum Zurückgehen gibt.
 */
const initialHistoryLength = window.history.length;

/**
 * Notizbuch- und Seitenauswahl-Ebene bekommen eigene, URL-neutrale
 * Verlaufseinträge (`history.state` ändert sich, die sichtbare Adresse
 * bleibt gleich). Sowohl die native Zurück-Geste als auch unsere eigene
 * Wisch-Erkennung rufen für den Ebenenwechsel letztlich `history.back()`
 * bzw. `history.forward()` auf (siehe `goBack()`/`goForward()` unten) - beide
 * Auslöser navigieren dadurch durch dieselbe Historie und landen zuverlässig
 * am selben Ziel, statt sich gegenseitig zu stören.
 *
 * Der Listener muss vor `pageList`s eigenem popstate-Listener laufen, damit
 * er unsere eigenen Einträge abfangen kann, bevor jener sie fälschlich als
 * echten Seitenwechsel liest - deshalb Registrierung auf Modulebene statt in
 * `init()` (Module werden vor `Alpine.start()` ausgewertet).
 */
window.addEventListener('popstate', (event) => {
  const state = event.state;
  if (!state?.nasmutView) {
    return;
  }

  event.stopImmediatePropagation();
  const shell = window.__workspaceShellOwner__;
  if (!shell) {
    return;
  }

  shell.notebookDrawerOpen = false;
  shell.mobileView = state.nasmutView;
  if (state.nasmutView === 'pages') {
    shell.activeCollection = state.collection;
    shell.activeNotebookId = state.notebookId ?? null;
    window.dispatchEvent(new CustomEvent('collection-changed', {
      detail: { collection: state.collection, notebookId: state.notebookId ?? null },
    }));
  }
});

export function workspaceShell() {
  return {
    notebookDrawerOpen: false,
    notebookRailWidth: 256,
    notebookRailResizing: false,
    notebookRailMoveHandler: null,
    notebookRailEndHandler: null,
    isMobile: false,
    mobileView: 'content',
    mobileSwipe: null,
    notebooks: [],
    activeCollection: 'home',
    activeNotebookId: null,
    dropTargetNotebookId: null,
    notebookDialogOpen: false,
    infoDialogOpen: false,
    infoAcknowledgementRequired: false,
    infoDialogBusy: false,
    infoDialogError: '',
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
      // Für den popstate-Listener auf Modulebene - der steht bereits vor
      // jedem Alpine-Init fest, braucht aber Zugriff auf die gerade
      // entstehende Instanz.
      window.__workspaceShellOwner__ = this;
      this.restoreNotebookRailWidth();
      this.watchViewport();
      await this.refreshNotebooks();
      await this.initializeInfoDialog();
    },

    async initializeInfoDialog() {
      try {
        const session = await apiFetch('/api/session');
        if (session?.user?.info_acknowledged !== true) {
          this.infoAcknowledgementRequired = true;
          this.infoDialogOpen = true;
        }
      } catch {
        // Offline bleibt der Dialog über das Info-Symbol erreichbar.
      }
    },

    openInfoDialog() {
      this.infoAcknowledgementRequired = false;
      this.infoDialogError = '';
      this.infoDialogOpen = true;
    },

    dismissInfoDialog() {
      if (!this.infoAcknowledgementRequired && !this.infoDialogBusy) {
        this.infoDialogOpen = false;
        this.infoDialogError = '';
      }
    },

    async closeInfoDialog() {
      if (this.infoDialogBusy) {
        return;
      }
      if (!this.infoAcknowledgementRequired) {
        this.dismissInfoDialog();
        return;
      }

      this.infoDialogBusy = true;
      this.infoDialogError = '';
      try {
        await apiFetch('/api/profile', {
          method: 'PATCH',
          body: JSON.stringify({ info_acknowledged: true }),
        });
        this.infoAcknowledgementRequired = false;
        this.infoDialogOpen = false;
      } catch (error) {
        this.infoDialogError = error.message || 'Die Bestätigung konnte nicht gespeichert werden.';
      } finally {
        this.infoDialogBusy = false;
      }
    },

    watchViewport() {
      const query = window.matchMedia(MOBILE_BREAKPOINT);
      this.applyViewport(query.matches);
      query.addEventListener('change', (event) => this.applyViewport(event.matches));
    },

    /**
     * Einstieg ist mobil immer der Inhalt - auf `/app` also die Übersicht. Die
     * Notizbücher erreicht man von dort über den Hamburger-Schalter.
     */
    applyViewport(isMobile) {
      this.isMobile = isMobile;
      this.notebookDrawerOpen = false;
      this.mobileView = 'content';
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
      this.pushMobileState('pages', { collection, notebookId });
      this.activeCollection = collection;
      this.activeNotebookId = notebookId;
      this.notebookDrawerOpen = false;
      this.mobileView = 'pages';
      this.openNotebookMenuId = null;
      window.dispatchEvent(new CustomEvent('collection-changed', {
        detail: { collection, notebookId },
      }));
    },

    /**
     * Eigener Verlaufseintrag für die Notizbuch- bzw. Seitenauswahl-Ebene -
     * nur mobil relevant, die Ebenen existieren am Desktop nicht. Die
     * sichtbare Adresse bleibt unverändert, nur `history.state` trägt die
     * Ebene; der Listener oben liest ihn beim Zurücknavigieren wieder aus.
     */
    pushMobileState(view, extra = {}) {
      if (!this.isMobile) {
        return;
      }
      window.history.pushState({ nasmutView: view, ...extra }, '', window.location.href);
    },

    /**
     * Der einzige Weg aus der Notizbuchliste zurück zur Übersicht - ausgelöst
     * über das nasmutNotes-Logo, nicht über einen eigenen Listeneintrag. Läuft
     * bewusst nicht über `selectCollection()`: Der eigentliche Verlaufseintrag
     * entsteht gleich darauf über die echte Navigation zu `/app` (Event
     * `navigate-home`) - ein zusätzlicher hier würde pro Klick zwei Einträge
     * erzeugen, von denen der zweite sofort wieder überschrieben wird.
     */
    navigateHome() {
      this.activeCollection = 'home';
      this.activeNotebookId = null;
      this.notebookDrawerOpen = false;
      this.mobileView = 'content';
      this.openNotebookMenuId = null;
      window.dispatchEvent(new CustomEvent('collection-changed', {
        detail: { collection: 'home', notebookId: null },
      }));
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

    isMobileView(view) {
      return this.isMobile && this.mobileView === view;
    },

    /**
     * Die Seitenliste bleibt auch auf der Notizbuchebene eingeblendet: Sie liegt
     * unter dem Notizbuch-Panel und kommt beim Zurückwischen darunter hervor.
     */
    isPageSidebarVisible() {
      return this.isMobile && this.mobileView !== 'content';
    },

    isNotebookDrawerVisible() {
      return this.notebookDrawerOpen || this.isMobileView('books');
    },

    showBooks() {
      if (this.mobileView !== 'books') {
        this.pushMobileState('books');
      }
      this.notebookDrawerOpen = false;
      this.mobileView = 'books';
    },

    showPages() {
      this.notebookDrawerOpen = false;
      this.mobileView = 'pages';
    },

    showContent() {
      this.notebookDrawerOpen = false;
      this.mobileView = 'content';
    },

    /**
     * Eine Ebene zurück: Inhalt → Seitenliste → Notizbücher. Existiert für
     * diese Ladung schon ein eigener Verlaufseintrag, läuft das über die
     * echte Browser-Historie - dann landen Wischgeste, Zurück-Button und
     * native Geste garantiert am selben Ziel (siehe popstate-Listener oben).
     * Ohne eigenen Eintrag (z. B. Direktaufruf einer Seite über einen Link)
     * bleibt der rein lokale Ebenenwechsel als Rückfall.
     */
    goBack() {
      this.notebookDrawerOpen = false;
      if (this.mobileView === 'books') {
        return;
      }
      if (window.history.length > initialHistoryLength) {
        window.history.back();
        return;
      }
      const index = MOBILE_VIEWS.indexOf(this.mobileView);
      this.mobileView = MOBILE_VIEWS[Math.max(0, index - 1)];
    },

    /** Eine Ebene vor: Notizbücher → Seitenliste → Inhalt. Siehe `goBack()`. */
    goForward() {
      this.notebookDrawerOpen = false;
      if (this.mobileView === 'content') {
        return;
      }
      if (window.history.length > initialHistoryLength) {
        window.history.forward();
        return;
      }
      const index = MOBILE_VIEWS.indexOf(this.mobileView);
      this.mobileView = MOBILE_VIEWS[Math.min(MOBILE_VIEWS.length - 1, index + 1)];
    },

    startMobileSwipe(event) {
      this.mobileSwipe = null;
      if (!this.isMobile || event.touches?.length !== 1) {
        return;
      }

      const touch = event.touches[0];
      if (touch.clientX <= SWIPE_EDGE_ZONE) {
        return;
      }

      const target = event.target instanceof Element ? event.target : null;
      if (target && blocksSwipe(target, this.$el)) {
        return;
      }

      // Bei markiertem Text zieht der Nutzer an den Auswahlgriffen.
      const selection = window.getSelection();
      if (selection && !selection.isCollapsed) {
        return;
      }

      this.mobileSwipe = { x: touch.clientX, y: touch.clientY, horizontal: false };
    },

    /**
     * Ohne preventDefault() erkennt der Browser dieselbe Fingerbewegung parallel
     * als eigene Zurück-Geste. Der dadurch ausgelöste `popstate` reißt die App
     * zur zuvor besuchten Seite, noch während die eigene Animation läuft. Erst
     * wird die Richtung festgestellt, damit senkrechtes Scrollen frei bleibt.
     */
    moveMobileSwipe(event) {
      if (!this.mobileSwipe || event.touches?.length !== 1) {
        return;
      }

      const touch = event.touches[0];
      const deltaX = touch.clientX - this.mobileSwipe.x;
      const deltaY = touch.clientY - this.mobileSwipe.y;
      if (!this.mobileSwipe.horizontal) {
        if (Math.abs(deltaX) < SWIPE_LOCK_DISTANCE && Math.abs(deltaY) < SWIPE_LOCK_DISTANCE) {
          return;
        }
        if (Math.abs(deltaY) >= Math.abs(deltaX)) {
          this.mobileSwipe = null;
          return;
        }
        this.mobileSwipe.horizontal = true;
      }

      if (event.cancelable) {
        event.preventDefault();
      }
    },

    endMobileSwipe(event) {
      const swipe = this.mobileSwipe;
      this.mobileSwipe = null;
      if (!swipe?.horizontal || event.changedTouches?.length !== 1) {
        return;
      }

      const deltaX = event.changedTouches[0].clientX - swipe.x;
      if (Math.abs(deltaX) < SWIPE_MIN_DISTANCE) {
        return;
      }

      if (deltaX > 0) {
        this.goBack();
      } else {
        this.goForward();
      }
    },

    cancelMobileSwipe() {
      this.mobileSwipe = null;
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
  };
}
