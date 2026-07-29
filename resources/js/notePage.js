import { apiFetch } from './api.js';
import { createEditor } from './editor/index.js';
import { consumeNewPageTitleEdit } from './newPageTitle.js';
import { sanitizeNoteDoc } from './editor/sanitize.js';
import { voiceFormData, voiceRecorderMixin } from './voice.js';
import {
  isLocationSupported,
  locationLabel,
  locationMapUrl,
  parseLocationInput,
  requestLocation,
} from './geo.js';
import {
  acquireNoteEditLock,
  cacheNoteContent,
  cachePageAttachments,
  isAvailableOffline,
  invalidateCachedImages,
  readCachedPageAttachments,
  hasQueuedNoteChange,
  listBlockedEntries,
  listSyncConflicts,
  readCachedNoteContent,
  resolveConflictKeepLocal,
  resolveConflictUseServer,
  saveOfflineAttachment,
  saveNoteOffline,
  syncOutbox,
} from './offline/runtime.js';

const DEBOUNCE_MS = 1500;
const LOCAL_CACHE_PREFIX = 'notes-note-cache-';

export function noteEditorPage() {
  // ProseMirror keeps mutable transaction state and must not be made reactive by Alpine.
  let editor = null;
  const pendingUploads = new Set();

  return {
    ...voiceRecorderMixin(),
    status: 'loading', // loading | saved | saving | unsaved | offline | invalid | conflict
    version: 0,
    pageId: null,
    canEditPage: Boolean(window.__CURRENT_PAGE_CAN_EDIT__),
    saveTimer: null,
    retryDelay: 1000,
    pendingSave: false,
    saveRequested: false,
    editorRevision: 0,
    queuedRevision: 0,
    editLockedElsewhere: false,
    releaseEditLock: null,
    conflictContent: null,
    offlineConflictId: null,
    pageTitle: '',
    savedPageTitle: '',
    editingPageTitle: false,
    savingPageTitle: false,
    updatedAt: null,
    lastEditorName: null,
    pageLocation: null,
    pageIsShared: Boolean(window.__CURRENT_PAGE_IS_SHARED__),
    locationSupported: isLocationSupported(),
    locationDialogOpen: false,
    locationInput: '',
    locationBusy: false,
    locationError: '',
    visibilityHandler: null,
    beforeUnloadHandler: null,
    keyDownHandler: null,
    navigationHandler: null,
    syncHandler: null,
    pendingImageUploads: 0,
    imageUploadError: '',
    saveError: '',
    linkMenuOpen: false,
    activeLinkHref: '',
    activeLinkPosition: 0,
    linkMenuStyle: '',
    imageViewerSrc: '',
    imageViewerAlt: '',
    imageViewerScale: 1,
    imageViewerPanX: 0,
    imageViewerPanY: 0,
    imageViewerTouch: null,
    imageViewerLastTap: 0,
    imageViewerHandlers: null,
    attachments: [],
    attachmentError: '',
    uploadingAttachment: false,
    maxAttachmentMb: 10,
    isOnline: typeof navigator === 'undefined' ? true : navigator.onLine,
    statusHandler: null,
    pdfViewerUrl: '',
    pdfViewerName: '',
    inTable: false,
    historyOpen: false,
    historyLoading: false,
    historyError: '',
    historyVersions: [],
    canRestoreVersions: !Boolean(window.__CURRENT_PAGE_IS_SHARED__),
    selectedVersionId: null,
    selectedVersion: null,
    selectedVersionLoading: false,
    restoringVersion: false,
    compressionOpen: false,
    compressionQuality: 82,
    compressionSize: 'screen',
    compressionBusy: false,
    compressionError: '',
    compressionResult: null,

    async init() {
      const pageRoot = this.$root;
      this.pageId = Number(pageRoot?.dataset.pageId || window.__CURRENT_PAGE_ID__ || 0);
      this.pageTitle = pageRoot?.dataset.pageTitle || window.__CURRENT_PAGE_TITLE__ || '';
      this.canEditPage = pageRoot?.dataset.pageCanEdit
        ? pageRoot.dataset.pageCanEdit === '1'
        : Boolean(window.__CURRENT_PAGE_CAN_EDIT__);
      const isShared = pageRoot?.dataset.pageIsShared === '1'
        || Boolean(window.__CURRENT_PAGE_IS_SHARED__);
      // Eigene Kopie: `isShared` im Markup gehört der übergeordneten
      // pageShare-Komponente und steht dieser hier nicht zur Verfügung.
      this.pageIsShared = isShared;
      this.canRestoreVersions = this.canEditPage && !isShared;
      this.savedPageTitle = this.pageTitle;
      this.pageLocation = this.readLocationFrom(pageRoot);

      if (!this.pageId) {
        this.status = 'offline';
        return;
      }

      if (this.canEditPage) {
        this.releaseEditLock = await acquireNoteEditLock(this.pageId);
        if (!this.releaseEditLock) {
          this.canEditPage = false;
          this.editLockedElsewhere = true;
        }
      }
      this.canRestoreVersions = this.canEditPage && !isShared;

      let initial;
      let fromOffline = false;
      const localDraft = this.readLocalCache();
      const idb = await readCachedNoteContent(this.pageId);
      if (localDraft?.content) {
        initial = {
          content: localDraft.content,
          version: Number(localDraft.version || idb?.version || 1),
          updated_at: idb?.updated_at || null,
          last_editor_name: idb?.last_editor_name || null,
        };
        this.editorRevision = Number(localDraft.revision || 1);
        try {
          await saveNoteOffline(this.pageId, initial.content, initial.version);
          this.queuedRevision = this.editorRevision;
        } catch {
          /* localStorage remains the recovery source */
        }
        fromOffline = true;
      } else if (idb?.dirty) {
        initial = {
          content: idb.content,
          version: idb.version,
          updated_at: idb.updated_at,
          last_editor_name: idb.last_editor_name,
        };
        this.editorRevision = Number(idb.local_revision || 1);
        this.queuedRevision = this.editorRevision;
        fromOffline = true;
      } else {
        try {
          initial = await apiFetch(`/api/pages/${this.pageId}/content`);
          await cacheNoteContent(this.pageId, initial);
        } catch (e) {
          const cached = this.readLocalCache();
          if (idb && (!cached || Number(idb.version) >= Number(cached.version))) {
            initial = {
              content: idb.content,
              version: idb.version,
              updated_at: idb.updated_at,
              last_editor_name: idb.last_editor_name,
            };
            fromOffline = true;
          } else if (cached) {
            initial = { content: cached.content, version: cached.version };
            fromOffline = true;
          } else {
            initial = { content: { type: 'doc', content: [] }, version: 1 };
            fromOffline = true;
          }
        }
      }

      this.version = initial.version;
      this.updatedAt = initial.updated_at;
      this.lastEditorName = initial.last_editor_name;
      if (fromOffline) {
        this.status = navigator.onLine ? 'saving' : 'offline';
      }

      // Ein Entwurf aus der Zeit vor der Einfüge-Bereinigung kann Knoten
      // enthalten, die der Server ablehnt - dann wäre die Notiz dauerhaft
      // unspeicherbar. Der geputzte Stand wird gleich in die Queue gestellt.
      const sanitized = sanitizeNoteDoc(initial.content);
      initial.content = sanitized.doc;

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
      this.bindImageViewer();
      this.syncToolbar();

      // Erst nach dem Aufbau des Editors, damit der Fokus im Titel bleibt.
      if (this.canEditPage && consumeNewPageTitleEdit(this.pageId)) {
        this.startEditingPageTitle(true);
      }

      const existingConflict = (await listSyncConflicts())
        .find((conflict) => Number(conflict.page_id) === this.pageId);
      if (existingConflict) {
        this.status = 'conflict';
        this.offlineConflictId = existingConflict.id;
        this.conflictContent = {
          content: existingConflict.server_content,
          version: existingConflict.server_version,
        };
      }

      if (!fromOffline && this.status !== 'conflict') {
        this.status = 'saved';
      }

      // Nach dem Setzen des Anfangszustands, damit der bereinigte Stand als
      // ungespeicherte Änderung stehen bleibt und in die Queue wandert.
      if (sanitized.changed && this.canEditPage && this.status !== 'conflict') {
        this.onChange(editor.getJSON());
      }

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
      this.syncHandler = (event) => {
        const detail = event.detail || {};
        if (Number(detail.pageId) !== this.pageId) {
          return;
        }
        if (detail.action === 'conflict') {
          this.status = 'conflict';
          this.offlineConflictId = Number(detail.outboxId);
          this.conflictContent = detail.result;
          return;
        }
        if (!detail.result) {
          return;
        }
        this.version = Number(detail.result.version);
        this.updatedAt = detail.result.updated_at;
        this.lastEditorName = detail.result.last_editor_name;
        this.offlineConflictId = null;
        this.conflictContent = null;
        if (detail.result.content && editor) {
          const current = JSON.stringify(editor.getJSON());
          const synced = JSON.stringify(detail.result.content);
          const source = detail.sourceContent ? JSON.stringify(detail.sourceContent) : null;
          const mustApplyServer = detail.action === 'resolved-server';
          const currentMatchesSubmitted = source !== null && current === source;
          const acknowledgedCurrent = mustApplyServer || currentMatchesSubmitted || current === synced;
          if (current !== synced && acknowledgedCurrent) {
            editor.commands.setContent(detail.result.content, { emitUpdate: false });
          }
          if (acknowledgedCurrent) {
            this.status = 'saved';
            this.queuedRevision = this.editorRevision;
            this.clearLocalCache();
          } else if (current !== synced) {
            this.status = 'unsaved';
            clearTimeout(this.saveTimer);
            const saveWhenReady = () => {
              if (this.pendingSave) {
                this.saveTimer = setTimeout(saveWhenReady, 100);
                return;
              }
              void this.saveNow();
            };
            this.saveTimer = setTimeout(saveWhenReady, 100);
          }
        }
      };
      window.addEventListener('offline-note-sync', this.syncHandler);
      if (navigator.onLine && await hasQueuedNoteChange(this.pageId)) {
        void syncOutbox();
      }
      this.navigationHandler = async () => {
        await Promise.allSettled(Array.from(pendingUploads));
        if (this.editorRevision > this.queuedRevision) {
          await this.saveNow();
        }
      };
      window.__prepareWorkspaceNavigation = this.navigationHandler;
      // Die Offline-Runtime meldet Verbindungswechsel; bei Rückkehr ins Netz
      // wird die Anhangliste neu geladen, damit die Kennzeichnung stimmt.
      this.statusHandler = (event) => {
        const wasOnline = this.isOnline;
        this.isOnline = event.detail?.online ?? navigator.onLine;
        if (!wasOnline && this.isOnline) {
          void this.loadAttachments();
        }
      };
      window.addEventListener('offline-status', this.statusHandler);
      void this.loadAttachments();
    },

    openCompressionDialog() {
      this.compressionQuality = 82;
      this.compressionSize = 'screen';
      this.compressionError = '';
      this.compressionResult = null;
      this.compressionOpen = true;
    },

    closeCompressionDialog() {
      if (!this.compressionBusy) {
        this.compressionOpen = false;
      }
    },

    async compressImages() {
      if (!this.canEditPage || !this.isOnline || this.compressionBusy) {
        this.compressionError = 'Die Bildkompression benötigt eine Online-Verbindung.';
        return;
      }
      if (this.pendingImageUploads > 0) {
        this.compressionError = 'Bitte warte, bis alle Bild-Uploads abgeschlossen sind.';
        return;
      }

      this.compressionBusy = true;
      this.compressionError = '';
      try {
        this.compressionResult = await apiFetch(`/api/pages/${this.pageId}/attachments/compress`, {
          method: 'POST',
          body: JSON.stringify({
            quality: Number(this.compressionQuality),
            size: this.compressionSize,
          }),
        });
        await invalidateCachedImages(this.pageImageUrls());
        window.dispatchEvent(new Event('pages-changed'));
      } catch (error) {
        this.compressionError = error.message || 'Die Bilder konnten nicht komprimiert werden.';
      } finally {
        this.compressionBusy = false;
      }
    },

    pageImageUrls() {
      if (!editor) {
        return [];
      }
      const urls = [];
      const visit = (node) => {
        const src = node?.attrs?.src;
        if (node?.type === 'image' && typeof src === 'string' && src.startsWith('/api/attachments/')) {
          urls.push(src);
        }
        if (Array.isArray(node?.content)) {
          node.content.forEach(visit);
        }
      };
      visit(editor.getJSON());

      return [...new Set(urls)];
    },

    compressionBytes(bytes) {
      const value = Number(bytes || 0);
      if (value >= 1024 * 1024) {
        return `${(value / (1024 * 1024)).toFixed(1)} MB`;
      }
      return `${Math.max(0, Math.round(value / 1024))} KB`;
    },

    compressionResultLabel() {
      if (!this.compressionResult) {
        return '';
      }
      return `${this.compressionResult.compressed} von ${this.compressionResult.images} Bild(ern) verarbeitet · `
        + `${this.compressionBytes(this.compressionResult.saved_bytes)} eingespart`;
    },

    /**
     * Alpine wertet auch die Direktiven innerhalb eines ausgeblendeten `x-show`
     * aus - ein direkter Zugriff auf `compressionResult` schlüge dort fehl,
     * solange noch kein Ergebnis vorliegt.
     */
    compressionSkippedCount() {
      return this.compressionResult?.skipped || 0;
    },

    finishCompression() {
      window.location.reload();
    },

    onChange(json) {
      this.editorRevision += 1;
      this.status = 'unsaved';
      this.writeLocalCache(json);

      clearTimeout(this.saveTimer);
      this.saveTimer = setTimeout(() => this.saveNow(), DEBOUNCE_MS);
    },

    async saveNow(options = {}) {
      if (!editor || this.pendingSave) {
        if (this.pendingSave) {
          this.saveRequested = true;
        }
        return;
      }
      clearTimeout(this.saveTimer);

      const content = editor.getJSON();
      const savingRevision = this.editorRevision;
      this.pendingSave = true;
      this.saveRequested = false;
      this.status = 'saving';
      this.saveError = '';

      try {
        await saveNoteOffline(this.pageId, content, this.version, options);
        this.queuedRevision = Math.max(this.queuedRevision, savingRevision);
        this.status = navigator.onLine ? 'saving' : 'offline';

        if (navigator.onLine) {
          await syncOutbox();
          const conflict = (await listSyncConflicts())
            .find((item) => Number(item.page_id) === this.pageId);
          if (conflict) {
            this.status = 'conflict';
            this.offlineConflictId = conflict.id;
            this.conflictContent = {
              content: conflict.server_content,
              version: conflict.server_version,
            };
          } else if (!(await hasQueuedNoteChange(this.pageId))) {
            const synced = await readCachedNoteContent(this.pageId);
            if (synced) {
              this.version = Number(synced.version);
              this.updatedAt = synced.updated_at;
              this.lastEditorName = synced.last_editor_name;
              if (this.editorRevision === savingRevision) {
                this.status = 'saved';
                this.clearLocalCache();
              }
            }
          } else {
            // Der Server hat den Inhalt endgültig abgelehnt (z. B. ein nicht
            // erlaubter Knoten). Ohne diesen Zweig bliebe die Anzeige auf
            // „Speichern…" stehen, obwohl nichts mehr passiert.
            const blocked = (await listBlockedEntries())
              .find((entry) => Number(entry.page_id) === this.pageId);
            if (blocked) {
              this.status = 'invalid';
              this.saveError = `${blocked.last_error} Der Inhalt liegt weiter lokal vor - über die Seitenleiste (Offline-Inhalte) lässt sich der Sync erneut versuchen oder verwerfen.`;
            }
          }
        }
      } catch (e) {
        this.status = 'invalid';
        this.saveError = e.message || 'Der Notizinhalt konnte nicht lokal gespeichert werden.';
      }

      this.pendingSave = false;
      if (this.saveRequested || this.editorRevision > savingRevision) {
        this.status = 'unsaved';
        clearTimeout(this.saveTimer);
        this.saveTimer = setTimeout(() => this.saveNow(), 100);
      }
    },

    async keepMyVersion() {
      if (this.offlineConflictId) {
        this.pendingSave = true;
        this.status = 'saving';
        editor?.setEditable(false);
        try {
          const content = editor?.getJSON();
          if (content) {
            await saveNoteOffline(this.pageId, content, this.version, { forceSnapshot: true });
            this.queuedRevision = this.editorRevision;
          }
          await resolveConflictKeepLocal(this.offlineConflictId);
        } catch (error) {
          this.status = 'conflict';
          this.saveError = error.message || 'Der Konflikt konnte nicht aufgelöst werden.';
        } finally {
          this.pendingSave = false;
          editor?.setEditable(this.canEditPage);
        }
        return;
      }
      this.status = 'unsaved';
      this.conflictContent = null;
      await this.saveNow({ forceSnapshot: true });
    },

    async useServerVersion() {
      if (!this.offlineConflictId) {
        return;
      }
      this.pendingSave = true;
      editor?.setEditable(false);
      try {
        await resolveConflictUseServer(this.offlineConflictId);
      } catch (error) {
        this.status = 'conflict';
        this.saveError = error.message || 'Die Serverfassung konnte nicht übernommen werden.';
      } finally {
        this.pendingSave = false;
        editor?.setEditable(this.canEditPage);
      }
    },

    async openHistory() {
      this.historyOpen = true;
      this.historyError = '';
      this.selectedVersionId = null;
      this.selectedVersion = null;
      this.historyLoading = true;
      try {
        const data = await apiFetch(`/api/pages/${this.pageId}/versions`);
        this.historyVersions = data.versions || [];
        this.canRestoreVersions = this.canEditPage && data.can_restore === true;
      } catch (e) {
        this.historyError = e.message || 'Der Versionsverlauf konnte nicht geladen werden.';
        this.historyVersions = [];
      } finally {
        this.historyLoading = false;
      }
    },

    closeHistory() {
      if (this.restoringVersion) {
        return;
      }
      this.historyOpen = false;
      this.selectedVersionId = null;
      this.selectedVersion = null;
      this.historyError = '';
    },

    async selectVersion(versionId) {
      this.selectedVersionId = versionId;
      this.selectedVersion = null;
      this.selectedVersionLoading = true;
      this.historyError = '';
      try {
        this.selectedVersion = await apiFetch(`/api/pages/${this.pageId}/versions/${versionId}`);
      } catch (e) {
        this.historyError = e.message || 'Die Version konnte nicht geladen werden.';
      } finally {
        this.selectedVersionLoading = false;
      }
    },

    versionPreviewText() {
      if (!this.selectedVersion?.content) {
        return '';
      }
      return this.extractPreview(this.selectedVersion.content);
    },

    extractPreview(doc) {
      const parts = [];
      const walk = (node) => {
        if (!node || typeof node !== 'object') {
          return;
        }
        if (node.type === 'text' && typeof node.text === 'string') {
          parts.push(node.text);
        }
        if (Array.isArray(node.content)) {
          for (const child of node.content) {
            walk(child);
          }
          if (['paragraph', 'heading', 'listItem', 'taskItem', 'blockquote'].includes(node.type)) {
            parts.push('\n');
          }
        }
      };
      walk(doc);
      return parts.join('').replace(/\n{3,}/g, '\n\n').trim() || '(leerer Inhalt)';
    },

    versionLabel(version) {
      if (!version?.created_at) {
        return '';
      }
      const date = new Intl.DateTimeFormat('de-DE', {
        dateStyle: 'medium',
        timeStyle: 'short',
      }).format(new Date(version.created_at));
      return version.created_by_name ? `${date} · ${version.created_by_name}` : date;
    },

    isSelectedVersion(versionId) {
      return this.selectedVersionId === versionId;
    },

    async ensureRestoreReady() {
      if (!navigator.onLine) {
        throw new Error('Die Wiederherstellung benötigt eine Internetverbindung.');
      }
      if (this.pendingSave) {
        throw new Error('Ein Speichervorgang läuft noch. Bitte warte kurz und versuche es erneut.');
      }
      if (this.status === 'conflict') {
        throw new Error('Löse zuerst den bestehenden Versionskonflikt auf.');
      }
      if (this.status === 'invalid') {
        throw new Error('Löse zuerst den fehlgeschlagenen Speichervorgang auf.');
      }
      if (this.status === 'unsaved') {
        await this.saveNow();
      }
      if (this.pendingSave) {
        throw new Error('Der aktuelle Inhalt wird noch gespeichert. Bitte versuche es gleich erneut.');
      }
      if (this.status === 'conflict') {
        throw new Error('Der aktuelle Inhalt hat einen Versionskonflikt. Löse ihn vor der Wiederherstellung auf.');
      }
      if (this.status === 'invalid') {
        throw new Error('Der aktuelle Inhalt konnte nicht gespeichert werden. Löse das Problem vor der Wiederherstellung auf.');
      }
      if (!(await hasQueuedNoteChange(this.pageId))) {
        return;
      }

      await syncOutbox();
      const conflict = (await listSyncConflicts())
        .find((item) => Number(item.page_id) === this.pageId);
      if (conflict) {
        this.status = 'conflict';
        this.offlineConflictId = conflict.id;
        this.conflictContent = {
          content: conflict.server_content,
          version: conflict.server_version,
        };
        throw new Error('Der aktuelle Inhalt hat einen Versionskonflikt. Löse ihn vor der Wiederherstellung auf.');
      }

      const blocked = (await listBlockedEntries())
        .find((item) => Number(item.page_id) === this.pageId);
      if (blocked) {
        this.status = 'invalid';
        this.saveError = blocked.last_error;
        throw new Error('Der aktuelle Inhalt konnte nicht synchronisiert werden. Löse das Problem vor der Wiederherstellung auf.');
      }
      if (await hasQueuedNoteChange(this.pageId)) {
        throw new Error('Der aktuelle Inhalt wurde noch nicht synchronisiert. Bitte versuche es gleich erneut.');
      }
    },

    async restoreSelectedVersion() {
      if (!this.canRestoreVersions || !this.selectedVersionId || this.restoringVersion) {
        return;
      }
      if (!window.confirm('Aktuellen Inhalt durch diese Version ersetzen? Der aktuelle Stand wird als Snapshot gesichert.')) {
        return;
      }

      this.restoringVersion = true;
      this.historyError = '';
      editor?.setEditable(false);
      try {
        await this.ensureRestoreReady();
        const result = await apiFetch(`/api/pages/${this.pageId}/versions/${this.selectedVersionId}/restore`, {
          method: 'POST',
          body: JSON.stringify({ version: this.version }),
        });
        this.version = result.version;
        this.updatedAt = result.updated_at;
        this.lastEditorName = result.last_editor_name;
        editor?.commands.setContent(result.content, { emitUpdate: false });
        this.status = 'saved';
        try {
          await cacheNoteContent(this.pageId, { ...result, dirty: false });
        } catch {
          /* Der nächste Abruf holt den wiederhergestellten Stand nach. */
        }
        this.clearLocalCache();
        this.closeHistory();
      } catch (e) {
        const current = e?.payload?.current;
        if (e?.status === 409 && current?.content) {
          this.version = Number(current.version);
          this.updatedAt = current.updated_at;
          this.lastEditorName = current.last_editor_name;
          editor?.commands.setContent(current.content, { emitUpdate: false });
          this.status = 'saved';
          try {
            await cacheNoteContent(this.pageId, { ...current, dirty: false });
          } catch {
            /* Der nächste Abruf holt den aktuellen Stand nach. */
          }
          this.clearLocalCache();
          this.historyError = 'Die Notiz wurde zwischenzeitlich geändert. Der aktuelle Serverstand wurde geladen.';
        } else {
          this.historyError = e?.message || 'Die Version konnte nicht wiederhergestellt werden.';
        }
      } finally {
        this.restoringVersion = false;
        editor?.setEditable(this.canEditPage);
      }
    },

    /**
     * Aufnahmeort aus dem servergerenderten Markup (FR-NOTE-25). Fehlt eine der
     * Koordinaten, gilt die Notiz als ohne Ort angelegt.
     */
    readLocationFrom(pageRoot) {
      const lat = Number.parseFloat(pageRoot?.dataset.pageLat ?? '');
      const lon = Number.parseFloat(pageRoot?.dataset.pageLon ?? '');
      if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
        return null;
      }
      const accuracy = Number.parseFloat(pageRoot?.dataset.pageAccuracy ?? '');

      return {
        lat,
        lon,
        accuracy: Number.isFinite(accuracy) ? accuracy : null,
        label: pageRoot?.dataset.pageAddress || null,
      };
    },

    locationLabel() {
      return locationLabel(this.pageLocation);
    },

    locationMapUrl() {
      return locationMapUrl(this.pageLocation);
    },

    /** Der Ort gehört zur Seite, nicht zum Inhalt - Freigaben ändern ihn nicht. */
    canEditLocation() {
      return this.canEditPage && !this.pageIsShared;
    },

    /**
     * Der Ort wird auf Klick gesetzt (FR-NOTE-25). Der Dialog bietet den
     * aktuellen Standort und - um ihn auf einen anderen zu verschieben - die
     * Eingabe von Koordinaten oder eines kopierten Kartenlinks.
     */
    openLocationDialog() {
      if (!this.canEditPage || this.pageIsShared) {
        return;
      }
      this.locationError = '';
      this.locationInput = this.pageLocation
        ? `${this.pageLocation.lat}, ${this.pageLocation.lon}`
        : '';
      this.locationDialogOpen = true;
    },

    closeLocationDialog() {
      this.locationDialogOpen = false;
      this.locationBusy = false;
      this.locationError = '';
    },

    async useCurrentLocation() {
      if (!this.locationSupported) {
        this.locationError = 'Dieser Browser bietet keine Ortung an.';
        return;
      }

      this.locationBusy = true;
      this.locationError = '';
      try {
        const location = await requestLocation();
        if (!location) {
          this.locationError = 'Der Standort konnte nicht ermittelt werden. '
            + 'Vielleicht ist die Ortung abgelehnt oder gerade kein Signal da.';
          return;
        }
        await this.saveLocation(location);
      } finally {
        this.locationBusy = false;
      }
    },

    async applyLocationInput() {
      const location = parseLocationInput(this.locationInput);
      if (!location) {
        this.locationError = 'Bitte Koordinaten wie „48.7758, 9.1829" oder einen Kartenlink einfügen.';
        return;
      }

      this.locationBusy = true;
      try {
        await this.saveLocation(location);
      } finally {
        this.locationBusy = false;
      }
    },

    async removeLocation() {
      this.locationBusy = true;
      try {
        await this.saveLocation(null);
      } finally {
        this.locationBusy = false;
      }
    },

    async saveLocation(location) {
      if (!navigator.onLine) {
        this.locationError = 'Der Standort kann nur mit Internetverbindung geändert werden.';
        return;
      }

      this.locationError = '';
      try {
        const page = await apiFetch(`/api/pages/${this.pageId}`, {
          method: 'PATCH',
          body: JSON.stringify({ location }),
        });
        this.pageLocation = page.location || null;
        this.closeLocationDialog();
      } catch (error) {
        this.locationError = error.message || 'Der Standort konnte nicht gespeichert werden.';
      }
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
        offline: 'Lokal gespeichert – Sync bei Verbindung…',
        invalid: 'Speichern fehlgeschlagen',
        conflict: 'Konflikt: Version wurde anderswo geändert',
      }[this.status];
    },

    uploadImage(file) {
      this.imageUploadError = '';
      const request = (async () => {
        if (navigator.onLine) {
          try {
            const body = new FormData();
            body.append('file', file, file.name || 'screenshot.png');
            return await apiFetch(`/api/pages/${this.pageId}/attachments`, {
              method: 'POST',
              body,
            });
          } catch (error) {
            if (error.status) {
              throw error;
            }
          }
        }
        return saveOfflineAttachment(file, this.pageId);
      })();
      pendingUploads.add(request);
      request.then(
        () => pendingUploads.delete(request),
        () => pendingUploads.delete(request),
      );

      return request;
    },

    /**
     * Diktat in die offene Notiz: Der aufbereitete Text wird an der
     * Einfügemarke eingesetzt (FR-VOICE-02). Gespeichert wird er wie jede
     * andere Änderung über den Autosave.
     */
    async handleVoiceRecording(recording) {
      if (!this.canEditPage || !editor) {
        throw new Error('Diese Notiz ist schreibgeschützt.');
      }

      const data = await apiFetch('/api/voice/transcribe', {
        method: 'POST',
        body: voiceFormData(recording),
      });

      const nodes = Array.isArray(data.document?.content) ? data.document.content : [];
      if (nodes.length === 0) {
        throw new Error('In der Aufnahme wurde kein Text erkannt.');
      }

      editor.chain().focus().insertContent(nodes).run();
      this.applyVoiceTitle(data.title);
    },

    /**
     * Die aus der Aufnahme gebildete Überschrift überschreibt nur den
     * Vorschlagstitel einer noch unbenannten Notiz - selbst vergebene Titel
     * bleiben unangetastet (FR-VOICE-03).
     */
    applyVoiceTitle(title) {
      const suggestion = String(title || '').trim();
      const current = String(this.pageTitle || '').trim();
      if (suggestion === '' || (current !== '' && current !== 'Neue Notiz')) {
        return;
      }

      this.pageTitle = suggestion;
      void this.savePageTitle();
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

    /**
     * Nur auf Handy-Breite: dort öffnet ein Tipp auf ein Bild die Vollbild-
     * Ansicht. Auf dem Desktop bleibt der Klick beim Auswählen des Bildknotens,
     * damit die Größe weiterhin gezogen werden kann.
     */
    imageAtEvent(event) {
      if (!window.matchMedia('(max-width: 767px)').matches) {
        return null;
      }
      const target = event.target instanceof Element ? event.target.closest('img') : null;

      return target instanceof HTMLImageElement ? target : null;
    },

    /**
     * In der Capture-Phase am Editor-Container, damit ProseMirror den Klick gar
     * nicht erst sieht: sonst würde der Bildknoten ausgewählt, die Skaliergriffe
     * erschienen unter der Vollbildebene und die Tastatur klappte auf.
     */
    bindImageViewer() {
      const container = this.$refs.editor;
      if (!container || this.imageViewerHandlers) {
        return;
      }

      const swallow = (event) => {
        if (this.imageAtEvent(event)) {
          event.preventDefault();
          event.stopPropagation();
        }
      };
      const open = (event) => {
        const image = this.imageAtEvent(event);
        if (!image) {
          return;
        }
        event.preventDefault();
        event.stopPropagation();
        this.openImageViewer(image.currentSrc || image.src, image.alt);
      };

      container.addEventListener('mousedown', swallow, true);
      container.addEventListener('click', open, true);
      this.imageViewerHandlers = { container, swallow, open };
    },

    openImageViewer(src, alt) {
      if (!src) {
        return;
      }
      this.imageViewerSrc = src;
      this.imageViewerAlt = alt || '';
      this.resetImageZoom();
    },

    closeImageViewer() {
      this.imageViewerSrc = '';
      this.imageViewerAlt = '';
      this.resetImageZoom();
    },

    resetImageZoom() {
      this.imageViewerScale = 1;
      this.imageViewerPanX = 0;
      this.imageViewerPanY = 0;
      this.imageViewerTouch = null;
    },

    imageViewerStyle() {
      return `transform: translate(${this.imageViewerPanX}px, ${this.imageViewerPanY}px) scale(${this.imageViewerScale});`;
    },

    toggleImageZoom() {
      if (this.imageViewerScale > 1) {
        this.resetImageZoom();
      } else {
        this.imageViewerScale = 2;
      }
    },

    imageViewerTouchStart(event) {
      event.stopPropagation();
      const touches = event.touches;
      if (touches.length === 2) {
        const [first, second] = touches;
        this.imageViewerTouch = {
          kind: 'pinch',
          distance: Math.hypot(second.clientX - first.clientX, second.clientY - first.clientY),
          scale: this.imageViewerScale,
        };
        return;
      }
      if (touches.length === 1) {
        const touch = touches[0];
        this.imageViewerTouch = {
          kind: 'pan',
          x: touch.clientX,
          y: touch.clientY,
          panX: this.imageViewerPanX,
          panY: this.imageViewerPanY,
        };
      }
    },

    imageViewerTouchMove(event) {
      const state = this.imageViewerTouch;
      if (!state) {
        return;
      }
      event.preventDefault();
      event.stopPropagation();
      const touches = event.touches;
      if (state.kind === 'pinch' && touches.length === 2) {
        const [first, second] = touches;
        const distance = Math.hypot(second.clientX - first.clientX, second.clientY - first.clientY);
        this.imageViewerScale = Math.min(4, Math.max(1, state.scale * (distance / state.distance)));
      } else if (state.kind === 'pan' && touches.length === 1 && this.imageViewerScale > 1) {
        this.imageViewerPanX = state.panX + touches[0].clientX - state.x;
        this.imageViewerPanY = state.panY + touches[0].clientY - state.y;
      }
    },

    imageViewerTouchEnd(event) {
      event.stopPropagation();
      if (event.touches.length > 0) {
        return;
      }
      const now = Date.now();
      if (this.imageViewerTouch?.kind === 'pan' && now - this.imageViewerLastTap < 280) {
        this.toggleImageZoom();
        this.imageViewerLastTap = 0;
      } else if (this.imageViewerTouch?.kind === 'pan') {
        this.imageViewerLastTap = now;
      }
      this.imageViewerTouch = null;
      if (this.imageViewerScale <= 1) {
        this.imageViewerPanX = 0;
        this.imageViewerPanY = 0;
      }
    },

    /**
     * Dateianhänge der Seite (FR-NOTE-18). Sie hängen an der Notiz, nicht am
     * Dokumentinhalt, und werden als Badges unter der Überschrift gezeigt.
     * Ohne Netz kommt die Liste aus dem lokalen Cache, damit wenigstens
     * sichtbar bleibt, was an der Notiz hängt (FR-OFFLINE-06).
     */
    async loadAttachments() {
      if (!this.pageId) {
        return;
      }
      if (navigator.onLine) {
        try {
          const data = await apiFetch(`/api/pages/${this.pageId}/files`);
          this.maxAttachmentMb = Number(data.max_attachment_mb || 10);
          await this.persistAttachments(data.attachments || []);

          return;
        } catch (error) {
          // Anhänge sind ergänzend - ohne sie bleibt die Notiz nutzbar.
        }
      }
      await this.applyAttachments(await readCachedPageAttachments(this.pageId));
    },

    /**
     * Schreibt die Liste in den Offline-Cache; Dateien bis zum Admin-Limit
     * werden dabei mitgeladen (FR-OFFLINE-06).
     *
     * @param {Record<string, unknown>[]} attachments
     */
    async persistAttachments(attachments) {
      const plain = attachments.map((attachment) => {
        const copy = { ...attachment };
        delete copy.available_offline;

        return copy;
      });
      await this.applyAttachments(await cachePageAttachments(this.pageId, plain));
    },

    /**
     * Ergänzt jeden Anhang um die Information, ob er lokal vorliegt. Große
     * Anhänge bleiben in der Liste, lassen sich aber nur mit Verbindung öffnen.
     *
     * @param {Record<string, unknown>[]} attachments
     */
    async applyAttachments(attachments) {
      const list = Array.isArray(attachments) ? attachments : [];
      this.attachments = await Promise.all(list.map(async (attachment) => ({
        ...attachment,
        available_offline: await isAvailableOffline(attachment.url),
      })));
    },

    /** Nur bei fehlender Verbindung relevant - online ist jeder Anhang erreichbar. */
    needsConnection(attachment) {
      return !this.isOnline && attachment.available_offline !== true;
    },

    pickAttachment() {
      this.$refs.attachmentInput?.click();
    },

    async uploadAttachment(event) {
      const input = event.target;
      const files = Array.from(input?.files || []);
      if (input) {
        input.value = '';
      }
      if (files.length === 0 || !this.canEditPage) {
        return;
      }
      if (!navigator.onLine) {
        this.attachmentError = 'Anhänge können nur online hochgeladen werden.';
        return;
      }

      this.uploadingAttachment = true;
      this.attachmentError = '';
      const uploaded = [...this.attachments];
      try {
        for (const file of files) {
          const body = new FormData();
          body.append('file', file, file.name);
          uploaded.push(await apiFetch(`/api/pages/${this.pageId}/files`, { method: 'POST', body }));
        }
      } catch (error) {
        this.attachmentError = error.message || 'Der Anhang konnte nicht hochgeladen werden.';
      } finally {
        // Auch nach einem Abbruch mitten in der Auswahl: Die bereits
        // hochgeladenen Anhänge sollen sichtbar und lokal hinterlegt sein.
        await this.persistAttachments(uploaded);
        this.uploadingAttachment = false;
      }
    },

    async removeAttachment(attachment) {
      if (!this.canEditPage) {
        return;
      }
      if (!this.isOnline) {
        this.attachmentError = 'Anhänge können nur mit Internetverbindung entfernt werden.';
        return;
      }
      if (!window.confirm(`Anhang „${attachment.name}" entfernen?`)) {
        return;
      }
      this.attachmentError = '';
      try {
        await apiFetch(`/api/page-attachments/${attachment.id}`, { method: 'DELETE' });
        await this.persistAttachments(this.attachments.filter((item) => item.id !== attachment.id));
      } catch (error) {
        this.attachmentError = error.message || 'Der Anhang konnte nicht entfernt werden.';
      }
    },

    /**
     * PDFs öffnen in einer überlagerten Ansicht, alles andere lädt der Browser
     * herunter - der Endpunkt setzt das passende Content-Disposition
     * (FR-NOTE-20).
     */
    openAttachment(attachment) {
      // Große Anhänge liegen bewusst nicht lokal (FR-OFFLINE-06) - ohne Netz
      // gäbe es sonst nur eine leere Ansicht bzw. einen abgebrochenen Download.
      if (this.needsConnection(attachment)) {
        this.attachmentError = `„${attachment.name}" (${this.attachmentSize(attachment)}) ist nicht lokal `
          + 'gespeichert. Zum Öffnen ist eine Internetverbindung nötig.';
        return;
      }
      this.attachmentError = '';

      if (attachment.is_pdf) {
        this.pdfViewerUrl = attachment.url;
        this.pdfViewerName = attachment.name;
        // Der Rahmen trägt keine Alpine-Bindungen (siehe Kommentar im Template);
        // Quelle und Titel werden deshalb von Hand gesetzt, sobald die
        // Überlagerung sichtbar ist.
        this.$nextTick(() => {
          const frame = document.getElementById('pdf-viewer-frame');
          if (frame) {
            frame.setAttribute('title', attachment.name);
            frame.setAttribute('src', attachment.url);
          }
        });
        return;
      }

      const link = document.createElement('a');
      link.href = attachment.url;
      link.rel = 'noopener';
      document.body.appendChild(link);
      link.click();
      link.remove();
    },

    closePdfViewer() {
      // Quelle zurücksetzen, damit der Betrachter das Dokument nicht im
      // Hintergrund geladen hält.
      const frame = document.getElementById('pdf-viewer-frame');
      if (frame) {
        frame.setAttribute('src', 'about:blank');
      }
      this.pdfViewerUrl = '';
      this.pdfViewerName = '';
    },

    attachmentSize(attachment) {
      const bytes = Number(attachment.byte_size || 0);

      return bytes >= 1024 * 1024
        ? `${(bytes / (1024 * 1024)).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
    },

    attachmentLabel(attachment) {
      const label = `${attachment.name} · ${this.attachmentSize(attachment)}`;

      return this.needsConnection(attachment)
        ? `${label} · nur mit Internetverbindung`
        : label;
    },

    pickImage() {
      this.$refs.imageInput?.click();
    },

    pickCameraImage() {
      this.$refs.cameraInput?.click();
    },

    /**
     * Datei- bzw. Kameraauswahl aus der Werkzeugleiste. Das Eingabefeld wird
     * geleert, damit dieselbe Datei direkt noch einmal gewählt werden kann.
     * Die Verkleinerung/Kompression übernimmt `insertImageFiles` selbst -
     * derselbe Weg wie Einfügen und Drag & Drop (siehe imageUpload.js).
     */
    insertPickedImage(event) {
      const input = event.target;
      const files = Array.from(input?.files || []);
      if (input) {
        input.value = '';
      }
      if (files.length === 0 || !editor || !this.canEditPage) {
        return;
      }
      this.imageUploadError = '';
      editor.chain().focus().insertImageFiles(files).run();
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
        case 'code':
          chain.toggleCode().run();
          break;
        case 'codeBlock':
          chain.toggleCodeBlock().run();
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
        case 'taskList':
          chain.toggleTaskList().run();
          break;
        case 'blockquote':
          chain.toggleBlockquote().run();
          break;
        case 'table':
          if (editor.isActive('table')) {
            return;
          }
          chain.insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run();
          break;
        case 'tableAddRow':
          chain.addRowAfter().run();
          break;
        case 'tableAddCol':
          chain.addColumnAfter().run();
          break;
        case 'tableDelRow':
          chain.deleteRow().run();
          break;
        case 'tableDelCol':
          chain.deleteColumn().run();
          break;
        case 'tableDelete':
          chain.deleteTable().run();
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

    toggleCode() {
      this.runEditorCommand('code');
    },

    toggleCodeBlock() {
      this.runEditorCommand('codeBlock');
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

    toggleTaskList() {
      this.runEditorCommand('taskList');
    },

    toggleBlockquote() {
      this.runEditorCommand('blockquote');
    },

    insertTable() {
      this.runEditorCommand('table');
    },

    addTableRow() {
      this.runEditorCommand('tableAddRow');
    },

    addTableColumn() {
      this.runEditorCommand('tableAddCol');
    },

    deleteTableRow() {
      this.runEditorCommand('tableDelRow');
    },

    deleteTableColumn() {
      this.runEditorCommand('tableDelCol');
    },

    deleteTable() {
      this.runEditorCommand('tableDelete');
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
      this.inTable = editor.isActive('table');
      for (const button of toolbar.querySelectorAll('[data-editor-command]')) {
        const command = button.dataset.editorCommand;
        const active = {
          bold: editor.isActive('bold'),
          italic: editor.isActive('italic'),
          strike: editor.isActive('strike'),
          code: editor.isActive('code'),
          codeBlock: editor.isActive('codeBlock'),
          heading1: editor.isActive('heading', { level: 1 }),
          heading2: editor.isActive('heading', { level: 2 }),
          bulletList: editor.isActive('bulletList'),
          taskList: editor.isActive('taskList'),
          blockquote: editor.isActive('blockquote'),
          link: editor.isActive('link'),
          table: this.inTable,
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

    writeLocalCache(content, revision = this.editorRevision) {
      if (this.editLockedElsewhere) {
        return;
      }
      try {
        localStorage.setItem(
          LOCAL_CACHE_PREFIX + this.pageId,
          JSON.stringify({
            content,
            version: this.version,
            revision,
            saved_at: Date.now(),
          }),
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
      if (this.editLockedElsewhere) {
        return;
      }
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
      if (this.syncHandler) {
        window.removeEventListener('offline-note-sync', this.syncHandler);
      }
      if (this.statusHandler) {
        window.removeEventListener('offline-status', this.statusHandler);
      }
      if (window.__prepareWorkspaceNavigation === this.navigationHandler) {
        delete window.__prepareWorkspaceNavigation;
      }
      if (this.imageViewerHandlers) {
        const { container, swallow, open } = this.imageViewerHandlers;
        container.removeEventListener('mousedown', swallow, true);
        container.removeEventListener('click', open, true);
        this.imageViewerHandlers = null;
      }
      if (this.releaseEditLock) {
        this.releaseEditLock();
        this.releaseEditLock = null;
      }
      // Ohne das bliebe das Mikrofon nach dem Seitenwechsel aktiv.
      this.cancelVoice();
      clearTimeout(this.saveTimer);
      editor?.destroy();
      editor = null;
    },
  };
}
