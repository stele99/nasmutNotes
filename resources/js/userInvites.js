import { apiFetch } from './api.js';

function emptyForm() {
  return { email: '', note: '', max_uses: 1, ttl_days: 7 };
}

/**
 * Einladungen für jeden angemeldeten Nutzer (FR-INV-09). Der erzeugte Link ist
 * nur unmittelbar nach dem Anlegen sichtbar - serverseitig liegt lediglich der
 * Hash des Tokens.
 */
export function userInvites() {
  return {
    open: false,
    invites: [],
    loading: false,
    creating: false,
    error: '',
    lastCreatedUrl: '',
    copyLabel: 'Kopieren',
    form: emptyForm(),

    async openDialog() {
      this.open = true;
      this.error = '';
      this.lastCreatedUrl = '';
      this.copyLabel = 'Kopieren';
      await this.refresh();
    },

    closeDialog() {
      if (this.creating) {
        return;
      }
      this.open = false;
    },

    async refresh() {
      if (!navigator.onLine) {
        this.error = 'Einladungen können nur online verwaltet werden.';
        return;
      }
      this.loading = true;
      try {
        const data = await apiFetch('/api/invites');
        this.invites = data.invites || [];
      } catch (error) {
        this.error = error.message || 'Die Einladungen konnten nicht geladen werden.';
      } finally {
        this.loading = false;
      }
    },

    async create() {
      if (this.creating) {
        return;
      }
      if (!navigator.onLine) {
        this.error = 'Einladungen können nur online erstellt werden.';
        return;
      }
      this.creating = true;
      this.error = '';
      this.copyLabel = 'Kopieren';
      try {
        const result = await apiFetch('/api/invites', {
          method: 'POST',
          body: JSON.stringify(this.form),
        });
        this.lastCreatedUrl = result.invite_url;
        this.form = emptyForm();
        await this.copyLink();
        await this.refresh();
      } catch (error) {
        this.error = error.message || 'Die Einladung konnte nicht erstellt werden.';
      } finally {
        this.creating = false;
      }
    },

    async copyLink() {
      if (!this.lastCreatedUrl) {
        return;
      }
      try {
        if (navigator.clipboard?.writeText) {
          await navigator.clipboard.writeText(this.lastCreatedUrl);
        } else {
          const input = document.createElement('textarea');
          input.value = this.lastCreatedUrl;
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
        this.copyLabel = 'Kopiert';
      } catch (error) {
        this.copyLabel = 'Erneut kopieren';
      }
    },

    async share() {
      if (!this.lastCreatedUrl) {
        return;
      }
      if (typeof navigator.share !== 'function') {
        await this.copyLink();
        return;
      }
      try {
        await navigator.share({
          title: 'Einladung zu Notizen & Tasks',
          text: 'Du bist zu Notizen & Tasks eingeladen.',
          url: this.lastCreatedUrl,
        });
      } catch (error) {
        /* Abbruch durch den Nutzer ist kein Fehler. */
      }
    },

    canShare() {
      return typeof navigator.share === 'function';
    },

    async revoke(invite) {
      if (!window.confirm('Diesen Einladungslink wirklich widerrufen?')) {
        return;
      }
      this.error = '';
      try {
        await apiFetch(`/api/invites/${invite.id}`, { method: 'DELETE' });
        await this.refresh();
      } catch (error) {
        this.error = error.message || 'Die Einladung konnte nicht widerrufen werden.';
      }
    },

    statusLabel(status) {
      return {
        open: 'Offen',
        used: 'Verbraucht',
        expired: 'Abgelaufen',
        revoked: 'Widerrufen',
      }[status] || status;
    },

    inviteSummary(invite) {
      const parts = [this.statusLabel(invite.status), `${invite.used_count}/${invite.max_uses} genutzt`];
      if (invite.email) {
        parts.unshift(invite.email);
      }

      return parts.join(' · ');
    },
  };
}
