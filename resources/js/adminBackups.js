import { apiFetch } from './api.js';

function formatBytes(bytes) {
  const value = Number(bytes || 0);
  if (value < 1024) {
    return `${value} B`;
  }
  const units = ['KB', 'MB', 'GB', 'TB'];
  let size = value / 1024;
  let unit = 0;
  while (size >= 1024 && unit < units.length - 1) {
    size /= 1024;
    unit += 1;
  }

  return `${size.toFixed(size >= 10 || unit === 0 ? 0 : 1)} ${units[unit]}`;
}

/**
 * Sicherungen im Admin-Bereich (NFR-OPS-06). Anlegen, herunterladen, löschen -
 * das Zurückspielen bleibt bewusst der CLI vorbehalten.
 */
export function adminBackups() {
  return {
    snapshots: [],
    stats: {},
    loading: true,
    busy: false,
    error: '',
    message: '',

    async init() {
      await this.refresh();
    },

    async refresh() {
      this.loading = true;
      this.error = '';
      try {
        const data = await apiFetch('/api/admin/backups');
        this.snapshots = data.snapshots || [];
        this.stats = data.stats || {};
      } catch (error) {
        this.error = error.message || 'Die Sicherungen konnten nicht geladen werden.';
      } finally {
        this.loading = false;
      }
    },

    async create() {
      this.busy = true;
      this.error = '';
      this.message = '';
      try {
        const result = await apiFetch('/api/admin/backups', { method: 'POST' });
        const parts = [
          `Sicherung ${result.id} erstellt`,
          `${result.upload_count} Datei(en) erfasst`,
          `${result.new_files} neu gespeichert (${formatBytes(result.new_bytes)})`,
        ];
        if (result.pruned > 0) {
          parts.push(`${result.pruned} alte Sicherung(en) entfernt`);
        }
        this.message = `${parts.join(' · ')}.`;
        await this.refresh();
      } catch (error) {
        this.error = error.message || 'Die Sicherung konnte nicht erstellt werden.';
      } finally {
        this.busy = false;
      }
    },

    async remove(snapshot) {
      if (!window.confirm(`Sicherung ${snapshot.id} endgültig löschen?`)) {
        return;
      }
      this.busy = true;
      this.error = '';
      this.message = '';
      try {
        await apiFetch(`/api/admin/backups/${snapshot.id}`, { method: 'DELETE' });
        this.message = `Sicherung ${snapshot.id} gelöscht.`;
        await this.refresh();
      } catch (error) {
        this.error = error.message || 'Die Sicherung konnte nicht gelöscht werden.';
      } finally {
        this.busy = false;
      }
    },

    downloadUrl(snapshot) {
      return `/api/admin/backups/${snapshot.id}/download`;
    },

    formatBytes(bytes) {
      return formatBytes(bytes);
    },

    formatDate(value) {
      if (!value) {
        return '—';
      }
      const date = new Date(value);
      if (Number.isNaN(date.getTime())) {
        return '—';
      }

      return new Intl.DateTimeFormat('de-DE', {
        dateStyle: 'medium',
        timeStyle: 'short',
      }).format(date);
    },

    /** Wie alt die jüngste Sicherung ist - das ist die eigentliche Kennzahl. */
    lastBackupLabel() {
      const value = this.stats.last_created_at;
      if (!value) {
        return 'nie';
      }
      const date = new Date(value);
      if (Number.isNaN(date.getTime())) {
        return '—';
      }
      const hours = Math.floor((Date.now() - date.getTime()) / 3_600_000);
      if (hours < 1) {
        return 'gerade eben';
      }
      if (hours < 24) {
        return `vor ${hours} h`;
      }

      return `vor ${Math.floor(hours / 24)} Tag(en)`;
    },

    keepLabel() {
      return `${Number(this.stats.keep || 0)} Läufe`;
    },

    pageLabel(snapshot) {
      return snapshot.broken ? '—' : Number(snapshot.page_count || 0);
    },

    fileLabel(snapshot) {
      return snapshot.broken ? '—' : Number(snapshot.upload_count || 0);
    },

    createLabel() {
      return this.busy ? 'Sicherung läuft…' : 'Sicherung erstellen';
    },
  };
}
