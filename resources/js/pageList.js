import { apiFetch } from './api.js';
import { markNewPageForTitleEdit } from './newPageTitle.js';
import {
  cacheDocument,
  cacheNotebooks,
  cachePageList,
  clearOfflineData,
  countUnsyncedChanges,
  getCachedPage,
  getCachedPages,
  prefetchSelected,
  readCachedDocument,
  syncOutbox,
} from './offline/runtime.js';

function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;');
}

function offlinePageHtml(page) {
  const id = Number(page.id);
  const title = escapeHtml(page.title || 'Seite');
  const isShared = page.is_shared ? '1' : '0';
  const canEdit = page.is_shared
    ? (page.can_edit === true || page.can_edit === 1 || page.share_permission === 'write' ? '1' : '0')
    : '1';
  const permission = escapeHtml(page.share_permission || '');
  const isNote = page.type === 'note';
  const notebookLabel = escapeHtml(page.is_shared ? 'Geteilt' : (page.notebook_name || 'Nicht zugewiesen'));
  const notebookIcon = page.is_shared ? 'share-2' : (page.notebook_icon || 'book-open');
  const notebookColor = page.is_shared ? 'var(--color-text-muted)' : (page.notebook_color || 'var(--color-text-muted)');

  const body = isNote
    ? `<div class="note-page page-canvas mx-auto px-6 pb-16 pt-20 sm:px-10 md:pt-14" x-data="noteEditorPage" data-page-id="${id}" data-page-title="${title}" data-page-can-edit="${canEdit}" data-page-is-shared="${isShared}">
        <div class="note-sticky-header page-toolbar flex items-center gap-2">
          <span style="color: ${notebookColor};" x-icon="${notebookIcon}"></span>
          ${notebookLabel}
          <span class="truncate" x-text="pageTitle"></span>
        </div>
        <div class="pt-10">
          <h1 class="text-4xl font-semibold tracking-tight" x-text="pageTitle"></h1>
          <p class="mt-2 text-sm" style="color: var(--color-text-muted);" x-text="statusLabel()"></p>
          <div x-show="canEditPage" class="note-sticky-toolbar editor-toolbar mb-5 mt-6 flex flex-wrap items-center gap-1 border-b pb-4" style="border-color: var(--color-border);" x-ref="toolbar">
            <button type="button" data-editor-command="bold" @click.prevent="toggleBold" class="toolbar-button" title="Fett" x-icon="bold"></button>
            <button type="button" data-editor-command="italic" @click.prevent="toggleItalic" class="toolbar-button" title="Kursiv" x-icon="italic"></button>
            <button type="button" data-editor-command="code" @click.prevent="toggleCode" class="toolbar-button" title="Code (inline)" x-icon="code"></button>
            <button type="button" data-editor-command="codeBlock" @click.prevent="toggleCodeBlock" class="toolbar-button" title="Codeblock" x-icon="square-code"></button>
            <button type="button" data-editor-command="table" @click.prevent="insertTable" class="toolbar-button" title="Tabelle" x-icon="table"></button>
          </div>
          <div class="prose-editor" x-ref="editor"></div>
        </div>
      </div>`
    : `<div class="page-canvas mx-auto px-6 pb-16 pt-20 sm:px-10 md:pt-14" x-data="taskBoard" data-page-id="${id}" data-page-title="${title}" data-page-can-edit="${canEdit}">
        <div class="page-toolbar flex items-center gap-2">
          <span style="color: ${notebookColor};" x-icon="${notebookIcon}"></span>
          ${notebookLabel}
          <span class="truncate" x-text="pageTitle"></span>
        </div>
        <div class="pt-10">
          <h1 class="text-4xl font-semibold" x-text="pageTitle"></h1>
          <p class="mt-4 text-sm" style="color: var(--color-text-muted);">Offline-Ansicht der Aufgabenliste.</p>
          <template x-for="category in categories" :key="category.id">
            <div class="mt-8">
              <h2 class="text-lg font-semibold" x-text="category.name"></h2>
              <template x-for="task in category.tasks || []" :key="task.id">
                <div class="mt-2 rounded-md border px-3 py-2 text-sm" style="border-color: var(--color-border);" x-text="task.title"></div>
              </template>
            </div>
          </template>
        </div>
      </div>`;

  return `<!DOCTYPE html><html><head><title>${title}</title></head><body>
<main class="workspace-main min-w-0 flex-1 h-dvh overflow-y-auto" x-data="pageShare"
  data-page-id="${id}" data-page-title="${title}" data-page-is-shared="${isShared}"
  data-page-permission="${permission}" data-page-can-edit="${canEdit}">
  <button @click="goBack()" class="sidebar-toggle fixed left-4 top-4 z-[100] flex border shadow-sm md:hidden" style="border-color: var(--color-border); background: var(--color-bg);" aria-label="Zurück zur Seitenauswahl" x-icon="chevron-left"></button>
  ${body}
</main></body></html>`;
}

const RECENT_PAGE_SIZE = 25;

export function pageList() {
  return {
    pages: [],
    // Für die Übersicht sortierte Kopie: Favoriten zuerst, dann nach
    // Änderungsdatum. Die Seitenleiste nutzt weiterhin `pages`.
    orderedPages: [],
    recentLimit: RECENT_PAGE_SIZE,
    recentObserver: null,
    loading: true,
    sort: 'updated',
    typeFilter: null,
    currentPageId: window.__CURRENT_PAGE_ID__ || null,
    searchQuery: '',
    searchResults: [],
    searchLoading: false,
    navigating: false,
    activeCollection: 'home',
    activeNotebookId: null,
    selectedPageIds: [],
    selectionAnchorId: null,
    trashRetentionDays: 90,
    trashError: '',

    async init() {
      const currentPageRoot = document.querySelector('main.workspace-main');
      if (!this.currentPageId && currentPageRoot?.dataset.pageId) {
        this.currentPageId = Number(currentPageRoot.dataset.pageId);
      }
      await this.refresh();
      this.$nextTick(() => this.observeRecentSentinel());

      if (!window.__workspaceNavigationBound__) {
        window.__workspaceNavigationBound__ = true;
        window.__workspaceNavigationOwner__ = this;
        window.addEventListener('popstate', () => {
          const owner = window.__workspaceNavigationOwner__;
          if (!owner) {
            window.location.reload();
            return;
          }

          const match = window.location.pathname.match(/^\/app\/page\/(\d+)$/);
          const page = match
            ? owner.pages.find((item) => Number(item.id) === Number(match[1])) || null
            : null;
          owner.navigateTo(window.location.pathname, page, false);
        });
        window.addEventListener('online', () => {
          void syncOutbox();
          void this.refresh();
        });
      }
    },

    async setCollection(event) {
      this.activeCollection = event.detail.collection;
      this.activeNotebookId = event.detail.notebookId;
      this.clearSearch();
      this.clearPageSelection();
      await this.refresh();
    },

    beginPageDrag(page, event) {
      if (page.is_shared) {
        event.preventDefault();
        return;
      }
      const pageIds = this.isPageSelected(page.id)
        ? [...this.selectedPageIds]
        : [Number(page.id)];
      if (!this.isPageSelected(page.id)) {
        this.selectedPageIds = pageIds;
        this.selectionAnchorId = Number(page.id);
      }
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', String(page.id));
      event.dataTransfer.setData('application/x-nasmut-pages', JSON.stringify(pageIds));
    },

    endPageDrag() {
      window.dispatchEvent(new Event('page-drag-end'));
    },

    async movePagesByIds(pageIds, notebookId) {
      const ownedIds = pageIds
        .map(Number)
        .filter((pageId) => this.pages.some(
          (page) => Number(page.id) === pageId && !page.is_shared,
        ));
      if (ownedIds.length === 0 || !navigator.onLine) {
        return;
      }
      const target = notebookId === '' || notebookId === null ? null : Number(notebookId);
      await apiFetch('/api/pages/move', {
        method: 'POST',
        body: JSON.stringify({ page_ids: ownedIds, notebook_id: target }),
      });
      this.clearPageSelection();
      this.notifyPagesChanged();
    },

    async trashPagesByIds(pageIds) {
      const ownedIds = pageIds
        .map(Number)
        .filter((pageId) => this.pages.some(
          (page) => Number(page.id) === pageId && !page.is_shared,
        ));
      if (ownedIds.length === 0 || !navigator.onLine || !window.confirm(
        `${ownedIds.length} Seite(n) in den Papierkorb verschieben?`,
      )) return;

      const removedCurrent = ownedIds.includes(Number(this.currentPageId));
      await apiFetch('/api/pages/trash', {
        method: 'POST',
        body: JSON.stringify({ page_ids: ownedIds }),
      });
      this.clearPageSelection();
      this.notifyPagesChanged();
      if (removedCurrent) {
        await this.navigateTo('/app');
      }
    },

    trashSelectedPages() {
      void this.trashPagesByIds([...this.selectedPageIds]);
    },

    handlePageClick(page, event) {
      if (page.is_shared || (!event.ctrlKey && !event.metaKey && !event.shiftKey)) {
        void this.navigate(page);
        return;
      }

      const pageId = Number(page.id);
      if (event.shiftKey && this.selectionAnchorId !== null) {
        const selectable = this.filteredPages().filter((item) => !item.is_shared);
        const anchorIndex = selectable.findIndex(
          (item) => Number(item.id) === Number(this.selectionAnchorId),
        );
        const pageIndex = selectable.findIndex((item) => Number(item.id) === pageId);
        if (anchorIndex !== -1 && pageIndex !== -1) {
          const start = Math.min(anchorIndex, pageIndex);
          const end = Math.max(anchorIndex, pageIndex);
          const rangeIds = selectable.slice(start, end + 1).map((item) => Number(item.id));
          this.selectedPageIds = event.ctrlKey || event.metaKey
            ? [...new Set([...this.selectedPageIds, ...rangeIds])]
            : rangeIds;
          return;
        }
      }

      this.selectedPageIds = this.isPageSelected(pageId)
        ? this.selectedPageIds.filter((id) => id !== pageId)
        : [...this.selectedPageIds, pageId];
      this.selectionAnchorId = pageId;
    },

    isPageSelected(pageId) {
      return this.selectedPageIds.includes(Number(pageId));
    },

    selectedPageCount() {
      return this.selectedPageIds.length;
    },

    clearPageSelection() {
      this.selectedPageIds = [];
      this.selectionAnchorId = null;
    },

    async refresh() {
      this.loading = true;
      this.trashError = '';
      if (this.activeCollection === 'trash' && !navigator.onLine) {
        this.setPages([]);
        this.trashError = 'Der Papierkorb ist offline nicht verfügbar.';
        this.loading = false;
        return;
      }
      try {
        const qs = new URLSearchParams({ sort: this.sort });
        if (this.activeCollection === 'notebook' && this.activeNotebookId) {
          qs.set('notebook_id', this.activeNotebookId);
        } else if (this.activeCollection === 'unassigned' || this.activeCollection === 'shared') {
          qs.set('collection', this.activeCollection);
        } else if (this.activeCollection === 'trash') {
          qs.set('trashed', '1');
        }
        if (this.typeFilter) {
          qs.set('type', this.typeFilter);
        }
        const data = await apiFetch(`/api/pages?${qs.toString()}`);
        this.setPages(data.pages);
        this.trashRetentionDays = Number(data.trash_retention_days || 90);
        if (this.activeCollection !== 'trash') {
          await cachePageList(data.pages);
        }
        // Refresh notebook navigation together with the page list. A missing
        // notebook endpoint must not turn an otherwise usable page refresh
        // into an offline fallback.
        try {
          const notebooks = await apiFetch('/api/notebooks');
          await cacheNotebooks(notebooks?.notebooks);
        } catch {
          /* Keep an existing notebook cache. */
        }
        if (navigator.onLine && this.activeCollection !== 'trash') {
          void prefetchSelected();
        }
      } catch (error) {
        // Auch Serverfehler landen hier - der gecachte Stand ist dann zwar
        // veraltet, aber besser als eine leere Liste.
        console.warn('Seitenliste konnte nicht geladen werden, nutze lokalen Stand.', error);
        if (this.activeCollection === 'trash') {
          this.setPages([]);
          this.trashError = error.message || 'Der Papierkorb konnte nicht geladen werden.';
        } else {
          this.setPages(await getCachedPages());
        }
      } finally {
        this.loading = false;
      }
    },

    /**
     * Seitenleiste und Uebersicht sind zwei getrennte pageList-Instanzen. Nach
     * jeder Aenderung muessen beide neu laden - deshalb ueber ein Fenster-Ereignis
     * statt eines direkten refresh() auf der eigenen Instanz.
     */
    notifyPagesChanged() {
      this.$dispatch('pages-changed');
    },

    setPages(pages) {
      this.pages = pages;
      const availableIds = new Set(pages.map((page) => Number(page.id)));
      this.selectedPageIds = this.selectedPageIds.filter((id) => availableIds.has(id));
      this.orderedPages = [...pages].sort((left, right) => {
        const favoriteDifference = Number(right.is_favorite ?? 0) - Number(left.is_favorite ?? 0);
        if (favoriteDifference !== 0) {
          return favoriteDifference;
        }
        return String(right.updated_at || '').localeCompare(String(left.updated_at || ''));
      });
    },

    async createPage(type) {
      if (!navigator.onLine) {
        window.alert('Neue Seiten können nur online angelegt werden.');
        return;
      }
      const title = type === 'note' ? 'Neue Notiz' : 'Neue Task-Seite';
      if (this.activeCollection === 'shared' || this.activeCollection === 'favorites' || this.activeCollection === 'trash') {
        return;
      }
      const page = await apiFetch('/api/pages', {
        method: 'POST',
        body: JSON.stringify({
          type,
          title,
          notebook_id: this.activeCollection === 'notebook' ? this.activeNotebookId : null,
        }),
      });
      this.notifyPagesChanged();
      // Der Vorschlagstitel soll auf der neuen Seite gleich überschreibbar sein.
      markNewPageForTitleEdit(page.id);
      await this.navigate(page);
    },

    async rename(page) {
      if (!navigator.onLine) {
        window.alert('Umbenennen ist offline nicht möglich.');
        return;
      }
      const title = prompt('Neuer Titel', page.title);
      if (!title || title === page.title) {
        return;
      }
      await apiFetch(`/api/pages/${page.id}`, {
        method: 'PATCH',
        body: JSON.stringify({ title }),
      });
      this.notifyPagesChanged();
    },

    async toggleFavorite(page) {
      if (!navigator.onLine) {
        window.alert('Favoriten können offline nicht geändert werden.');
        return;
      }
      await apiFetch(`/api/pages/${page.id}`, {
        method: 'PATCH',
        body: JSON.stringify({ is_favorite: !page.is_favorite }),
      });
      this.notifyPagesChanged();
    },

    async remove(page) {
      if (!navigator.onLine) {
        window.alert('Löschen ist offline nicht möglich.');
        return;
      }
      if (!confirm(`"${page.title}" in den Papierkorb verschieben?`)) {
        return;
      }
      await apiFetch(`/api/pages/${page.id}`, { method: 'DELETE' });
      this.notifyPagesChanged();
      if (this.currentPageId === page.id) {
        await this.navigateTo('/app');
      }
    },

    trashRemainingLabel(page) {
      if (!page.deleted_at) {
        return '';
      }
      const elapsedDays = Math.floor((Date.now() - new Date(page.deleted_at).getTime()) / 86_400_000);
      const days = this.trashRetentionDays - elapsedDays;
      if (days <= 0) {
        return 'wird demnächst endgültig gelöscht';
      }
      return days === 1 ? 'noch 1 Tag' : `noch ${days} Tage`;
    },

    async restorePage(page) {
      if (!navigator.onLine) return;
      await apiFetch(`/api/pages/${page.id}/restore`, { method: 'POST' });
      await this.refresh();
      this.notifyPagesChanged();
    },

    async purgePage(page) {
      if (!navigator.onLine || !window.confirm(
        `„${page.title}" endgültig löschen? Das lässt sich nicht rückgängig machen.`,
      )) return;
      await apiFetch(`/api/pages/${page.id}/purge`, { method: 'DELETE' });
      await this.refresh();
      this.notifyPagesChanged();
    },

    async emptyTrash() {
      if (!navigator.onLine || this.pages.length === 0 || !window.confirm(
        `Papierkorb leeren? ${this.pages.length} Seite(n) werden endgültig gelöscht.`,
      )) return;
      await apiFetch('/api/pages/trash', { method: 'DELETE' });
      await this.refresh();
      this.notifyPagesChanged();
    },

    async leave(page) {
      if (!navigator.onLine) {
        window.alert('Freigaben können offline nicht verlassen werden.');
        return;
      }
      if (!confirm(`Freigabe von "${page.title}" verlassen? Die Seite wird aus deiner Seitenliste entfernt.`)) {
        return;
      }
      await apiFetch(`/api/pages/${page.id}/share-access`, { method: 'DELETE' });
      this.notifyPagesChanged();
      if (this.currentPageId === page.id) {
        await this.navigateTo('/app');
      }
    },

    async move(page, notebookId) {
      if (page.is_shared) return;
      await this.movePagesByIds([Number(page.id)], notebookId);
    },

    pageUrl(page) {
      return `/app/page/${page.id}`;
    },

    async navigate(page) {
      this.clearPageSelection();
      await this.navigateTo(this.pageUrl(page), page);
    },

    async navigateTo(url, page = null, pushHistory = true) {
      if (this.navigating) {
        return;
      }

      this.navigating = true;
      try {
        if (typeof window.__prepareWorkspaceNavigation === 'function') {
          await window.__prepareWorkspaceNavigation();
        }

        let html = null;
        try {
          const response = await fetch(url, {
            credentials: 'same-origin',
            headers: {
              Accept: 'text/html',
              'X-Requested-With': 'XMLHttpRequest',
            },
          });
          if (!response.ok) {
            throw new Error(`Navigation fehlgeschlagen (${response.status})`);
          }
          html = await response.text();
          await cacheDocument(url, html);
        } catch (error) {
          html = await readCachedDocument(url);
          if (!html && page) {
            html = offlinePageHtml(page);
          } else if (!html && url.startsWith('/app/page/')) {
            const id = Number(url.split('/').pop());
            const cachedPage = await getCachedPage(id);
            if (cachedPage) {
              html = offlinePageHtml(cachedPage);
              page = cachedPage;
            }
          }
          if (!html && url === '/app') {
            html = await readCachedDocument('/app');
          }
          if (!html) {
            throw error;
          }
        }

        const documentParser = new DOMParser();
        const nextDocument = documentParser.parseFromString(html, 'text/html');
        const currentMain = document.querySelector('main.workspace-main');
        const nextMain = nextDocument.querySelector('main.workspace-main');
        if (!currentMain || !nextMain) {
          throw new Error('Hauptbereich der Seite fehlt.');
        }

        this.setPageGlobals(page);
        window.Alpine?.destroyTree(currentMain);
        currentMain.replaceWith(nextMain);
        document.title = nextDocument.title || (page?.title || document.title);
        if (pushHistory) {
          window.history.pushState({}, '', url);
        }
        this.currentPageId = page ? page.id : (url === '/app' ? null : this.currentPageId);
        // Eine geöffnete Seite rückt mobil in den Vordergrund, der Sprung zur
        // Übersicht dagegen nicht - dort bleibt die Seitenauswahl stehen. Die
        // Leiste gehört einer eigenen Alpine-Komponente, deshalb das Ereignis
        // statt einer direkten Zuweisung.
        if (page) {
          this.$dispatch('close-sidebar');
        }
        window.Alpine?.initTree(nextMain);
        window.scrollTo(0, 0);
      } catch (error) {
        if (navigator.onLine) {
          window.location.href = url;
        } else {
          window.alert('Diese Seite ist offline nicht verfügbar. Bitte zuerst online laden.');
        }
      } finally {
        this.navigating = false;
      }
    },

    setPageGlobals(page) {
      const isShared = Boolean(page?.is_shared);
      const canEdit = isShared
        ? page?.can_edit === true || page?.can_edit === 1 || page?.share_permission === 'write'
        : Boolean(page);
      window.__CURRENT_PAGE_ID__ = page ? Number(page.id) : null;
      window.__CURRENT_PAGE_TITLE__ = page?.title || null;
      window.__CURRENT_PAGE_IS_SHARED__ = isShared;
      window.__CURRENT_PAGE_PERMISSION__ = page?.share_permission || null;
      window.__CURRENT_PAGE_CAN_EDIT__ = canEdit;
    },

    /**
     * Übersicht: Favoriten zuerst, danach zuletzt bearbeitete zuerst. Der
     * Ausschnitt wächst beim Scrollen, damit auch große Workspaces nicht auf
     * einen Schlag in den DOM gerendert werden (FR-WS-11).
     */
    recentPages() {
      return this.orderedPages.slice(0, this.recentLimit);
    },

    hasMoreRecentPages() {
      return this.orderedPages.length > this.recentLimit;
    },

    loadMoreRecentPages() {
      if (this.hasMoreRecentPages()) {
        this.recentLimit += RECENT_PAGE_SIZE;
      }
    },

    recentCountLabel() {
      if (this.loading) {
        return 'Lädt…';
      }
      const total = this.orderedPages.length;
      const shown = Math.min(this.recentLimit, total);

      return total > shown ? `${shown} von ${total} Seiten` : `${total} Seiten`;
    },

    searchResultsLabel() {
      const total = this.searchResults.length;
      return total === 1 ? '1 Ergebnis' : `${total} Ergebnisse`;
    },

    observeRecentSentinel() {
      const sentinel = this.$refs.recentSentinel;
      if (!sentinel || this.recentObserver || typeof IntersectionObserver !== 'function') {
        return;
      }

      this.recentObserver = new IntersectionObserver((entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
          this.loadMoreRecentPages();
        }
      }, { rootMargin: '400px' });
      this.recentObserver.observe(sentinel);
    },

    filteredPages() {
      const pages = this.searchQuery.trim() === '' ? this.pages : this.searchResults;
      if (this.activeCollection === 'favorites') {
        return pages.filter((page) => page.is_favorite);
      }
      return pages;
    },

    /** Zweite Kartenzeile: Anriss der Notiz bzw. Aufgabenstand der Task-Seite. */
    pageSummary(page) {
      if (page.type === 'task') {
        const total = Number(page.task_count || 0);
        if (total === 0) {
          return 'Noch keine Aufgaben';
        }

        return `${total} ${total === 1 ? 'Aufgabe' : 'Aufgaben'} · ${Number(page.open_task_count || 0)} offen`;
      }

      return String(page.preview || '').trim() || 'Leere Notiz';
    },

    /** Dritte Kartenzeile: wer zuletzt geändert hat und wann. */
    pageMeta(page) {
      const parts = [];
      if (page.last_editor_name) {
        parts.push(page.last_editor_name);
      }
      if (page.updated_at) {
        parts.push(new Intl.DateTimeFormat('de-DE', {
          dateStyle: 'medium',
          timeStyle: 'short',
        }).format(new Date(page.updated_at)));
      }

      return parts.join(' · ');
    },

    clearSearch() {
      this.searchQuery = '';
      this.searchResults = [];
    },

    async search() {
      const query = this.searchQuery.trim();
      if (!query) {
        this.searchResults = [];
        return;
      }
      this.searchLoading = true;
      try {
        if (!navigator.onLine) {
          const q = query.toLowerCase();
          this.searchResults = this.pages.filter((page) => String(page.title || '').toLowerCase().includes(q));
          return;
        }
        const data = await apiFetch(`/api/search?q=${encodeURIComponent(query)}`);
        this.searchResults = data.pages;
      } finally {
        this.searchLoading = false;
      }
    },

    async logout() {
      // Abmelden löscht den lokalen Cache inklusive Queue - ungesyncte
      // Änderungen wären damit unwiederbringlich weg.
      const unsynced = await countUnsyncedChanges();
      if (unsynced > 0) {
        if (navigator.onLine) {
          await syncOutbox();
        }
        const remaining = await countUnsyncedChanges();
        if (
          remaining > 0
          && !window.confirm(
            `${remaining} Änderung(en) sind noch nicht synchronisiert und gehen beim Abmelden verloren. Trotzdem abmelden?`,
          )
        ) {
          return;
        }
      }

      try {
        await clearOfflineData({ unregisterWorker: true });
      } catch {
        /* continue logout */
      }
      await apiFetch('/auth/logout', { method: 'POST' });
      window.location.href = '/';
    },

    destroy() {
      this.recentObserver?.disconnect();
      this.recentObserver = null;
    },
  };
}
