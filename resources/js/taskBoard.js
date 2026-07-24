import { apiFetch } from './api.js';

export function taskBoard() {
  return {
    pageId: null,
    categories: [],
    collapsedCategories: {},
    loading: true,
    activeTask: null,
    newCategoryName: '',
    creatingCategory: false,
    importCategory: null,
    importText: '',
    importError: '',
    importing: false,
    newTaskTitles: {},
    savingCategoryId: null,
    pageTitle: '',
    canEditPage: Boolean(window.__CURRENT_PAGE_CAN_EDIT__),
    savedPageTitle: '',
    editingPageTitle: false,
    savingPageTitle: false,

    async init() {
      const pageRoot = this.$root;
      this.pageId = Number(pageRoot?.dataset.pageId || window.__CURRENT_PAGE_ID__ || 0);
      this.pageTitle = pageRoot?.dataset.pageTitle || window.__CURRENT_PAGE_TITLE__ || '';
      this.canEditPage = pageRoot?.dataset.pageCanEdit
        ? pageRoot.dataset.pageCanEdit === '1'
        : Boolean(window.__CURRENT_PAGE_CAN_EDIT__);
      this.savedPageTitle = this.pageTitle;
      this.loadCollapsedCategories();

      if (!this.pageId) {
        this.loading = false;
        return;
      }

      await this.refresh();
    },

    categoryStateKey() {
      return `task-board-collapsed-${this.pageId}`;
    },

    loadCollapsedCategories() {
      try {
        const stored = localStorage.getItem(this.categoryStateKey());
        const parsed = stored ? JSON.parse(stored) : {};
        this.collapsedCategories = parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
      } catch (error) {
        this.collapsedCategories = {};
      }
    },

    saveCollapsedCategories() {
      try {
        localStorage.setItem(this.categoryStateKey(), JSON.stringify(this.collapsedCategories));
      } catch (error) {
        // Lokaler Speicher kann deaktiviert oder voll sein.
      }
    },

    isCategoryCollapsed(category) {
      return Boolean(this.collapsedCategories[category.id]);
    },

    openTaskCount(category) {
      return category.tasks.filter((task) => !task.is_done).length;
    },

    completedTaskCount(category) {
      return category.tasks.filter((task) => task.is_done).length;
    },

    toggleCategory(category) {
      const categoryKey = String(category.id);
      if (this.isCategoryCollapsed(category)) {
        delete this.collapsedCategories[categoryKey];
      } else {
        this.collapsedCategories[categoryKey] = true;
      }
      this.saveCollapsedCategories();
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
      if (!this.canEditPage) {
        return;
      }
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

    openImportDialog(category) {
      if (!this.canEditPage) {
        return;
      }
      this.importCategory = category;
      this.importText = '';
      this.importError = '';
      this.$nextTick(() => this.$refs.importText?.focus());
    },

    closeImportDialog() {
      if (this.importing) {
        return;
      }
      this.importCategory = null;
      this.importText = '';
      this.importError = '';
    },

    async importTasks() {
      if (!this.canEditPage || !this.importCategory || this.importing) {
        return;
      }
      if (!this.importText.trim()) {
        this.importError = 'Bitte mindestens eine Aufgabe eingeben.';
        return;
      }

      this.importing = true;
      this.importError = '';
      try {
        await apiFetch(`/api/categories/${this.importCategory.id}/tasks/import`, {
          method: 'POST',
          body: JSON.stringify({ text: this.importText }),
        });
        this.importing = false;
        this.closeImportDialog();
        await this.refresh();
      } catch (error) {
        this.importError = error.message || 'Die Aufgaben konnten nicht eingefügt werden.';
      } finally {
        this.importing = false;
      }
    },

    async renameCategory(category) {
      if (!this.canEditPage) {
        return;
      }
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
      if (!this.canEditPage) {
        return;
      }
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
      if (!this.canEditPage) {
        return;
      }
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
      if (!this.canEditPage) {
        this.cancelPageTitleEdit();
        return;
      }
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
      if (!this.canEditPage) {
        return;
      }
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
      if (!this.canEditPage || !this.activeTask) {
        return;
      }
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
      if (!this.canEditPage) {
        return;
      }
      await apiFetch(`/api/tasks/${task.id}`, {
        method: 'PATCH',
        body: JSON.stringify({ is_done: !task.is_done }),
      });
      await this.refresh();
    },

    async deleteTask(task) {
      if (!this.canEditPage) {
        return;
      }
      if (!confirm(`Task "${task.title}" löschen?`)) {
        return;
      }
      await apiFetch(`/api/tasks/${task.id}`, { method: 'DELETE' });
      await this.refresh();
    },

  };
}
