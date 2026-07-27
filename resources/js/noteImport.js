import { apiFetch } from './api.js';

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
        this.report = await this.uploadInChunks(file);
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
      }
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
