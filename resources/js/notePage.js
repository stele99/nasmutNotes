import { apiFetch } from './api.js';
import { createEditor } from './editor/index.js';

const DEBOUNCE_MS = 1500;
const LOCAL_CACHE_PREFIX = 'notes-note-cache-';

export function noteEditorPage() {
  return {
    editor: null,
    status: 'loading', // loading | saved | saving | unsaved | offline | conflict
    version: 0,
    pageId: null,
    saveTimer: null,
    retryDelay: 1000,
    pendingSave: false,
    conflictContent: null,
    pageTitle: '',
    savedPageTitle: '',
    editingPageTitle: false,
    savingPageTitle: false,
    updatedAt: null,
    lastEditorName: null,

    async init() {
      this.pageId = window.__CURRENT_PAGE_ID__;
      this.pageTitle = window.__CURRENT_PAGE_TITLE__;
      this.savedPageTitle = this.pageTitle;

      let initial;
      try {
        initial = await apiFetch(`/api/pages/${this.pageId}/content`);
      } catch (e) {
        const cached = this.readLocalCache();
        initial = cached ? { content: cached.content, version: cached.version } : { content: { type: 'doc', content: [] }, version: 1 };
      }

      this.version = initial.version;
      this.updatedAt = initial.updated_at;
      this.lastEditorName = initial.last_editor_name;

      this.editor = createEditor({
        element: this.$refs.editor,
        content: initial.content,
        onUpdate: (json) => this.onChange(json),
        onTransaction: () => this.syncToolbar(),
      });
      this.bindToolbar();
      this.syncToolbar();

      this.status = 'saved';

      document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
          this.saveNow();
        }
      });
      window.addEventListener('beforeunload', () => {
        if (this.status === 'unsaved') {
          this.saveNow();
        }
      });
      window.addEventListener('keydown', (event) => {
        if ((event.metaKey || event.ctrlKey) && event.key === 's') {
          event.preventDefault();
          this.saveNow();
        }
      });
    },

    onChange(json) {
      this.status = 'unsaved';
      this.writeLocalCache(json);

      clearTimeout(this.saveTimer);
      this.saveTimer = setTimeout(() => this.saveNow(), DEBOUNCE_MS);
    },

    async saveNow() {
      if (!this.editor || this.pendingSave) {
        return;
      }
      clearTimeout(this.saveTimer);

      const content = this.editor.getJSON();
      this.pendingSave = true;
      this.status = 'saving';

      try {
        const result = await apiFetch(`/api/pages/${this.pageId}/content`, {
          method: 'PUT',
          body: JSON.stringify({ content, version: this.version }),
        });
        this.version = result.version;
        this.updatedAt = result.updated_at;
        this.lastEditorName = result.last_editor_name;
        this.status = 'saved';
        this.retryDelay = 1000;
        this.clearLocalCache();
      } catch (e) {
        if (e.status === 409) {
          this.status = 'conflict';
          this.conflictContent = e.payload.current;
          this.version = e.payload.current.version;
        } else {
          this.status = 'offline';
          this.pendingSave = false;
          setTimeout(() => this.saveNow(), this.retryDelay);
          this.retryDelay = Math.min(this.retryDelay * 2, 30000);
          return;
        }
      }

      this.pendingSave = false;
    },

    async keepMyVersion() {
      this.status = 'unsaved';
      this.conflictContent = null;
      await this.saveNow();
    },

    statusLabel() {
      return {
        loading: 'Lädt…',
        saved: 'Gespeichert',
        saving: 'Speichern…',
        unsaved: 'Nicht gespeichert',
        offline: 'Keine Verbindung – wird erneut versucht…',
        conflict: 'Konflikt: Version wurde anderswo geändert',
      }[this.status];
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

    runEditorCommand(command) {
      const chain = this.editor?.chain().focus();
      if (!chain) {
        return;
      }
      switch (command) {
        case 'bold':
          chain.toggleBold().run();
          break;
        case 'italic':
          chain.toggleItalic().run();
          break;
        case 'strike':
          chain.toggleStrike().run();
          break;
        case 'heading1':
          chain.toggleHeading({ level: 1 }).run();
          break;
        case 'heading2':
          chain.toggleHeading({ level: 2 }).run();
          break;
        case 'bulletList':
          chain.toggleBulletList().run();
          break;
        case 'blockquote':
          chain.toggleBlockquote().run();
          break;
        case 'undo':
          chain.undo().run();
          break;
        case 'redo':
          chain.redo().run();
          break;
        case 'link':
          this.toggleLink();
          break;
      }
    },

    bindToolbar() {
      this.$refs.toolbar?.addEventListener('mousedown', (event) => {
        const button = event.target.closest('[data-editor-command]');
        if (!(button instanceof HTMLButtonElement)) {
          return;
        }
        event.preventDefault();
        this.runEditorCommand(button.dataset.editorCommand);
      });
    },

    syncToolbar() {
      const toolbar = this.$refs.toolbar;
      if (!this.editor || !toolbar) {
        return;
      }
      for (const button of toolbar.querySelectorAll('[data-editor-command]')) {
        const command = button.dataset.editorCommand;
        const active = {
          bold: this.editor.isActive('bold'),
          italic: this.editor.isActive('italic'),
          strike: this.editor.isActive('strike'),
          heading1: this.editor.isActive('heading', { level: 1 }),
          heading2: this.editor.isActive('heading', { level: 2 }),
          bulletList: this.editor.isActive('bulletList'),
          blockquote: this.editor.isActive('blockquote'),
          link: this.editor.isActive('link'),
        }[command] ?? false;
        button.classList.toggle('is-active', active);
      }
    },

    toggleLink() {
      if (!this.editor) {
        return;
      }
      const current = this.editor.getAttributes('link').href || '';
      const href = prompt('Link-Adresse', current);
      if (href === null) {
        return;
      }
      if (href.trim() === '') {
        this.editor.chain().focus().unsetLink().run();
        return;
      }
      this.editor.chain().focus().setLink({ href: href.trim() }).run();
      this.syncToolbar();
    },

    lastEditedLabel() {
      if (!this.updatedAt) {
        return '';
      }
      const date = new Intl.DateTimeFormat('de-DE', {
        dateStyle: 'medium',
        timeStyle: 'short',
      }).format(new Date(this.updatedAt));
      return this.lastEditorName ? `Zuletzt geändert von ${this.lastEditorName}, ${date}` : `Zuletzt geändert ${date}`;
    },

    writeLocalCache(content) {
      try {
        localStorage.setItem(
          LOCAL_CACHE_PREFIX + this.pageId,
          JSON.stringify({ content, version: this.version }),
        );
      } catch (e) {
        /* Speicher voll oder deaktiviert – kein Blocker fürs Editieren */
      }
    },

    readLocalCache() {
      try {
        const raw = localStorage.getItem(LOCAL_CACHE_PREFIX + this.pageId);
        return raw ? JSON.parse(raw) : null;
      } catch (e) {
        return null;
      }
    },

    clearLocalCache() {
      try {
        localStorage.removeItem(LOCAL_CACHE_PREFIX + this.pageId);
      } catch (e) {
        /* ignore */
      }
    },
  };
}
