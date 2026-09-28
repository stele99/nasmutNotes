import { onStatusChange } from './offline/runtime.js';

/**
 * Sync-Hinweis über dem Seiteninhalt (FR-OFFLINE, NFR-UI-26).
 *
 * Die Statuszeile am Fuß der Notizbuchleiste ist unter `xl` nur in der
 * eingeklappten Schublade zu sehen. Wer unterwegs ohne Netz schreibt, muss aber
 * auf der Seite selbst erkennen, dass seine Änderungen noch nicht auf dem
 * Server sind. Die Leiste erscheint deshalb nur, wenn etwas zu wissen ist:
 * ohne Verbindung oder wenn Änderungen hängen (Konflikt, Sync-Fehler). Online
 * ausstehende Änderungen bleiben still - sie gehen beim normalen Speichern
 * sekundenweise durch die Outbox, die Leiste würde bei jedem Tastendruck
 * aufblitzen.
 */
export function syncStatus() {
  return {
    online: true,
    pending: 0,
    stuck: 0,
    unsubscribe: null,

    init() {
      this.unsubscribe = onStatusChange((status) => {
        this.online = status.online;
        this.pending = status.pendingCount;
        this.stuck = status.conflictCount + status.blockedCount;
      });
    },

    destroy() {
      if (this.unsubscribe) {
        this.unsubscribe();
      }
    },

    isVisible() {
      return !this.online || this.stuck > 0;
    },

    isWarning() {
      return this.stuck > 0;
    },

    toneClass() {
      return this.isWarning() ? 'is-warning' : 'is-offline';
    },

    text() {
      if (this.stuck > 0) {
        const count = this.stuck === 1 ? '1 Änderung' : `${this.stuck} Änderungen`;
        return `${count} nicht übertragen – Einstellungen → Sync`;
      }
      if (this.pending > 0) {
        const count = this.pending === 1 ? '1 Änderung wartet' : `${this.pending} Änderungen warten`;
        return `Offline · ${count} auf die Übertragung`;
      }

      return 'Offline · Änderungen werden übertragen, sobald wieder Netz da ist';
    },
  };
}
