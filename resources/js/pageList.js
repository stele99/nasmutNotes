import { apiFetch } from './api.js';

export function pageList() {
  return {
    pages: [],
    loading: true,
    sort: 'updated',
    typeFilter: null,
    currentPageId: window.__CURRENT_PAGE_ID__ || null,
    searchQuery: '',
    searchResults: [],
    searchLoading: false,
    navigating: false,

    async init() {
      const currentPageRoot = document.querySelector('main.workspace-main');
      if (!this.currentPageId && currentPageRoot?.dataset.pageId) {
        this.currentPageId = Number(currentPageRoot.dataset.pageId);
      }
      await this.refresh();

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
      }
    },

    async refresh() {
      this.loading = true;
      try {
        const qs = new URLSearchParams({ sort: this.sort });
        if (this.typeFilter) {
          qs.set('type', this.typeFilter);
        }
        const data = await apiFetch(`/api/pages?${qs.toString()}`);
        this.pages = data.pages;
      } finally {
        this.loading = false;
      }
    },

    async createPage(type) {
      const title = type === 'note' ? 'Neue Notiz' : 'Neue Task-Seite';
      const page = await apiFetch('/api/pages', {
        method: 'POST',
        body: JSON.stringify({ type, title }),
      });
      await this.navigate(page);
    },

    async rename(page) {
      const title = prompt('Neuer Titel', page.title);
      if (!title || title === page.title) {
        return;
      }
      await apiFetch(`/api/pages/${page.id}`, {
        method: 'PATCH',
        body: JSON.stringify({ title }),
      });
      await this.refresh();
    },

    async toggleFavorite(page) {
      await apiFetch(`/api/pages/${page.id}`, {
        method: 'PATCH',
        body: JSON.stringify({ is_favorite: !page.is_favorite }),
      });
      await this.refresh();
    },

    async remove(page) {
      if (!confirm(`"${page.title}" in den Papierkorb verschieben?`)) {
        return;
      }
      await apiFetch(`/api/pages/${page.id}`, { method: 'DELETE' });
      if (this.currentPageId === page.id) {
        await this.navigateTo('/app');
        return;
      }
      await this.refresh();
    },

    async leave(page) {
      if (!confirm(`Freigabe von "${page.title}" verlassen? Die Seite wird aus deiner Seitenliste entfernt.`)) {
        return;
      }
      await apiFetch(`/api/pages/${page.id}/share-access`, { method: 'DELETE' });
      if (this.currentPageId === page.id) {
        await this.navigateTo('/app');
        return;
      }
      await this.refresh();
    },

    pageUrl(page) {
      return `/app/page/${page.id}`;
    },

    async navigate(page) {
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

        const html = await response.text();
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
        document.title = nextDocument.title;
        if (pushHistory) {
          window.history.pushState({}, '', url);
        }
        this.currentPageId = page ? page.id : null;
        this.sidebarOpen = false;
        window.Alpine?.initTree(nextMain);
        window.scrollTo(0, 0);
      } catch (error) {
        window.location.href = url;
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

    recentPages() {
      return this.pages.slice(0, 5);
    },

    filteredPages() {
      return this.searchQuery.trim() === '' ? this.pages : this.searchResults;
    },

    async search() {
      const query = this.searchQuery.trim();
      if (!query) {
        this.searchResults = [];
        return;
      }
      this.searchLoading = true;
      try {
        const data = await apiFetch(`/api/search?q=${encodeURIComponent(query)}`);
        this.searchResults = data.pages;
      } finally {
        this.searchLoading = false;
      }
    },

    async logout() {
      await apiFetch('/auth/logout', { method: 'POST' });
      window.location.href = '/';
    },
  };
}
