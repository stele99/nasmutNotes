import { apiFetch } from './api.js';

export function taskBoard() {
  return {
    pageId: null,
    categories: [],
    loading: true,
    activeTask: null,
    newCategoryName: '',
    creatingCategory: false,
    newTaskTitles: {},
    savingCategoryId: null,
    pageTitle: '',
    savedPageTitle: '',
    editingPageTitle: false,
    savingPageTitle: false,

    async init() {
      this.pageId = window.__CURRENT_PAGE_ID__;
      this.pageTitle = window.__CURRENT_PAGE_TITLE__;
      this.savedPageTitle = this.pageTitle;
      await this.refresh();
    },

    async refresh() {
      this.loading = true;
      try {
        const data = await apiFetch(`/api/pages/${this.pageId}/board`);
        this.categories = data.categories;
      } finally {
        this.loading = false;
      }
    },

    async addCategory() {
      const name = this.newCategoryName.trim();
      if (!name) {
        return;
      }
      await apiFetch(`/api/pages/${this.pageId}/categories`, {
        method: 'POST',
        body: JSON.stringify({ name }),
      });
      this.newCategoryName = '';
      this.creatingCategory = false;
      await this.refresh();
    },

    openCategoryDialog() {
      this.creatingCategory = true;
      this.$nextTick(() => this.$refs.categoryName?.focus());
    },

    closeCategoryDialog() {
      this.newCategoryName = '';
      this.creatingCategory = false;
    },

    async renameCategory(category) {
      const name = prompt('Kapitel umbenennen', category.name);
      if (!name || name === category.name) {
        return;
      }
      await apiFetch(`/api/categories/${category.id}`, {
        method: 'PATCH',
        body: JSON.stringify({ name }),
      });
      await this.refresh();
    },

    async deleteCategory(category) {
      if (category.tasks.length > 0) {
        const cascade = confirm(
          `"${category.name}" enthält ${category.tasks.length} Aufgabe(n). OK = alle mitlöschen, Abbrechen = nichts tun.`,
        );
        if (!cascade) {
          return;
        }
        await apiFetch(`/api/categories/${category.id}?cascade=1`, { method: 'DELETE' });
      } else {
        await apiFetch(`/api/categories/${category.id}`, { method: 'DELETE' });
      }
      await this.refresh();
    },

    async addTask(category, event) {
      const form = event.currentTarget;
      const title = (this.newTaskTitles[category.id] || '').trim();
      if (!title) {
        form.querySelector('input')?.focus();
        return;
      }
      this.savingCategoryId = category.id;
      try {
        const task = await apiFetch(`/api/categories/${category.id}/tasks`, {
          method: 'POST',
          body: JSON.stringify({ title }),
        });
        category.tasks.push(task);
        this.newTaskTitles[category.id] = '';
      } finally {
        this.savingCategoryId = null;
      }
      await this.$nextTick();
      form.querySelector('input')?.focus();
    },

    async savePageTitle() {
      if (this.savingPageTitle) {
        return;
      }
      const title = this.pageTitle.trim();
      if (!title) {
        this.cancelPageTitleEdit();
        return;
      }
      if (title === this.savedPageTitle) {
        this.editingPageTitle = false;
        return;
      }
      this.savingPageTitle = true;
      try {
        const page = await apiFetch(`/api/pages/${this.pageId}`, {
          method: 'PATCH',
          body: JSON.stringify({ title }),
        });
        this.pageTitle = page.title;
        this.savedPageTitle = page.title;
        document.title = page.title;
      } finally {
        this.savingPageTitle = false;
        this.editingPageTitle = false;
      }
    },

    startEditingPageTitle() {
      this.editingPageTitle = true;
      this.$nextTick(() => this.$refs.titleInput?.focus());
    },

    cancelPageTitleEdit() {
      this.pageTitle = this.savedPageTitle;
      this.editingPageTitle = false;
    },

    openTask(task) {
      this.activeTask = { ...task };
    },

    closeTask() {
      this.activeTask = null;
    },

    async saveTask() {
      const t = this.activeTask;
      await apiFetch(`/api/tasks/${t.id}`, {
        method: 'PATCH',
        body: JSON.stringify({
          title: t.title,
          description: t.description,
          responsible: t.responsible,
          link: t.link,
          is_done: t.is_done,
        }),
      });
      this.activeTask = null;
      await this.refresh();
    },

    async toggleDone(task) {
      await apiFetch(`/api/tasks/${task.id}`, {
        method: 'PATCH',
        body: JSON.stringify({ is_done: !task.is_done }),
      });
      await this.refresh();
    },

    async deleteTask(task) {
      if (!confirm(`Task "${task.title}" löschen?`)) {
        return;
      }
      await apiFetch(`/api/tasks/${task.id}`, { method: 'DELETE' });
      await this.refresh();
    },

  };
}
