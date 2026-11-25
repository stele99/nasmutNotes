import { apiFetch } from './api.js';

/**
 * MCP-Zugang (FR-MCP-01/02) im Einstellungen-Dialog: Token für KI-Clients
 * erzeugen, ihre Rechte ändern und widerrufen. Wird in offlineSettings
 * eingemischt, weil der Dialog dort lebt.
 */

async function copyToClipboard(text) {
  if (navigator.clipboard?.writeText) {
    await navigator.clipboard.writeText(text);
    return;
  }
  const input = document.createElement('textarea');
  input.value = text;
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

export function mcpEndpointUrl() {
  return `${window.location.origin}/mcp`;
}

/** Befehl für Claude Code; andere Clients bekommen die JSON-Konfiguration. */
export function mcpClaudeCommand(token) {
  return `claude mcp add --transport http nasmut-notes ${mcpEndpointUrl()} --header "Authorization: Bearer ${token}"`;
}

export function mcpJsonConfig(token) {
  return JSON.stringify({
    mcpServers: {
      'nasmut-notes': {
        type: 'http',
        url: mcpEndpointUrl(),
        headers: { Authorization: `Bearer ${token}` },
      },
    },
  }, null, 2);
}

export function mcpSettingsMixin(instance) {
  return {
    mcpTokens: [],
    mcpNotebooks: [],
    mcpTokensLoading: false,
    mcpError: '',
    mcpLabel: '',
    mcpWriteMode: 'none',
    mcpCanCreate: false,
    mcpCreating: false,
    mcpLastCreated: null,
    mcpCopied: '',
    mcpFieldIds: {
      label: `mcp-token-label-${instance}`,
      mode: `mcp-token-mode-${instance}`,
      create: `mcp-token-create-${instance}`,
    },

    /**
     * Den Klartext-Token vergessen: Er ist nur bis zum Schließen des Dialogs
     * sichtbar und lässt sich danach nirgends mehr abrufen - auch nicht durch
     * erneutes Öffnen der Einstellungen (FR-MCP-01).
     */
    forgetMcpSecret() {
      this.mcpLastCreated = null;
      this.mcpCopied = '';
    },

    mcpEndpoint() {
      return mcpEndpointUrl();
    },

    mcpLastCommand() {
      return this.mcpLastCreated ? mcpClaudeCommand(this.mcpLastCreated.token) : '';
    },

    mcpLastJson() {
      return this.mcpLastCreated ? mcpJsonConfig(this.mcpLastCreated.token) : '';
    },

    async loadMcpTokens() {
      this.mcpTokensLoading = true;
      this.mcpError = '';
      try {
        const data = await apiFetch('/api/profile/mcp-tokens');
        this.mcpTokens = data.mcp_tokens || [];
      } catch (error) {
        this.mcpError = error.message || 'MCP-Zugänge konnten nicht geladen werden.';
      } finally {
        this.mcpTokensLoading = false;
      }
    },

    async loadMcpNotebooks() {
      try {
        const data = await apiFetch('/api/profile/mcp-notebooks');
        this.mcpNotebooks = data.notebooks || [];
      } catch (error) {
        this.mcpError = error.message || 'Notizbücher konnten nicht geladen werden.';
      }
    },

    async changeMcpNotebook(notebook, event) {
      const writable = event.target.checked === true;
      this.mcpError = '';
      try {
        await apiFetch(`/api/notebooks/${notebook.id}/mcp-access`, {
          method: 'PUT',
          body: JSON.stringify({ writable }),
        });
      } catch (error) {
        event.target.checked = !writable;
        this.mcpError = error.message || 'Die Freigabe konnte nicht gespeichert werden.';
      }
      await this.loadMcpNotebooks();
    },

    mcpNotebookLabel(notebook) {
      return notebook.owner_name ? `${notebook.name} (von ${notebook.owner_name})` : notebook.name;
    },

    async createMcpToken() {
      if (this.mcpCreating) {
        return;
      }
      const label = this.mcpLabel.trim();
      if (!label) {
        this.mcpError = 'Bitte einen Namen für den Client angeben.';
        return;
      }
      this.mcpCreating = true;
      this.mcpError = '';
      this.mcpCopied = '';
      try {
        this.mcpLastCreated = await apiFetch('/api/profile/mcp-tokens', {
          method: 'POST',
          body: JSON.stringify({
            label,
            write_mode: this.mcpWriteMode,
            can_create: this.mcpCanCreate === true,
          }),
        });
        this.mcpLabel = '';
        await this.copyMcp('token');
        await this.loadMcpTokens();
      } catch (error) {
        this.mcpError = error.message || 'Der Token konnte nicht erstellt werden.';
      } finally {
        this.mcpCreating = false;
      }
    },

    /** Kopiert Token, Endpunkt, Befehl oder JSON-Konfiguration. */
    async copyMcp(what) {
      const token = this.mcpLastCreated?.token || '';
      const text = {
        token,
        endpoint: mcpEndpointUrl(),
        command: token ? mcpClaudeCommand(token) : '',
        json: token ? mcpJsonConfig(token) : '',
      }[what];
      if (!text) {
        return;
      }
      try {
        await copyToClipboard(text);
        this.mcpCopied = what;
      } catch (error) {
        this.mcpCopied = '';
        this.mcpError = 'Kopieren nicht möglich - bitte von Hand markieren.';
      }
    },

    mcpCopyLabel(what) {
      return this.mcpCopied === what ? 'Kopiert' : 'Kopieren';
    },

    async updateMcpToken(token, changes) {
      this.mcpError = '';
      try {
        const updated = await apiFetch(`/api/profile/mcp-tokens/${token.id}`, {
          method: 'PATCH',
          body: JSON.stringify(changes),
        });
        this.mcpTokens = this.mcpTokens.map((item) => (item.id === updated.id ? updated : item));
      } catch (error) {
        this.mcpError = error.message || 'Die Änderung konnte nicht gespeichert werden.';
        await this.loadMcpTokens();
      }
    },

    changeMcpTokenMode(token, event) {
      return this.updateMcpToken(token, { write_mode: event.target.value });
    },

    changeMcpTokenCreate(token, event) {
      return this.updateMcpToken(token, { can_create: event.target.checked === true });
    },

    isMcpTokenMode(token, mode) {
      return token.write_mode === mode;
    },

    async revokeMcpToken(token) {
      if (!window.confirm(`MCP-Zugang „${token.label}“ wirklich widerrufen? Der Client verliert sofort den Zugriff.`)) {
        return;
      }
      this.mcpError = '';
      try {
        await apiFetch(`/api/profile/mcp-tokens/${token.id}`, { method: 'DELETE' });
        if (this.mcpLastCreated?.id === token.id) {
          this.mcpLastCreated = null;
        }
        await this.loadMcpTokens();
      } catch (error) {
        this.mcpError = error.message || 'Der Zugang konnte nicht widerrufen werden.';
      }
    },

    mcpTokenSummary(token) {
      const format = (value) => new Intl.DateTimeFormat('de-DE', {
        dateStyle: 'medium',
        timeStyle: 'short',
      }).format(new Date(value));
      const used = token.last_used_at
        ? `Zuletzt genutzt: ${format(token.last_used_at)}`
        : `Erstellt: ${format(token.created_at)} · noch nicht genutzt`;

      return used;
    },
  };
}
