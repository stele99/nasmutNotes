import { apiFetch } from './api.js';
import { consumeNewPageTitleEdit } from './newPageTitle.js';
import { cacheBoard, readCachedBoard } from './offline/runtime.js';
import { pageLocationMixin } from './pageLocation.js';
import { voiceFormData, voiceRecorderMixin } from './voice.js';

const POLL_INTERVAL_MS = 5000;

export function taskBoard() {
  return {
    ...pageLocationMixin(),
    ...voiceRecorderMixin(),
    pageId: null,
    categories: [],
    hiddenCompletedCategories: {},
    // Es ist immer nur ein Kapitel aktiv - mobil per Dropdown gewählt, auf dem
    // Desktop per Reiter. Als String gehalten, weil <select> nur Strings liefert.
    selectedCategoryId: '',
    // ID des Kapitels, dessen "..."-Menü offen ist (Reiterleiste bzw. mobiles
    // Dropdown teilen sich dieselbe Menü-Instanz), oder null.
    openCategoryMenuId: null,
    loading: true,
    activeTask: null,
    savingTask: false,
    taskConflict: null,
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
    // Personen mit Zugriff auf die Seite - Auswahlliste für Verantwortliche.
    collaborators: [],
    myName: '',
    onlyMineCategories: {},
    responsibleFreeText: false,
    pollTimer: null,
    polling: false,
    lastBoardJson: '',
    visibilityHandler: null,
    offlineNotice: '',

    /**
     * Aufgaben werden - anders als Notizen - nicht in die Offline-Queue
     * gestellt. Schreibzugriffe brauchen deshalb eine Verbindung; ohne diesen
     * Guard liefen sie in eine unbehandelte Rejection ohne jede Rückmeldung.
     */
    requireOnline() {
      if (navigator.onLine) {
        this.offlineNotice = '';
        return true;
      }
      this.offlineNotice = 'Aufgaben können offline nur gelesen werden.';

      return false;
    },

    async init() {
      const pageRoot = this.$root;
      this.pageId = Number(pageRoot?.dataset.pageId || window.__CURRENT_PAGE_ID__ || 0);
      this.pageTitle = pageRoot?.dataset.pageTitle || window.__CURRENT_PAGE_TITLE__ || '';
      this.canEditPage = pageRoot?.dataset.pageCanEdit
        ? pageRoot.dataset.pageCanEdit === '1'
        : Boolean(window.__CURRENT_PAGE_CAN_EDIT__);
      this.savedPageTitle = this.pageTitle;
      this.initPageLocation(pageRoot);
      this.loadHiddenCompletedCategories();
      this.loadOnlyMineCategories();

      if (!this.pageId) {
        this.loading = false;
        return;
      }

      if (this.canEditPage && consumeNewPageTitleEdit(this.pageId)) {
        this.startEditingPageTitle(true);
      }

      this.loadSelectedCategory();
      await this.refresh();
      void this.loadCollaborators();

      this.pollTimer = setInterval(() => this.pollBoard(), POLL_INTERVAL_MS);
      this.visibilityHandler = () => {
        if (document.visibilityState === 'visible') {
          this.pollBoard();
        }
      };
      document.addEventListener('visibilitychange', this.visibilityHandler);
    },

    // Holt leise den aktuellen Stand, damit Änderungen anderer Nutzer ohne Reload sichtbar werden.
    async pollBoard() {
      if (this.polling || document.visibilityState === 'hidden' || !navigator.onLine) {
        return;
      }
      this.polling = true;
      try {
        const data = await apiFetch(`/api/pages/${this.pageId}/board`);
        const boardJson = JSON.stringify(data.categories);
        if (boardJson !== this.lastBoardJson) {
          this.lastBoardJson = boardJson;
          this.categories = data.categories;
          this.ensureSelectedCategory();
        }
      } catch (error) {
        // Offline oder Serverfehler: der nächste Tick versucht es erneut.
      } finally {
        this.polling = false;
      }
    },

    hiddenCompletedStateKey() {
      return `task-board-hidden-completed-${this.pageId}`;
    },

    selectedCategoryStateKey() {
      return `task-board-active-category-${this.pageId}`;
    },

    loadSelectedCategory() {
      try {
        this.selectedCategoryId = localStorage.getItem(this.selectedCategoryStateKey()) || '';
      } catch (error) {
        this.selectedCategoryId = '';
      }
    },

    /**
     * Die zuletzt gewählte Kategorie wird pro Task-Seite im Browser gemerkt
     * (FR-TASK-16). Verschwindet sie (gelöscht, umsortiert), fällt die Auswahl
     * auf das erste Kapitel zurück.
     */
    ensureSelectedCategory() {
      if (this.categories.length === 0) {
        this.selectedCategoryId = '';
        return;
      }
      const known = this.categories.some(
        (category) => String(category.id) === this.selectedCategoryId,
      );
      if (!known) {
        this.selectedCategoryId = String(this.categories[0].id);
        this.persistSelectedCategory();
      }
    },

    persistSelectedCategory() {
      try {
        if (this.selectedCategoryId === '') {
          localStorage.removeItem(this.selectedCategoryStateKey());
        } else {
          localStorage.setItem(this.selectedCategoryStateKey(), this.selectedCategoryId);
        }
      } catch (error) {
        // Lokaler Speicher kann deaktiviert oder voll sein.
      }
    },

    selectedCategory() {
      return this.categories.find(
        (category) => String(category.id) === this.selectedCategoryId,
      ) || null;
    },

    hasSelectedCategory() {
      return this.selectedCategory() !== null;
    },

    /**
     * Wählt ein Kapitel aus - auf dem Desktop per Klick auf den Reiter, mobil
     * über das Dropdown (dort via `persistSelectedCategory` am `@change`).
     */
    selectCategory(category) {
      this.selectedCategoryId = String(category.id);
      this.persistSelectedCategory();
    },

    isSelectedCategory(category) {
      return String(category.id) === this.selectedCategoryId;
    },

    /**
     * Kapitelaktionen liegen hinter einem Menü neben der Auswahl (Reiter auf
     * dem Desktop, Dropdown mobil) - als einzelne Schalter bräuchten sie mehr
     * Breite, als dort zur Verfügung steht (FR-TASK-16).
     */
    toggleCategoryMenu(categoryId) {
      const id = String(categoryId);
      this.openCategoryMenuId = this.openCategoryMenuId === id ? null : id;
    },

    closeCategoryMenu() {
      this.openCategoryMenuId = null;
    },

    isCategoryMenuOpen(categoryId) {
      return this.openCategoryMenuId !== null && this.openCategoryMenuId === String(categoryId);
    },

    menuAddCategory() {
      this.closeCategoryMenu();
      this.openCategoryDialog();
    },

    menuRenameCategory(category) {
      this.closeCategoryMenu();
      if (category) {
        void this.renameCategory(category);
      }
    },

    menuImportTasks(category) {
      this.closeCategoryMenu();
      if (category) {
        this.openImportDialog(category);
      }
    },

    menuDeleteCategory(category) {
      this.closeCategoryMenu();
      if (category) {
        void this.deleteCategory(category);
      }
    },

    /**
     * Es ist immer nur ein Kapitel sichtbar - mobil per Dropdown, auf dem
     * Desktop per Reiter ausgewählt.
     */
    visibleCategories() {
      return this.categories.filter(
        (category) => String(category.id) === this.selectedCategoryId,
      );
    },

    loadHiddenCompletedCategories() {
      try {
        const stored = localStorage.getItem(this.hiddenCompletedStateKey());
        const parsed = stored ? JSON.parse(stored) : {};
        this.hiddenCompletedCategories = parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
      } catch (error) {
        this.hiddenCompletedCategories = {};
      }
    },

    saveHiddenCompletedCategories() {
      try {
        localStorage.setItem(this.hiddenCompletedStateKey(), JSON.stringify(this.hiddenCompletedCategories));
      } catch (error) {
        // Lokaler Speicher kann deaktiviert oder voll sein.
      }
    },

    openTaskCount(category) {
      return category.tasks.filter((task) => !task.is_done).length;
    },

    completedTaskCount(category) {
      return category.tasks.filter((task) => task.is_done).length;
    },

    areCompletedTasksHidden(category) {
      return Boolean(this.hiddenCompletedCategories[category.id]);
    },

    visibleTasks(category) {
      let tasks = category.tasks;
      if (this.areCompletedTasksHidden(category)) {
        tasks = tasks.filter((task) => !task.is_done);
      }
      if (this.isOnlyMine(category) && this.myName !== '') {
        const me = this.myName.trim().toLowerCase();
        tasks = tasks.filter((task) => String(task.responsible || '').trim().toLowerCase() === me);
      }

      return tasks;
    },

    /**
     * Filter „nur meine Aufgaben": vergleicht den Verantwortlichen mit dem
     * eigenen Anzeigenamen. Ohne bekannten Namen (offline, Ladefehler) bleibt
     * der Schalter aus, sonst verschwänden schlicht alle Aufgaben.
     */
    canFilterMine() {
      return this.myName !== '';
    },

    isOnlyMine(category) {
      return Boolean(this.onlyMineCategories[category.id]);
    },

    toggleOnlyMine(category) {
      const categoryKey = String(category.id);
      if (this.isOnlyMine(category)) {
        delete this.onlyMineCategories[categoryKey];
      } else {
        this.onlyMineCategories[categoryKey] = true;
      }
      this.saveOnlyMineCategories();
    },

    onlyMineStateKey() {
      return `task-board-only-mine-${this.pageId}`;
    },

    loadOnlyMineCategories() {
      try {
        const stored = localStorage.getItem(this.onlyMineStateKey());
        const parsed = stored ? JSON.parse(stored) : {};
        this.onlyMineCategories = parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
      } catch (error) {
        this.onlyMineCategories = {};
      }
    },

    saveOnlyMineCategories() {
      try {
        localStorage.setItem(this.onlyMineStateKey(), JSON.stringify(this.onlyMineCategories));
      } catch (error) {
        // Lokaler Speicher kann deaktiviert oder voll sein.
      }
    },

    toggleCompletedTasks(category) {
      const categoryKey = String(category.id);
      if (this.areCompletedTasksHidden(category)) {
        delete this.hiddenCompletedCategories[categoryKey];
      } else {
        this.hiddenCompletedCategories[categoryKey] = true;
      }
      this.saveHiddenCompletedCategories();
    },

    async refresh() {
      this.loading = true;
      try {
        const data = await apiFetch(`/api/pages/${this.pageId}/board`);
        this.lastBoardJson = JSON.stringify(data.categories);
        this.categories = data.categories;
        await cacheBoard(this.pageId, data);
      } catch (error) {
        const cached = await readCachedBoard(this.pageId);
        if (cached) {
          this.categories = cached.categories || [];
          this.lastBoardJson = JSON.stringify(this.categories);
        } else {
          throw error;
        }
      } finally {
        this.ensureSelectedCategory();
        this.loading = false;
      }
    },

    async addCategory() {
      if (!this.canEditPage || !this.requireOnline()) {
        return;
      }
      const name = this.newCategoryName.trim();
      if (!name) {
        return;
      }
      const created = await apiFetch(`/api/pages/${this.pageId}/categories`, {
        method: 'POST',
        body: JSON.stringify({ name }),
      });
      this.newCategoryName = '';
      this.creatingCategory = false;
      // Ein frisch angelegtes Kapitel wird direkt angesteuert, sonst bliebe es
      // in der Mobilansicht hinter dem Dropdown verborgen.
      if (created?.id) {
        this.selectedCategoryId = String(created.id);
        this.persistSelectedCategory();
      }
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
      if (!this.requireOnline()) {
        this.importError = this.offlineNotice;
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

    /**
     * Diktierte Aufgabe(n) im gerade gewählten Kapitel: Der Server zerlegt die
     * Aufnahme in einen oder mehrere Titel und legt sie wie beim Textimport an.
     */
    async handleVoiceRecording(recording) {
      const category = this.selectedCategory();
      if (!category) {
        return;
      }
      const body = voiceFormData(recording);
      const data = await apiFetch(`/api/categories/${category.id}/tasks/voice`, { method: 'POST', body });
      await this.refresh();
      this.voiceNotice = data.transcript ? `Erfasst: „${data.transcript}“` : 'Aufgabe erfasst.';
    },

    async renameCategory(category) {
      if (!this.canEditPage || !this.requireOnline()) {
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
      if (!this.canEditPage || !this.requireOnline()) {
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
      if (!this.canEditPage || !this.requireOnline()) {
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
        const task = await this.createTask(category, title);
        if (!task) {
          return;
        }
        category.tasks.push(task);
        this.newTaskTitles[category.id] = '';
      } finally {
        this.savingCategoryId = null;
      }
      await this.$nextTick();
      form.querySelector('input')?.focus();
    },

    /**
     * Legt eine Aufgabe an. Steht im Kapitel bereits eine mit gleichem Titel,
     * antwortet der Server mit 409 - dann entscheidet der Nutzer, ob die
     * Aufgabe trotzdem angelegt wird (FR-TASK-20). Gibt null zurück, wenn
     * abgebrochen wurde.
     */
    async createTask(category, title, allowDuplicate = false) {
      try {
        return await apiFetch(`/api/categories/${category.id}/tasks`, {
          method: 'POST',
          body: JSON.stringify(allowDuplicate ? { title, allow_duplicate: true } : { title }),
        });
      } catch (error) {
        if (error.status !== 409 || error.payload?.error?.code !== 'DUPLICATE_TITLE') {
          throw error;
        }

        const existing = error.payload.existing;
        const state = existing?.is_done ? 'bereits erledigt' : 'noch offen';
        const confirmed = window.confirm(
          `„${existing?.title || title}“ steht in „${category.name}“ bereits auf der Liste (${state}).\n\nTrotzdem ein zweites Mal anlegen?`,
        );

        return confirmed ? this.createTask(category, title, true) : null;
      }
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
      if (!this.requireOnline()) {
        this.cancelPageTitleEdit();
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
        // Die Seitenleiste ist eine eigene Alpine-Komponente und bekäme den
        // neuen Titel sonst erst nach einem Reload zu sehen.
        this.$dispatch('pages-changed');
      } finally {
        this.savingPageTitle = false;
        this.editingPageTitle = false;
      }
    },

    /**
     * `selectAll` markiert den bestehenden Titel komplett - gedacht für die
     * frisch angelegte Seite, deren Vorschlagstitel direkt ersetzt wird.
     */
    startEditingPageTitle(selectAll) {
      if (!this.canEditPage) {
        return;
      }
      this.editingPageTitle = true;
      this.$nextTick(() => {
        const input = this.$refs.titleInput;
        if (!input) {
          return;
        }
        input.focus();
        // Strikter Vergleich: Alpine übergibt bei `@click="startEditingPageTitle"`
        // das Event als erstes Argument, das darf nicht als „markieren" gelten.
        if (selectAll === true) {
          input.select();
        }
      });
    },

    cancelPageTitleEdit() {
      this.pageTitle = this.savedPageTitle;
      this.editingPageTitle = false;
    },

    /**
     * Verantwortliche lassen sich aus den Personen wählen, die die Seite sehen
     * (Eigentümer plus angenommene Freigaben, lesend wie schreibend). Freitext
     * bleibt möglich - „Responsible" verweist laut Datenmodell nicht zwingend
     * auf ein Konto (FR-TASK-21).
     */
    async loadCollaborators() {
      if (!this.pageId || !navigator.onLine) {
        return;
      }
      try {
        const data = await apiFetch(`/api/pages/${this.pageId}/collaborators`);
        this.collaborators = data.collaborators || [];
        this.myName = data.me?.name || '';
      } catch (error) {
        // Ohne Liste bleibt das Freitextfeld - kein Grund, die Seite zu stören.
      }
    },

    hasCollaboratorChoices() {
      return this.collaborators.length > 1;
    },

    collaboratorLabel(person) {
      return person.is_owner ? `${person.name} (Eigentümer)` : person.name;
    },

    /**
     * Steht im Feld ein Name, den die Liste nicht kennt, wird direkt auf
     * Freitext geschaltet - sonst verschwände der bestehende Wert.
     */
    isKnownCollaborator(name) {
      const needle = String(name || '').trim().toLowerCase();

      return this.collaborators.some((person) => person.name.trim().toLowerCase() === needle);
    },

    onResponsibleSelect(event) {
      const value = event.target.value;
      if (value === '__free__') {
        this.responsibleFreeText = true;
        return;
      }
      this.responsibleFreeText = false;
      if (this.activeTask) {
        this.activeTask.responsible = value;
      }
    },

    responsibleSelectValue() {
      if (this.responsibleFreeText) {
        return '__free__';
      }
      const current = this.activeTask?.responsible || '';

      return current === '' || this.isKnownCollaborator(current) ? current : '__free__';
    },

    openTask(task) {
      this.activeTask = { ...task };
      this.taskConflict = null;
      const current = String(task.responsible || '').trim();
      this.responsibleFreeText = current !== '' && !this.isKnownCollaborator(current);
    },

    closeTask() {
      if (this.savingTask) {
        return;
      }
      const hadConflict = this.taskConflict !== null;
      this.activeTask = null;
      this.taskConflict = null;
      if (hadConflict) {
        this.refresh();
      }
    },

    useTheirVersion() {
      if (!this.taskConflict) {
        return;
      }
      this.activeTask = { ...this.taskConflict };
      this.taskConflict = null;
    },

    handleTaskEditorEnter(event) {
      if (event.isComposing || event.shiftKey) {
        return;
      }
      event.preventDefault();
      this.saveTask();
    },

    async saveTask() {
      if (!this.canEditPage || !this.activeTask || this.savingTask || !this.requireOnline()) {
        return;
      }
      const t = this.activeTask;
      this.savingTask = true;
      try {
        await apiFetch(`/api/tasks/${t.id}`, {
          method: 'PATCH',
          body: JSON.stringify({
            title: t.title,
            description: t.description,
            responsible: t.responsible,
            link: t.link,
            is_done: t.is_done,
            version: t.version,
          }),
        });
        this.taskConflict = null;
        this.activeTask = null;
        await this.refresh();
      } catch (error) {
        if (error.status === 409 && error.payload?.current) {
          // Anderer Nutzer hat den Task geändert: Dialog offen lassen, Nutzer entscheidet.
          this.taskConflict = error.payload.current;
          this.activeTask.version = error.payload.current.version;
        } else {
          throw error;
        }
      } finally {
        this.savingTask = false;
      }
    },

    async toggleDone(task) {
      if (!this.canEditPage || !this.requireOnline()) {
        return;
      }
      try {
        await apiFetch(`/api/tasks/${task.id}`, {
          method: 'PATCH',
          body: JSON.stringify({ is_done: !task.is_done, version: task.version }),
        });
      } catch (error) {
        if (error.status !== 409) {
          throw error;
        }
        // Veralteter Stand: refresh unten zeigt die aktuelle Version, Haken kann erneut gesetzt werden.
      }
      await this.refresh();
    },

    async deleteActiveTask() {
      const task = this.activeTask;
      if (!task) {
        return;
      }
      const removed = await this.deleteTask(task);
      if (removed) {
        this.activeTask = null;
        this.taskConflict = null;
      }
    },

    async deleteTask(task) {
      if (!this.canEditPage || !this.requireOnline()) {
        return false;
      }
      if (!confirm(`Task "${task.title}" löschen?`)) {
        return false;
      }
      await apiFetch(`/api/tasks/${task.id}`, { method: 'DELETE' });
      await this.refresh();

      return true;
    },

    destroy() {
      this.destroyPageLocation();
      this.cancelVoice();
      clearInterval(this.pollTimer);
      if (this.visibilityHandler) {
        document.removeEventListener('visibilitychange', this.visibilityHandler);
      }
    },

  };
}
