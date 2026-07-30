import { apiFetch } from './api.js';
import { decryptEnvelope, encryptDocument, validateEnvelope } from './noteCrypto.js';

const BEGIN_ENDPOINT = '/api/import/archive/parts';
const DEFAULT_CHUNK_SIZE = 1024 * 1024;

function formatBytes(bytes) {
  const value = Number(bytes || 0);
  if (value >= 1024 * 1024) {
    return `${(value / (1024 * 1024)).toFixed(1)} MB`;
  }

  return `${Math.max(1, Math.round(value / 1024))} KB`;
}

/**
 * Import eines Markdown-Archivs aus einem anderen Notizwerkzeug (FR-IMP-19).
 *
 * Das Archiv geht in Teilen zum Server (FR-IMP-25): Jede Anfrage bleibt damit
 * unter `upload_max_filesize` und `post_max_size`, und der Import funktioniert
 * ohne Eingriff in die PHP-Konfiguration. Der Fortschritt ergibt sich aus der
 * Zahl der übertragenen Teile.
 */
export function noteImport() {
  return {
    open: false,
    busy: false,
    progress: 0,
    processing: false,
    fileName: '',
    fileSize: 0,
    fileKind: 'archive',
    encryptionPassword: '',
    error: '',
    report: null,
    detailsOpen: false,
    maxArchiveBytes: 0,
    chunkSize: DEFAULT_CHUNK_SIZE,
    tooLarge: false,
    canceled: false,
    uploadId: null,

    async openDialog() {
      this.open = true;
      this.error = '';
      this.report = null;
      this.fileName = '';
      this.fileSize = 0;
      this.fileKind = 'archive';
      this.encryptionPassword = '';
      this.progress = 0;
      this.detailsOpen = false;
      this.tooLarge = false;
      this.announce(true);
      await this.loadLimits();
    },

    closeDialog() {
      if (this.busy) {
        return;
      }
      this.open = false;
      this.encryptionPassword = '';
      this.announce(false);
      // Nach einem Import erscheinen die neuen Seiten erst nach dem Neuladen
      // der Liste.
      if (this.report) {
        this.$dispatch('pages-changed');
      }
    },

    /**
     * Der Import-Dialog liegt über den Einstellungen. Ohne diese Meldung würde
     * die Escape-Taste beide gleichzeitig schließen.
     *
     * @param {boolean} open
     */
    announce(open) {
      window.dispatchEvent(new CustomEvent('import-dialog', { detail: { open } }));
    },

    /** Archivgrenze und Teilgröße bestimmt der Server. */
    async loadLimits() {
      try {
        const session = await apiFetch('/api/session');
        this.maxArchiveBytes = Number(session?.import?.max_archive_bytes || 0);
        this.chunkSize = Number(session?.import?.chunk_size || DEFAULT_CHUNK_SIZE);
      } catch {
        this.maxArchiveBytes = 0;
        this.chunkSize = DEFAULT_CHUNK_SIZE;
      }
    },

    limitHint() {
      return this.maxArchiveBytes > 0
        ? `Archive bis ${formatBytes(this.maxArchiveBytes)}; große Dateien werden in Teilen übertragen.`
        : '';
    },

    pickFile() {
      this.$refs.archiveInput?.click();
    },

    chooseFile(event) {
      const file = event.target?.files?.[0] || null;
      this.error = '';
      this.report = null;
      this.fileName = file ? file.name : '';
      this.fileSize = file ? file.size : 0;
      this.fileKind = file?.name.toLowerCase().endsWith('.encrypted-note.json') ? 'encrypted' : 'archive';
      this.tooLarge = Boolean(file) && this.maxArchiveBytes > 0 && file.size > this.maxArchiveBytes;

      if (this.tooLarge) {
        this.error = `Das Archiv ist ${formatBytes(this.fileSize)} groß, erlaubt sind `
          + `${formatBytes(this.maxArchiveBytes)} (IMPORT_MAX_ARCHIVE_MB).`;
      }
    },

    fileLabel() {
      if (!this.fileName) {
        return 'Keine Datei gewählt';
      }

      return `${this.fileName} · ${formatBytes(this.fileSize)}`;
    },

    progressLabel() {
      if (this.fileKind === 'encrypted') {
        return this.processing
          ? 'Verschlüsselte Notiz wird lokal entschlüsselt und neu gebunden…'
          : 'Verschlüsselte Notiz wird vorbereitet…';
      }
      return this.processing
        ? 'Notizen werden angelegt… das kann einige Minuten dauern.'
        : `Archiv wird übertragen… ${this.progress} %`;
    },

    async startImport() {
      const file = this.$refs.archiveInput?.files?.[0];
      if (!file || this.busy || this.tooLarge) {
        return;
      }

      this.busy = true;
      this.canceled = false;
      this.error = '';
      this.report = null;
      this.progress = 0;
      this.processing = false;

      try {
        this.report = this.fileKind === 'encrypted'
          ? await this.importEncryptedNote(file)
          : await this.uploadInChunks(file);
        this.$dispatch('pages-changed');
      } catch (error) {
        this.error = this.canceled
          ? 'Der Import wurde abgebrochen.'
          : error.message || 'Der Import ist fehlgeschlagen.';
        await this.discardUpload();
      } finally {
        this.busy = false;
        this.processing = false;
        this.uploadId = null;
        this.encryptionPassword = '';
      }
    },

    async importEncryptedNote(file) {
      if (!this.encryptionPassword) {
        throw new Error('Gib das Kennwort der verschlüsselten Notiz ein.');
      }
      if (file.size > 1_500_000) {
        throw new Error('Die verschlüsselte Notiz überschreitet die zulässige Größe.');
      }

      this.processing = true;
      this.progress = 20;
      let exported;
      try {
        exported = JSON.parse(await file.text());
      } catch {
        throw new Error('Die Datei enthält kein gültiges verschlüsseltes Notizformat.');
      }
      if (
        exported?.format !== 'nasmutNotes-encrypted-note'
        || exported?.version !== 1
        || !exported.envelope
        || !/^[1-9][0-9]*$/.test(String(exported.original_page_id || ''))
      ) {
        throw new Error('Die Datei enthält kein unterstütztes verschlüsseltes Notizformat.');
      }

      const originalPageId = String(exported.original_page_id);
      validateEnvelope(exported.envelope, originalPageId);
      let decrypted;
      try {
        decrypted = await decryptEnvelope(exported.envelope, this.encryptionPassword, originalPageId);
      } catch {
        throw new Error('Kennwort falsch oder verschlüsselte Notiz beschädigt.');
      }
      this.progress = 50;

      const title = typeof exported.title === 'string' && exported.title.trim()
        ? exported.title.trim().slice(0, 200)
        : 'Importierte verschlüsselte Notiz';
      const created = await apiFetch('/api/pages', {
        method: 'POST',
        body: JSON.stringify({ type: 'note', title }),
      });
      const pageId = Number(created.id);
      if (!Number.isInteger(pageId) || pageId < 1) {
        throw new Error('Die Zielseite konnte nicht angelegt werden.');
      }

      try {
        const rebound = await encryptDocument(decrypted.document, this.encryptionPassword, pageId);
        await apiFetch(`/api/pages/${pageId}/content/encryption`, {
          method: 'PUT',
          body: JSON.stringify({
            transition: 'encrypt',
            content: rebound.envelope,
            version: 1,
            expected_encryption_state: 'plain',
          }),
        });
      } catch (error) {
        try {
          await apiFetch(`/api/pages/${pageId}/purge`, { method: 'DELETE' });
        } catch {
          /* Die leere Zielseite kann notfalls normal gelöscht werden. */
        }
        throw error;
      }

      this.progress = 100;
      return {
        pages: 1,
        images: 0,
        files: 0,
        failed_count: 0,
        skipped_count: 0,
        dead_links: 0,
        unused_files: 0,
        failed: [],
        skipped: [],
      };
    },

    async uploadInChunks(file) {
      const started = await apiFetch(BEGIN_ENDPOINT, {
        method: 'POST',
        body: JSON.stringify({ file_name: file.name, size: file.size }),
      });
      this.uploadId = started.upload_id;
      const size = Math.max(64 * 1024, Number(started.chunk_size || this.chunkSize));

      let index = 0;
      for (let offset = 0; offset < file.size; offset += size) {
        if (this.canceled) {
          throw new Error('abgebrochen');
        }
        const body = new FormData();
        body.append('index', String(index));
        body.append('chunk', file.slice(offset, offset + size), 'part');
        await apiFetch(`${BEGIN_ENDPOINT}/${this.uploadId}`, { method: 'POST', body });

        index += 1;
        this.progress = Math.min(99, Math.round(((offset + size) / file.size) * 100));
      }

      this.progress = 100;
      this.processing = true;

      return apiFetch(`${BEGIN_ENDPOINT}/${this.uploadId}/complete`, {
        method: 'POST',
        body: JSON.stringify({}),
      });
    },

    /** Teile eines abgebrochenen Uploads sofort freigeben statt sie ablaufen zu lassen. */
    async discardUpload() {
      if (!this.uploadId) {
        return;
      }
      try {
        await apiFetch(`${BEGIN_ENDPOINT}/${this.uploadId}`, { method: 'DELETE' });
      } catch {
        /* Der Server räumt abgelaufene Teile ohnehin auf. */
      }
    },

    cancelImport() {
      if (this.processing) {
        return;
      }
      this.canceled = true;
    },

    summaryLine() {
      if (!this.report) {
        return '';
      }
      const parts = [`${this.report.pages} Notiz(en)`];
      if (this.report.images > 0) {
        parts.push(`${this.report.images} Bild(er)`);
      }
      if (this.report.files > 0) {
        parts.push(`${this.report.files} Dateianhang/-anhänge`);
      }

      return `${parts.join(', ')} importiert.`;
    },

    hasNotes() {
      if (!this.report) {
        return false;
      }

      return this.report.failed_count > 0
        || this.report.skipped_count > 0
        || this.report.dead_links > 0
        || this.report.unused_files > 0;
    },

    /** Hinweise, die kein Fehler sind, aber erklären, was nicht mitkam. */
    noteLines() {
      if (!this.report) {
        return [];
      }
      const lines = [];
      if (this.report.failed_count > 0) {
        lines.push(`${this.report.failed_count} Notiz(en) konnten nicht angelegt werden.`);
      }
      if (this.report.skipped_count > 0) {
        lines.push(`${this.report.skipped_count} Datei(en) wurden übersprungen, etwa wegen der Größe.`);
      }
      if (this.report.dead_links > 0) {
        lines.push(`${this.report.dead_links} Bildverweis(e) waren im Archiv nicht enthalten und wurden entfernt.`);
      }
      if (this.report.unused_files > 0) {
        lines.push(`${this.report.unused_files} Datei(en) im Archiv gehörten zu keiner Notiz.`);
      }

      return lines;
    },

    detailRows() {
      if (!this.report) {
        return [];
      }

      return [
        ...(this.report.failed || []).map((entry) => ({ ...entry, kind: 'Fehler' })),
        ...(this.report.skipped || []).map((entry) => ({ ...entry, kind: 'Übersprungen' })),
      ];
    },

    toggleDetails() {
      this.detailsOpen = !this.detailsOpen;
    },
  };
}
