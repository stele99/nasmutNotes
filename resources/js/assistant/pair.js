import { apiFetch, refreshCsrfToken } from '../api.js';

/**
 * Bestätigungsseite der Desktop-Assistant-Paarung: Der Client hat den
 * Browser mit dem Anzeige-Code geöffnet; hier bestätigt der angemeldete
 * Nutzer die Verbindung. Bewusst CSP-konform ohne Inline-Ausdrücke.
 */
export function assistantPair() {
  return {
    code: '',
    label: '',
    platform: '',
    status: 'ready',
    busy: false,
    error: '',

    async init() {
      this.code = (this.$el.dataset.pairCode || '').trim();
      this.label = this.$el.dataset.pairLabel || '';
      this.platform = this.$el.dataset.pairPlatform || '';
      if (!this.code) {
        this.status = 'missing';
        return;
      }
      // Der Server kennt den Code nicht mehr: abgelaufen oder schon verbraucht.
      if (!this.label) {
        this.status = 'unknown';
        return;
      }
      await refreshCsrfToken();
    },

    async approve() {
      if (this.busy || this.status === 'approved') {
        return;
      }
      this.busy = true;
      this.error = '';
      try {
        await apiFetch('/api/assistant/pair/approve', {
          method: 'POST',
          body: JSON.stringify({ user_code: this.code }),
        });
        this.status = 'approved';
      } catch (error) {
        this.error = error.message || 'Die Verbindung konnte nicht bestätigt werden.';
        if (error.status === 404 || error.status === 422) {
          this.status = 'invalid';
        }
      } finally {
        this.busy = false;
      }
    },
  };
}
