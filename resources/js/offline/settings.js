import {
  CACHE_LIMITS,
  cancelPrefetch,
  clearOfflineData,
  discardBlockedEntry,
  getCacheLimit,
  getOfflineStats,
  invalidateAllCachedImages,
  listBlockedEntries,
  listSyncConflicts,
  normalizeCacheLimit,
  onStatusChange,
  prefetchSelected,
  retryBlockedEntry,
  setCacheLimit,
  syncOutbox,
} from './runtime.js';
import { onInstallStateChange, promptInstall } from '../install.js';
import { apiFetch } from '../api.js';
import { getLocationMode, isLocationSupported, loadLocationMode, saveLocationMode } from '../geo.js';

const LARGE_LIMITS = [5000, 10000, 'all'];
let settingsInstance = 0;

function formatBytes(bytes) {
  if (!bytes || bytes <= 0) {
    return '0 B';
  }
  const units = ['B', 'KB', 'MB', 'GB'];
  let value = bytes;
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit += 1;
  }
  return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}

export function offlineSettings() {
  settingsInstance += 1;

  return {
    open: false,
    settingsSection: 'app',
    limits: CACHE_LIMITS,
    limit: 100,
    pageCount: 0,
    localNoteCount: 0,
    localTaskPageCount: 0,
    localImageCount: 0,
    localFileCount: 0,
    pendingSync: 0,
    conflictCount: 0,
    blockedCount: 0,
    conflicts: [],
    blocked: [],
    resolvingConflictId: null,
    usageLabel: '–',
    quotaLabel: '–',
    attachmentLimitLabel: '–',
    statusOnline: true,
    statusSyncing: false,
    statusPrefetching: false,
    prefetchLabel: '',
    message: '',
    error: '',
    busy: false,
    importOpen: false,
    unsubscribe: null,
    installUnsubscribe: null,
    canInstallApp: false,
    appInstalled: false,
    showIosInstallHint: false,
    installMessage: '',
    workspaceNotebookCount: 0,
    workspacePageCount: 0,
    workspaceTaskCount: 0,
    workspaceFileCount: 0,
    workspaceStorageLabel: '–',
    workspaceTopItems: [],
    // Standort neuer Notizen: im Benutzerprofil, in der Vorgabe erst auf Klick
    // (FR-NOTE-25).
    locationSupported: isLocationSupported(),
    locationMode: getLocationMode(),
    locationRadioName: `location-mode-${settingsInstance}`,
    locationModeHandler: null,
    locationModeSaving: false,

    // Automations-Token für NotesVoice (FR-NVOICE): eigener Diktatweg per
    // Rückseitentipp-Kurzbefehl, unabhängig vom Session-Cookie.
    deviceTokens: [],
    deviceTokensLoading: false,
    deviceTokenLabel: '',
    deviceTokenCreating: false,
    deviceTokenError: '',
    deviceTokenLastCreated: null,
    deviceTokenCopyLabel: 'Kopieren',

    async init() {
      await this.refreshStats();
      this.locationMode = await loadLocationMode();
      this.unsubscribe = onStatusChange((status) => {
        const previousConflictCount = this.conflictCount;
        const wasPrefetching = this.statusPrefetching;
        this.statusOnline = status.online;
        this.statusSyncing = status.syncing;
        this.statusPrefetching = status.prefetching;
        // Der Download läuft im Hintergrund; die Zahlen im Dialog stimmen sonst
        // erst wieder, wenn er von Hand neu geöffnet wird.
        if (wasPrefetching && !status.prefetching) {
          void this.refreshStats();
        }
        this.pendingSync = status.pendingCount + status.conflictCount + status.blockedCount;
        this.conflictCount = status.conflictCount;
        this.blockedCount = status.blockedCount;
        if (status.prefetching && status.prefetchProgress.total > 0) {
          this.prefetchLabel = `${status.prefetchProgress.done} / ${status.prefetchProgress.total}`;
        } else {
          this.prefetchLabel = '';
        }
        if (status.lastError) {
          this.error = status.lastError;
        }
        if (this.open && (previousConflictCount !== this.conflictCount
          || this.blockedCount !== this.blocked.length)) {
          void this.refreshConflicts();
        }
      });
      this.installUnsubscribe = onInstallStateChange((state) => {
        this.canInstallApp = state.canPrompt;
        this.appInstalled = state.installed;
        this.showIosInstallHint = state.showIosHint;
      });
      this.locationModeHandler = (event) => {
        this.locationMode = event.detail?.mode || getLocationMode();
      };
      window.addEventListener('location-mode-changed', this.locationModeHandler);
    },

    destroy() {
      if (this.unsubscribe) {
        this.unsubscribe();
      }
      if (this.installUnsubscribe) {
        this.installUnsubscribe();
      }
      if (this.locationModeHandler) {
        window.removeEventListener('location-mode-changed', this.locationModeHandler);
      }
    },

    async openDialog() {
      await this.showSettings('app');
    },

    /** Einstieg aus der Statuszeile - dort interessiert nur der Sync. */
    openSyncSettings() {
      void this.showSettings('sync');
    },

    async showSettings(section) {
      this.open = true;
      this.settingsSection = section;
      this.message = '';
      this.error = '';
      this.locationMode = await loadLocationMode(true);
      await this.refreshStats();
      await this.loadDeviceTokens();
    },

    selectSettingsSection(section) {
      this.settingsSection = section;
    },

    isSettingsSection(section) {
      return this.settingsSection === section;
    },

    closeDialog() {
      // Steht der Import-Dialog darüber, gehört die Escape-Taste ihm allein.
      if (this.busy || this.importOpen) {
        return;
      }
      this.open = false;
    },

    /** @param {CustomEvent} event */
    trackImportDialog(event) {
      this.importOpen = event.detail?.open === true;
    },

    async refreshStats() {
      const [stats, session] = await Promise.all([
        getOfflineStats(),
        apiFetch('/api/session').catch(() => null),
      ]);
      this.limit = normalizeCacheLimit(stats.limit);
      this.pageCount = stats.pageCount;
      this.localNoteCount = stats.noteCount;
      this.localTaskPageCount = stats.taskPageCount;
      this.localImageCount = stats.imageCount;
      this.localFileCount = stats.fileCount;
      this.pendingSync = stats.unresolved;
      this.conflictCount = stats.conflicts;
      this.blockedCount = stats.blocked;
      this.usageLabel = formatBytes(stats.usageBytes);
      this.quotaLabel = formatBytes(stats.quotaBytes);
      // Vom Admin vorgegeben; hier nur zur Erklärung, warum große Anhänge fehlen.
      this.attachmentLimitLabel = stats.attachmentMaxBytes > 0
        ? formatBytes(stats.attachmentMaxBytes)
        : 'aus';
      this.workspaceNotebookCount = Number(session?.storage?.notebooks || 0);
      this.workspacePageCount = Number(session?.storage?.pages || 0);
      this.workspaceTaskCount = Number(session?.storage?.tasks || 0);
      this.workspaceFileCount = Number(session?.storage?.files || 0);
      this.workspaceStorageLabel = formatBytes(Number(session?.storage?.storage_bytes || 0));
      this.workspaceTopItems = Array.isArray(session?.storage?.top_items)
        ? session.storage.top_items.map((item) => ({
          ...item,
          sizeLabel: formatBytes(Number(item.bytes || 0)),
        }))
        : [];
      await this.refreshConflicts();
    },

    async refreshConflicts() {
      this.conflicts = await listSyncConflicts();
      this.blocked = await listBlockedEntries();
    },

    limitLabel(value) {
      return value === 'all' ? 'Alle' : String(value);
    },

    async installApp() {
      this.installMessage = '';
      const accepted = await promptInstall();
      this.installMessage = accepted
        ? 'Die App wurde installiert.'
        : 'Installation wurde nicht abgeschlossen.';
    },

    /**
     * `manual`: Der Ort kommt erst, wenn er auf der Notiz angefordert wird.
     * `auto`: Schon beim Anlegen fragt der Browser danach (FR-NOTE-25).
     */
    async selectLocationMode(mode) {
      if (this.locationModeSaving || mode === this.locationMode) {
        return;
      }
      const previous = this.locationMode;
      this.locationMode = mode;
      this.locationModeSaving = true;
      this.error = '';
      try {
        this.locationMode = await saveLocationMode(mode);
        window.dispatchEvent(new CustomEvent('location-mode-changed', {
          detail: { mode: this.locationMode },
        }));
      } catch (error) {
        this.locationMode = previous;
        this.error = error.message || 'Die Standort-Einstellung konnte nicht gespeichert werden.';
      } finally {
        this.locationModeSaving = false;
      }
    },

    isLocationMode(mode) {
      return this.locationMode === mode;
    },

    /** Ohne freigeschaltete Sprachnotizen steht die Sektion gar nicht im Markup. */
    async loadDeviceTokens() {
      if (typeof window.__VOICE__ === 'undefined') {
        return;
      }
      this.deviceTokensLoading = true;
      this.deviceTokenError = '';
      try {
        const data = await apiFetch('/api/profile/device-tokens');
        this.deviceTokens = data.device_tokens || [];
      } catch (error) {
        this.deviceTokenError = error.message || 'Automations-Token konnten nicht geladen werden.';
      } finally {
        this.deviceTokensLoading = false;
      }
    },

    async createDeviceToken() {
      if (this.deviceTokenCreating) {
        return;
      }
      const label = this.deviceTokenLabel.trim();
      if (!label) {
        this.deviceTokenError = 'Bitte einen Namen für das Gerät angeben.';
        return;
      }
      this.deviceTokenCreating = true;
      this.deviceTokenError = '';
      this.deviceTokenCopyLabel = 'Kopieren';
      try {
        const result = await apiFetch('/api/profile/device-tokens', {
          method: 'POST',
          body: JSON.stringify({ label }),
        });
        this.deviceTokenLastCreated = result;
        this.deviceTokenLabel = '';
        await this.copyDeviceToken();
        await this.loadDeviceTokens();
      } catch (error) {
        this.deviceTokenError = error.message || 'Der Token konnte nicht erstellt werden.';
      } finally {
        this.deviceTokenCreating = false;
      }
    },

    async copyDeviceToken() {
      const token = this.deviceTokenLastCreated?.token;
      if (!token) {
        return;
      }
      try {
        if (navigator.clipboard?.writeText) {
          await navigator.clipboard.writeText(token);
        } else {
          const input = document.createElement('textarea');
          input.value = token;
          input.style.position = 'fixed';
          input.style.opacity = '0';
          document.body.appendChild(input);
          input.select();
          const copied = document.execCommand('copy');
          input.remove();
          if (!copied) {
            throw new Error('Zwischenablage nicht verfügbar.');
          }
        }
        this.deviceTokenCopyLabel = 'Kopiert';
      } catch (error) {
        this.deviceTokenCopyLabel = 'Erneut kopieren';
      }
    },

    async revokeDeviceToken(token) {
      if (!window.confirm(`Automations-Token „${token.label}“ wirklich widerrufen? Die zugehörige Automation funktioniert danach nicht mehr.`)) {
        return;
      }
      this.deviceTokenError = '';
      try {
        await apiFetch(`/api/profile/device-tokens/${token.id}`, { method: 'DELETE' });
        if (this.deviceTokenLastCreated?.id === token.id) {
          this.deviceTokenLastCreated = null;
        }
        await this.loadDeviceTokens();
      } catch (error) {
        this.deviceTokenError = error.message || 'Der Token konnte nicht widerrufen werden.';
      }
    },

    deviceTokenSummary(token) {
      return token.last_used_at
        ? `Zuletzt genutzt: ${this.conflictTime(token.last_used_at)}`
        : `Erstellt: ${this.conflictTime(token.created_at)} · noch nicht genutzt`;
    },

    async compressOwnImages() {
      if (!this.statusOnline || this.busy) {
        this.error = 'Die Bildkompression benötigt eine Online-Verbindung.';
        return;
      }
      if (!window.confirm(
        'Alle eingebetteten Bilder komprimieren?\n\n'
        + 'Einstellung: Qualität 82 %, maximale Breite 1960 px. '
        + 'Die Originaldateien werden ersetzt und können nicht über Versionen wiederhergestellt werden.',
      )) {
        return;
      }

      this.busy = true;
      this.error = '';
      this.message = '';
      try {
        const result = await apiFetch('/api/images/compress', { method: 'POST' });
        await invalidateAllCachedImages();
        await this.refreshStats();
        this.message = `${result.compressed} von ${result.images} Bild(ern) komprimiert · `
          + `${formatBytes(result.saved_bytes)} eingespart.`;
      } catch (error) {
        this.error = error.message || 'Die Bilder konnten nicht komprimiert werden.';
      } finally {
        this.busy = false;
      }
    },

    /** @param {string|null} value ISO-Zeitstempel */
    conflictTime(value) {
      if (!value) {
        return 'Zeitpunkt unbekannt';
      }
      const date = new Date(value);
      if (Number.isNaN(date.getTime())) {
        return 'Zeitpunkt unbekannt';
      }

      return new Intl.DateTimeFormat('de-DE', {
        dateStyle: 'medium',
        timeStyle: 'short',
      }).format(date);
    },

    localConflictLabel(conflict) {
      return `Zuletzt lokal bearbeitet: ${this.conflictTime(conflict.local_updated_at)}`;
    },

    serverConflictLabel(conflict) {
      const time = this.conflictTime(conflict.server_updated_at);
      return conflict.server_editor_name
        ? `Auf dem Server geändert: ${time} · ${conflict.server_editor_name}`
        : `Auf dem Server geändert: ${time}`;
    },

    /**
     * Die Auflösung selbst findet nur auf der Notiz statt (dort stehen beide
     * Fassungen im Kontext nebeneinander) - hier gibt es deshalb nur den
     * Einstieg, keine Entscheidungsknöpfe.
     */
    openConflictNote(conflict) {
      const owner = window.__workspaceNavigationOwner__;
      this.closeDialog();
      if (owner && typeof owner.navigateTo === 'function') {
        void owner.navigateTo(`/app/page/${conflict.page_id}`);
      } else {
        window.location.href = `/app/page/${conflict.page_id}`;
      }
    },

    /**
     * Blockierte Einträge werden vom Sync übersprungen. Ohne diese beiden
     * Aktionen bliebe der Zustand dauerhaft bestehen.
     */
    async retryBlocked(entry) {
      this.resolvingConflictId = entry.id;
      this.error = '';
      try {
        await retryBlockedEntry(entry.id);
        this.message = `„${entry.title}“ wurde erneut übertragen.`;
        await this.refreshStats();
      } catch (error) {
        this.error = error.message || 'Erneuter Versuch fehlgeschlagen.';
      } finally {
        this.resolvingConflictId = null;
      }
    },

    async discardBlocked(entry) {
      if (!window.confirm(`Nicht übertragbare Änderungen an „${entry.title}“ endgültig verwerfen?`)) {
        return;
      }
      this.resolvingConflictId = entry.id;
      this.error = '';
      try {
        await discardBlockedEntry(entry.id);
        this.message = `Eintrag „${entry.title}“ wurde verworfen.`;
        await this.refreshStats();
      } catch (error) {
        this.error = error.message || 'Eintrag konnte nicht verworfen werden.';
      } finally {
        this.resolvingConflictId = null;
      }
    },

    isResolvingBlocked(entry) {
      return this.resolvingConflictId === entry.id;
    },

    async saveLimit() {
      this.busy = true;
      this.error = '';
      this.message = '';
      try {
        // x-model liefert bei <select> Strings - ohne Normalisierung vor dem
        // Vergleich greift die Speicherwarnung für 5000/10000 nie.
        const chosen = normalizeCacheLimit(this.limit);
        if (LARGE_LIMITS.includes(chosen)
          && !window.confirm('Große Offline-Limits können viel Speicher benötigen (inkl. Bilder). Fortfahren?')) {
          this.limit = await getCacheLimit();
          return;
        }
        await setCacheLimit(chosen);
        this.message = 'Offline-Limit gespeichert. Fehlende Inhalte werden im Hintergrund geladen.';
        await this.refreshStats();
      } catch (error) {
        this.error = error.message || 'Limit konnte nicht gespeichert werden.';
      } finally {
        this.busy = false;
      }
    },

    /**
     * Bewusst ohne `await` und ohne `busy`: Der Dialog soll sich während des
     * Downloads schließen lassen, den Fortschritt zeigt die Statuszeile.
     */
    downloadNow() {
      this.error = '';
      this.message = 'Aktualisierung läuft im Hintergrund.';
      void prefetchSelected({ force: true }).catch((error) => {
        this.error = error.message || 'Download fehlgeschlagen.';
      });
    },

    cancelDownload() {
      cancelPrefetch();
      this.message = 'Download abgebrochen.';
    },

    cancelLabel() {
      return this.prefetchLabel
        ? `Download abbrechen (${this.prefetchLabel})`
        : 'Download abbrechen';
    },

    async syncNow() {
      this.busy = true;
      this.error = '';
      this.message = '';
      try {
        const result = await syncOutbox();
        await this.refreshStats();
        if (result.conflicts > 0) {
          this.message = `${result.synced} synchronisiert, ${result.conflicts} Konflikt(e).`;
        } else {
          this.message = result.synced > 0
            ? `${result.synced} Änderung(en) synchronisiert.`
            : 'Keine ausstehenden Änderungen.';
        }
      } catch (error) {
        this.error = error.message || 'Sync fehlgeschlagen.';
      } finally {
        this.busy = false;
      }
    },

    async clearCache() {
      // Der Cache enthält auch die Queue - ungesyncte Änderungen wären weg.
      if (this.pendingSync > 0
        && !window.confirm(`${this.pendingSync} Änderung(en) sind noch nicht synchronisiert und gehen dabei verloren. Trotzdem fortfahren?`)) {
        return;
      }
      if (!window.confirm('Alle lokalen Offline-Daten und Bilder löschen?')) {
        return;
      }
      this.busy = true;
      this.error = '';
      this.message = '';
      try {
        await clearOfflineData();
        await this.refreshStats();
        this.message = 'Lokaler Cache geleert.';
      } catch (error) {
        this.error = error.message || 'Cache konnte nicht geleert werden.';
      } finally {
        this.busy = false;
      }
    },

    statusText() {
      if (!this.statusOnline) {
        return this.pendingSync > 0
          ? `Offline · ${this.pendingSync} ausstehend`
          : 'Offline';
      }
      if (this.conflictCount > 0) {
        return `${this.conflictCount} Konflikt(e)`;
      }
      if (this.blockedCount > 0) {
        return `${this.blockedCount} Sync-Fehler`;
      }
      if (this.statusSyncing) {
        return 'Synchronisiere…';
      }
      if (this.statusPrefetching) {
        return this.prefetchLabel ? `Lade offline… ${this.prefetchLabel}` : 'Lade offline…';
      }
      if (this.pendingSync > 0) {
        return `${this.pendingSync} zum Sync`;
      }
      return 'Online';
    },

    /**
     * Farbgebung der Statuszeile. Konflikte und Sync-Fehler wiegen schwerer als
     * fehlende Verbindung: Sie bleiben auch online bestehen und brauchen eine
     * Entscheidung.
     */
    statusTone() {
      if (this.conflictCount > 0 || this.blockedCount > 0) {
        return 'is-warning';
      }
      if (!this.statusOnline) {
        return 'is-offline';
      }
      if (this.statusSyncing || this.statusPrefetching) {
        return 'is-busy';
      }

      return this.pendingSync > 0 ? 'is-pending' : 'is-idle';
    },
  };
}
