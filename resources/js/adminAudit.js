import { apiFetch } from './api.js';

const DATE_FORMAT = new Intl.DateTimeFormat('de-DE', { dateStyle: 'short', timeStyle: 'medium' });

/** Lesbare Bezeichnungen; unbekannte Aktionen erscheinen mit ihrem Schlüssel. */
const ACTION_LABELS = {
  login: 'Anmeldung',
  invite_redeemed: 'Einladung eingelöst',
  page_deleted: 'Seite in den Papierkorb',
  pages_deleted: 'Seiten in den Papierkorb',
  page_purged: 'Seite endgültig gelöscht',
  trash_emptied: 'Papierkorb geleert',
  page_copied: 'Seite dupliziert',
  pages_transferred: 'Seiten an Notizbuch-Eigentümer übergeben',
  shared_page_copied: 'Geteilte Seite kopiert',
  share_created: 'Freigabelink erstellt',
  share_revoked: 'Freigabelink widerrufen',
  shares_stopped: 'Alle Freigaben einer Seite beendet',
  share_left: 'Freigabe verlassen',
  notebook_member_added: 'Notizbuch geteilt',
  notebook_member_changed: 'Notizbuch-Recht geändert',
  notebook_member_removed: 'Notizbuch-Teilnehmer entfernt',
  notebook_left: 'Notizbuch verlassen',
  page_encryption_changed: 'Verschlüsselung geändert',
  note_version_restored: 'Notizversion wiederhergestellt',
  notes_exported: 'Export',
  session_revoked: 'Anmeldung beendet',
  sessions_revoked_others: 'Andere Anmeldungen beendet',
  user_deactivated: 'Nutzer deaktiviert',
  user_activated: 'Nutzer aktiviert',
  user_content_transferred: 'Inhalte übergeben',
  user_deleted: 'Nutzer gelöscht',
  account_deleted: 'Konto selbst gelöscht',
  backup_created: 'Sicherung erstellt',
  backup_deleted: 'Sicherung gelöscht',
  backup_downloaded: 'Sicherung heruntergeladen',
  invite_created: 'Einladung erstellt',
  invite_revoked: 'Einladung widerrufen',
  device_token_issued: 'Geräte-Token erstellt',
  device_token_paired: 'Gerät verbunden',
  device_token_revoked: 'Gerät getrennt',
  entries_restored: 'Gelöschte Einträge wiederhergestellt',
};

function emptyFilter() {
  return { action: '', user_id: '', from: '', to: '' };
}

export function adminAudit() {
  return {
    entries: [],
    actions: [],
    hasMore: false,
    loading: true,
    error: '',
    filter: emptyFilter(),

    async init() {
      await this.load(false);
    },

    query(offset) {
      const parameters = new URLSearchParams();
      for (const [key, value] of Object.entries(this.filter)) {
        if (String(value).trim() !== '') {
          parameters.set(key, String(value).trim());
        }
      }
      if (offset > 0) {
        parameters.set('offset', String(offset));
      }

      return `/api/admin/audit?${parameters.toString()}`;
    },

    async load(append) {
      this.loading = true;
      this.error = '';
      try {
        const data = await apiFetch(this.query(append ? this.entries.length : 0));
        this.entries = append ? [...this.entries, ...data.entries] : data.entries;
        this.hasMore = Boolean(data.has_more);
        this.actions = data.actions || [];
      } catch (error) {
        this.error = error.message || 'Das Protokoll konnte nicht geladen werden.';
      } finally {
        this.loading = false;
      }
    },

    applyFilter() {
      return this.load(false);
    },

    resetFilter() {
      this.filter = emptyFilter();
      return this.load(false);
    },

    loadMore() {
      return this.load(true);
    },

    formatDate(value) {
      const date = new Date(value);
      return Number.isNaN(date.getTime()) ? value : DATE_FORMAT.format(date);
    },

    actionLabel(action) {
      return ACTION_LABELS[action] || action;
    },

    userLabel(entry) {
      if (entry.user_id === null) {
        return '—';
      }
      return entry.user ? `${entry.user} (#${entry.user_id})` : `gelöschter Nutzer #${entry.user_id}`;
    },

    objectLabel(entry) {
      return entry.object_type ? `${entry.object_type} #${entry.object_id ?? '—'}` : '—';
    },

    detailLabel(entry) {
      const pairs = Object.entries(entry.metadata || {});
      return pairs.length === 0 ? '' : pairs.map(([key, value]) => `${key}=${JSON.stringify(value)}`).join(' · ');
    },
  };
}
