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

    async init() {
      await this.refresh();
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
      window.location.href = `/app/page/${page.id}`;
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
        window.location.href = '/app';
        return;
      }
      await this.refresh();
    },

    pageUrl(page) {
      return `/app/page/${page.id}`;
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
