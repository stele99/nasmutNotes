import { apiFetch } from './api.js';

export function pageShare() {
  return {
    pageId: window.__CURRENT_PAGE_ID__,
    isShared: Boolean(window.__CURRENT_PAGE_IS_SHARED__),
    currentPermission: window.__CURRENT_PAGE_PERMISSION__ || null,
    permission: 'read',
    shareDialogOpen: false,
    generatedLink: '',
    copyLabel: 'Kopieren',
    successMessage: '',
    errorMessage: '',
    generating: false,
    writers: [],
    writersOpen: false,
    existingShares: [],
    sharesLoading: false,
    stoppingSharing: false,
    writerRefreshHandler: null,
    writerRefreshTimer: null,

    permissionLabel() {
      return this.currentPermission === 'write' ? 'schreibend' : 'lesend';
    },

    async init() {
      const pageRoot = this.$root;
      if (pageRoot?.dataset.pageId) {
        this.pageId = Number(pageRoot.dataset.pageId);
        this.isShared = pageRoot.dataset.pageIsShared === '1';
        this.currentPermission = pageRoot.dataset.pagePermission || null;
        window.__CURRENT_PAGE_ID__ = this.pageId;
        window.__CURRENT_PAGE_IS_SHARED__ = this.isShared;
        window.__CURRENT_PAGE_PERMISSION__ = this.currentPermission;
        window.__CURRENT_PAGE_CAN_EDIT__ = pageRoot.dataset.pageCanEdit === '1';
      }
      await this.loadWriters();
      if (!this.isShared) {
        await this.loadShares();
      }
      this.writerRefreshHandler = () => {
        void this.loadWriters();
      };
      window.addEventListener('focus', this.writerRefreshHandler);
      this.writerRefreshTimer = setInterval(() => this.loadWriters(), 30_000);
    },

    async loadWriters() {
      if (!this.pageId || !navigator.onLine) {
        return;
      }
      try {
        const data = await apiFetch(`/api/pages/${this.pageId}/writers`);
        this.writers = data.writers || [];
      } catch {
        /* Header bleibt auch bei einem kurzzeitigen Netzwerkfehler nutzbar. */
      }
    },

    async loadShares() {
      if (!this.pageId || this.isShared || !navigator.onLine) {
        return;
      }
      this.sharesLoading = true;
      try {
        const data = await apiFetch(`/api/pages/${this.pageId}/shares`);
        this.existingShares = data.shares || [];
      } catch (error) {
        this.errorMessage = error.message || 'Freigaben konnten nicht geladen werden.';
      } finally {
        this.sharesLoading = false;
      }
    },

    writerInitials(writer) {
      const name = String(writer?.name || '').trim();
      if (!name) {
        return '?';
      }
      const parts = name.split(/\s+/).filter(Boolean);
      if (parts.length > 1) {
        return `${Array.from(parts[0])[0] || ''}${Array.from(parts[parts.length - 1])[0] || ''}`.toUpperCase();
      }
      return Array.from(parts[0]).slice(0, 2).join('').toUpperCase();
    },

    writerLabel(writer) {
      return writer?.is_owner ? `${writer.name} (Owner)` : writer?.name || 'Kollaborator';
    },

    hasCollaborators() {
      return this.writers.length > 1;
    },

    /**
     * Freigabe-Hinweis für den Eigentümer: greift auch dann, wenn ein Link zwar
     * erzeugt, aber noch von niemandem angenommen wurde.
     */
    ownedAndShared() {
      return !this.isShared && (this.hasCollaborators() || this.existingShares.length > 0);
    },

    visibleWriters() {
      return this.writers.slice(0, 4);
    },

    additionalWritersCount() {
      return Math.max(0, this.writers.length - 4);
    },

    sharedWithLabel() {
      const names = this.writers.map((writer) => this.writerLabel(writer));
      return names.length > 0 ? `Geteilt mit ${names.join(', ')}` : 'Nicht geteilt';
    },

    toggleWriters() {
      this.writersOpen = !this.writersOpen;
    },

    closeWriters() {
      this.writersOpen = false;
    },

    activeSharesLabel() {
      const count = this.existingShares.length;
      return count === 1 ? '1 aktive Freigabe' : `${count} aktive Freigaben`;
    },

    async openShareDialog() {
      if (this.isShared) {
        return;
      }
      this.errorMessage = '';
      this.successMessage = '';
      this.generatedLink = '';
      this.copyLabel = 'Kopieren';
      this.shareDialogOpen = true;
      await this.loadShares();
    },

    closeShareDialog() {
      if (this.generating || this.stoppingSharing) {
        return;
      }
      this.shareDialogOpen = false;
    },

    async generateShareLink() {
      if (this.generating) {
        return;
      }

      this.generating = true;
      this.errorMessage = '';
      this.successMessage = '';
      try {
        const data = await apiFetch(`/api/pages/${this.pageId}/shares`, {
          method: 'POST',
          body: JSON.stringify({ permission: this.permission }),
        });
        this.generatedLink = data.url;
        await this.loadShares();
        await this.copyLink();
        this.successMessage = 'Der Link wurde erzeugt und in die Zwischenablage kopiert.';
      } catch (error) {
        this.errorMessage = error.message || 'Der Freigabe-Link konnte nicht erzeugt werden.';
      } finally {
        this.generating = false;
      }
    },

    async stopSharing() {
      if (this.isShared || this.existingShares.length === 0 || this.stoppingSharing) {
        return;
      }
      if (!window.confirm('Alle Freigaben dieser Seite beenden? Andere Nutzer verlieren den Zugriff.')) {
        return;
      }

      this.stoppingSharing = true;
      this.errorMessage = '';
      this.successMessage = '';
      try {
        await apiFetch(`/api/pages/${this.pageId}/shares`, {
          method: 'DELETE',
        });
        this.existingShares = [];
        this.generatedLink = '';
        this.successMessage = 'Das Teilen wurde beendet.';
        await this.loadWriters();
      } catch (error) {
        this.errorMessage = error.message || 'Das Teilen konnte nicht beendet werden.';
        await this.loadShares();
      } finally {
        this.stoppingSharing = false;
      }
    },

    async copyLink() {
      if (!this.generatedLink) {
        return;
      }

      try {
        if (navigator.clipboard?.writeText) {
          await navigator.clipboard.writeText(this.generatedLink);
        } else {
          const input = document.createElement('textarea');
          input.value = this.generatedLink;
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
        throw error;
      }
    },

    destroy() {
      if (this.writerRefreshHandler) {
        window.removeEventListener('focus', this.writerRefreshHandler);
      }
      if (this.writerRefreshTimer) {
        clearInterval(this.writerRefreshTimer);
      }
    },
  };
}
