import { apiFetch } from './api.js';

export function adminInvites() {
  return {
    invites: [],
    loading: true,
    creating: false,
    lastCreatedUrl: null,
    form: { email: '', note: '', max_uses: 1, ttl_days: 7 },

    async init() {
      await this.refresh();
    },

    async refresh() {
      this.loading = true;
      try {
        const data = await apiFetch('/api/admin/invites');
        this.invites = data.invites;
      } finally {
        this.loading = false;
      }
    },

    async create() {
      this.creating = true;
      try {
        const result = await apiFetch('/api/admin/invites', {
          method: 'POST',
          body: JSON.stringify(this.form),
        });
        this.lastCreatedUrl = result.invite_url;
        this.form = { email: '', note: '', max_uses: 1, ttl_days: 7 };
        await this.refresh();
      } catch (e) {
        alert(e.message);
      } finally {
        this.creating = false;
      }
    },

    async revoke(id) {
      if (!confirm('Diesen Einladungslink wirklich widerrufen?')) {
        return;
      }
      await apiFetch(`/api/admin/invites/${id}`, { method: 'DELETE' });
      await this.refresh();
    },

    statusLabel(status) {
      return { open: 'Offen', used: 'Verbraucht', expired: 'Abgelaufen', revoked: 'Widerrufen' }[status] || status;
    },
  };
}
