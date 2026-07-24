import { apiFetch } from './api.js';
import { createEditor } from './editor/index.js';

const DEBOUNCE_MS = 1500;
const LOCAL_CACHE_PREFIX = 'notes-note-cache-';

export function noteEditorPage() {
  // ProseMirror keeps mutable transaction state and must not be made reactive by Alpine.
  let editor = null;
  const pendingUploads = new Set();

  return {
    status: 'loading', // loading | saved | saving | unsaved | offline | invalid | conflict
    version: 0,
    pageId: null,
    canEditPage: Boolean(window.__CURRENT_PAGE_CAN_EDIT__),
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
    visibilityHandler: null,
    beforeUnloadHandler: null,
    keyDownHandler: null,
    navigationHandler: null,
    pendingImageUploads: 0,
    imageUploadError: '',
    saveError: '',
    linkMenuOpen: false,
    activeLinkHref: '',
    activeLinkPosition: 0,
    linkMenuStyle: '',

    async init() {
      const pageRoot = this.$root;
      this.pageId = Number(pageRoot?.dataset.pageId || window.__CURRENT_PAGE_ID__ || 0);
      this.pageTitle = pageRoot?.dataset.pageTitle || window.__CURRENT_PAGE_TITLE__ || '';
      this.canEditPage = pageRoot?.dataset.pageCanEdit
        ? pageRoot.dataset.pageCanEdit === '1'
        : Boolean(window.__CURRENT_PAGE_CAN_EDIT__);
      this.savedPageTitle = this.pageTitle;

      if (!this.pageId) {
        this.status = 'offline';
        return;
      }

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

      editor?.destroy();
      editor = createEditor({
        element: this.$refs.editor,
        content: initial.content,
        editable: this.canEditPage,
        onUpdate: (json) => this.onChange(json),
        onTransaction: () => this.syncToolbar(),
        onImageUpload: (file) => this.uploadImage(file),
        onImageUploadError: (error) => this.handleImageUploadError(error),
        onPendingImageUploads: (count) => {
          this.pendingImageUploads = count;
        },
        onLinkClick: (link) => this.openLinkMenu(link),
      });
      this.syncToolbar();

      this.status = 'saved';

      this.visibilityHandler = () => {
        if (document.visibilityState === 'hidden') {
          this.saveNow();
        }
      };
      document.addEventListener('visibilitychange', this.visibilityHandler);
      this.beforeUnloadHandler = () => {
        if (this.status === 'unsaved') {
          this.saveNow();
        }
      };
      window.addEventListener('beforeunload', this.beforeUnloadHandler);
      this.keyDownHandler = (event) => {
        if ((event.metaKey || event.ctrlKey) && event.key === 's') {
          event.preventDefault();
          this.saveNow();
        }
      };
      window.addEventListener('keydown', this.keyDownHandler);
      this.navigationHandler = async () => {
        await Promise.allSettled(Array.from(pendingUploads));
        if (this.status === 'unsaved') {
          await this.saveNow();
        }
      };
      window.__prepareWorkspaceNavigation = this.navigationHandler;
    },

    onChange(json) {
      this.status = 'unsaved';
      this.writeLocalCache(json);

      clearTimeout(this.saveTimer);
      this.saveTimer = setTimeout(() => this.saveNow(), DEBOUNCE_MS);
    },

    async saveNow() {
      if (!editor || this.pendingSave) {
        return;
      }
      clearTimeout(this.saveTimer);

      const content = editor.getJSON();
      this.pendingSave = true;
      this.status = 'saving';
      this.saveError = '';

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
        } else if (e.status === 400 || e.status === 403 || e.status === 413 || e.status === 422) {
          this.status = 'invalid';
          this.saveError = e.message || 'Der Notizinhalt konnte nicht gespeichert werden.';
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
      if (this.pendingImageUploads > 0) {
        return this.pendingImageUploads === 1 ? 'Bild wird hochgeladen…' : 'Bilder werden hochgeladen…';
      }
      return {
        loading: 'Lädt…',
        saved: 'Gespeichert',
        saving: 'Speichern…',
        unsaved: 'Nicht gespeichert',
        offline: 'Keine Verbindung – wird erneut versucht…',
        invalid: 'Speichern fehlgeschlagen',
        conflict: 'Konflikt: Version wurde anderswo geändert',
      }[this.status];
    },

    uploadImage(file) {
      this.imageUploadError = '';
      const body = new FormData();
      body.append('file', file, file.name || 'screenshot.png');
      const request = apiFetch(`/api/pages/${this.pageId}/attachments`, {
        method: 'POST',
        body,
      });
      pendingUploads.add(request);
      request.then(
        () => pendingUploads.delete(request),
        () => pendingUploads.delete(request),
      );

      return request;
    },

    handleImageUploadError(error) {
      this.imageUploadError = error?.message || 'Das Bild konnte nicht eingefügt werden.';
    },

    openLinkMenu(link) {
      this.activeLinkHref = link.href;
      this.activeLinkPosition = link.position;
      this.linkMenuStyle = `left: ${Math.max(8, link.left)}px; top: ${Math.max(8, link.top)}px;`;
      this.linkMenuOpen = true;
    },

    closeLinkMenu() {
      this.linkMenuOpen = false;
    },

    openActiveLink() {
      if (!this.activeLinkHref) {
        return;
      }
      const opened = window.open(this.activeLinkHref, '_blank', 'noopener,noreferrer');
      if (opened) {
        opened.opener = null;
      }
      this.closeLinkMenu();
    },

    editActiveLink() {
      if (!this.canEditPage || !editor) {
        return;
      }
      editor.chain()
        .focus()
        .setTextSelection(this.activeLinkPosition)
        .extendMarkRange('link')
        .run();
      this.closeLinkMenu();
      this.toggleLink();
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

    runEditorCommand(command) {
      if (!this.canEditPage || !editor || !editor.isEditable) {
        return;
      }
      const chain = editor.chain().focus();
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

    toggleBold() {
      this.runEditorCommand('bold');
    },

    toggleItalic() {
      this.runEditorCommand('italic');
    },

    toggleStrike() {
      this.runEditorCommand('strike');
    },

    toggleHeading1() {
      this.runEditorCommand('heading1');
    },

    toggleHeading2() {
      this.runEditorCommand('heading2');
    },

    toggleBulletList() {
      this.runEditorCommand('bulletList');
    },

    toggleBlockquote() {
      this.runEditorCommand('blockquote');
    },

    editLink() {
      this.runEditorCommand('link');
    },

    undo() {
      this.runEditorCommand('undo');
    },

    redo() {
      this.runEditorCommand('redo');
    },

    syncToolbar() {
      const toolbar = this.$refs.toolbar;
      if (!editor || !toolbar) {
        return;
      }
      for (const button of toolbar.querySelectorAll('[data-editor-command]')) {
        const command = button.dataset.editorCommand;
        const active = {
          bold: editor.isActive('bold'),
          italic: editor.isActive('italic'),
          strike: editor.isActive('strike'),
          heading1: editor.isActive('heading', { level: 1 }),
          heading2: editor.isActive('heading', { level: 2 }),
          bulletList: editor.isActive('bulletList'),
          blockquote: editor.isActive('blockquote'),
          link: editor.isActive('link'),
        }[command] ?? false;
        button.classList.toggle('is-active', active);
      }
    },

    toggleLink() {
      if (!editor) {
        return;
      }
      if (editor.isActive('link')) {
        editor.chain().extendMarkRange('link').run();
      }
      const current = editor.getAttributes('link').href || '';
      const href = prompt('Link-Adresse', current);
      if (href === null) {
        return;
      }
      if (href.trim() === '') {
        editor.chain().focus().unsetLink().run();
        return;
      }
      editor.chain().focus().setLink({ href: href.trim() }).run();
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

    destroy() {
      if (this.visibilityHandler) {
        document.removeEventListener('visibilitychange', this.visibilityHandler);
      }
      if (this.beforeUnloadHandler) {
        window.removeEventListener('beforeunload', this.beforeUnloadHandler);
      }
      if (this.keyDownHandler) {
        window.removeEventListener('keydown', this.keyDownHandler);
      }
      if (window.__prepareWorkspaceNavigation === this.navigationHandler) {
        delete window.__prepareWorkspaceNavigation;
      }
      clearTimeout(this.saveTimer);
      editor?.destroy();
      editor = null;
    },
  };
}
