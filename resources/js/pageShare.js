import { apiFetch } from './api.js';

export function pageShare() {
  return {
    pageId: window.__CURRENT_PAGE_ID__,
    isShared: Boolean(window.__CURRENT_PAGE_IS_SHARED__),
    permission: 'read',
    shareDialogOpen: false,
    generatedLink: '',
    copyLabel: 'Kopieren',
    successMessage: '',
    errorMessage: '',
    generating: false,

    init() {
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
    },

    openShareDialog() {
      if (this.isShared) {
        return;
      }
      this.errorMessage = '';
      this.successMessage = '';
      this.generatedLink = '';
      this.copyLabel = 'Kopieren';
      this.shareDialogOpen = true;
    },

    closeShareDialog() {
      if (this.generating) {
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
        await this.copyLink();
        this.successMessage = 'Der Link wurde erzeugt und in die Zwischenablage kopiert.';
      } catch (error) {
        this.errorMessage = error.message || 'Der Freigabe-Link konnte nicht erzeugt werden.';
      } finally {
        this.generating = false;
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
  };
}
