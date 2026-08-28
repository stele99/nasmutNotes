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
 * Admin-Dashboard: Nutzer mit Speicherbedarf, Kontingente, Löschen eines
 * Nutzers samt Daten und Aufräumen verwaister Bilder (FR-ADM-01..06).
 */
export function adminDashboard() {
  return {
    users: [],
    totals: {},
    orphans: { count: 0, bytes: 0, items: [] },
    defaultQuota: 0,
    maxAttachmentMb: 10,
    offlineAttachmentKb: 250,
    loading: true,
    busy: false,
    error: '',
    message: '',
    // Sprachnotizen: flache Felder statt eines verschachtelten Objekts, damit
    // x-model im CSP-Build ohne Ausdruckspfade auskommt. Das LLM selbst steht
    // unter den gemeinsamen KI-Einstellungen; hier bleibt das Audio-Modell.
    voiceEnabled: false,
    voiceHasApiKey: false,
    voiceApiKeyHint: '',
    voiceBaseUrl: '',
    voiceTranscribeModel: '',
    voiceLanguage: '',
    voicePostprocessEnabled: true,
    voicePostprocessReasoning: '',
    voicePostprocessPrompt: '',
    voiceLogPrompt: '',
    voiceMaxSeconds: 300,
    voiceMaxMb: 25,
    voiceQuickReasoning: '',
    voiceQuickPrompt: '',
    noteAiEnabled: false,
    noteAiHasApiKey: false,
    noteAiReasoning: '',
    noteAiPrompt: '',
    // Gemeinsame KI-Defaults: ein Modell für alle Bereiche + Reasoning-Vorgabe.
    aiDefaultModel: '',
    aiDefaultReasoning: '',
    // Desktop-Assistant (KI-Proxy für die Desktop-App).
    assistantEnabled: false,
    assistantHasApiKey: false,
    assistantApiKeyHint: '',
    assistantReasoning: '',
    // Modellkosten-Katalog und Verbrauch über alle Nutzer.
    aiCosts: [],
    costModel: '',
    costInput: '',
    costOutput: '',
    costCurrency: 'EUR',
    aiUsage: null,
    // Globale Diktier-Vorlagen (FR-VOICE): für alle Nutzer wählbar.
    voiceTemplates: [],
    voiceTemplateName: '',
    voiceTemplateInstruction: '',
    voiceTemplateVocabulary: '',
    editingVoiceTemplateId: null,

    async init() {
      await this.refresh();
    },

    async refresh() {
      this.loading = true;
      this.error = '';
      try {
        const data = await apiFetch('/api/admin/overview');
        this.users = data.users || [];
        this.totals = data.totals || {};
        this.orphans = data.orphans || { count: 0, bytes: 0, items: [] };
        this.defaultQuota = Number(data.default_quota_mb || 0);
        this.maxAttachmentMb = Number(data.max_attachment_mb || 10);
        this.offlineAttachmentKb = Number(data.offline_attachment_max_kb ?? 250);
        this.applyAiDefaults(data.ai_defaults || {});
        this.applyVoiceSettings(data.voice || {});
        this.applyNoteAiSettings(data.note_ai || {});
        this.applyAssistantSettings(data.assistant || {});
        this.aiCosts = data.ai_costs || [];
        this.voiceTemplates = data.voice_templates || [];
        await this.loadAiUsage();
      } catch (error) {
        this.error = error.message || 'Die Übersicht konnte nicht geladen werden.';
      } finally {
        this.loading = false;
      }
    },

    async loadAiUsage() {
      try {
        this.aiUsage = await apiFetch('/api/admin/ai-usage');
      } catch (error) {
        this.aiUsage = null;
      }
    },

    formatBytes(bytes) {
      return formatBytes(bytes);
    },

    /** Der Schlüssel selbst kommt nie zurück - nur der Hinweis auf sein Ende. */
    applyVoiceSettings(voice) {
      this.voiceEnabled = Boolean(voice.enabled);
      this.voiceHasApiKey = Boolean(voice.has_api_key);
      this.voiceApiKeyHint = voice.api_key_hint || '';
      this.voiceTranscribeModel = voice.transcribe_model || '';
      this.voiceLanguage = voice.language || '';
      this.voicePostprocessEnabled = voice.postprocess_enabled !== false;
      this.voicePostprocessReasoning = voice.postprocess_reasoning || '';
      this.voicePrompt = voice.postprocess_prompt || '';
      this.voiceLogPrompt = voice.log_prompt || '';
      this.voiceMaxSeconds = Number(voice.max_seconds || 300);
      this.voiceMaxMb = Number(voice.max_mb || 25);
      this.voiceQuickReasoning = voice.quick_reasoning || '';
      this.voiceQuickPrompt = voice.quick_prompt || '';
    },

    applyAiDefaults(defaults) {
      this.aiDefaultModel = defaults.model || '';
      this.aiDefaultReasoning = defaults.reasoning || '';
      this.voiceBaseUrl = defaults.base_url || '';
    },

    voiceStatusLabel() {
      if (!this.voiceEnabled) {
        return 'ausgeschaltet';
      }

      return this.voiceHasApiKey ? 'aktiv' : 'freigeschaltet, aber ohne OPENAI_KEY unwirksam';
    },

    voiceApiKeyLabel() {
      return this.voiceHasApiKey
        ? `aus der Serverkonfiguration übernommen (${this.voiceApiKeyHint})`
        : 'fehlt – OPENAI_KEY in der .env des Servers setzen';
    },

    async saveAiDefaults() {
      await this.run(async () => {
        const data = await apiFetch('/api/admin/settings/ai', {
          method: 'PATCH',
          body: JSON.stringify({
            model: this.aiDefaultModel,
            reasoning: this.aiDefaultReasoning,
            base_url: this.voiceBaseUrl,
          }),
        });
        this.applyAiDefaults(data.ai_defaults || {});
        this.message = 'Gemeinsame KI-Einstellungen gespeichert.';
      });
    },

    async saveVoiceSettings() {
      const payload = {
        enabled: this.voiceEnabled,
        transcribe_model: this.voiceTranscribeModel,
        language: this.voiceLanguage,
        postprocess_reasoning: this.voicePostprocessReasoning,
        postprocess_prompt: this.voicePrompt,
        log_prompt: this.voiceLogPrompt,
        max_seconds: Number(this.voiceMaxSeconds),
        max_mb: Number(this.voiceMaxMb),
      };
      await this.run(async () => {
        await apiFetch('/api/admin/settings/voice', {
          method: 'PATCH',
          body: JSON.stringify(payload),
        });
        this.message = 'Einstellungen für Sprachnotizen gespeichert.';
      });
    },

    async saveQuickSettings() {
      await this.run(async () => {
        await apiFetch('/api/admin/settings/voice', {
          method: 'PATCH',
          body: JSON.stringify({
            quick_reasoning: this.voiceQuickReasoning,
            quick_prompt: this.voiceQuickPrompt,
          }),
        });
        this.message = 'Einstellungen für NotesVoice gespeichert.';
      });
    },

    async resetVoicePrompt() {
      if (!window.confirm('Die Anweisung an das Modell auf die Standardfassung zurücksetzen?')) {
        return;
      }

      await this.run(async () => {
        await apiFetch('/api/admin/settings/voice', {
          method: 'PATCH',
          body: JSON.stringify({ postprocess_prompt: '', log_prompt: '', quick_prompt: '' }),
        });
        this.message = 'Die Standardanweisung wurde wiederhergestellt.';
      });
    },

    applyNoteAiSettings(settings) {
      this.noteAiEnabled = Boolean(settings.enabled);
      this.noteAiHasApiKey = Boolean(settings.has_api_key);
      this.noteAiReasoning = settings.reasoning || '';
      this.noteAiPrompt = settings.prompt || '';
    },

    noteAiStatusLabel() {
      if (!this.noteAiEnabled) {
        return 'ausgeschaltet';
      }

      return this.noteAiHasApiKey ? 'aktiv' : 'freigeschaltet, aber ohne OPENAI_KEY unwirksam';
    },

    async saveNoteAiSettings() {
      await this.run(async () => {
        const data = await apiFetch('/api/admin/settings/note-ai', {
          method: 'PATCH',
          body: JSON.stringify({
            enabled: this.noteAiEnabled,
            reasoning: this.noteAiReasoning,
            prompt: this.noteAiPrompt,
          }),
        });
        this.applyNoteAiSettings(data.note_ai || {});
        this.message = 'Einstellungen für die KI-Textüberarbeitung gespeichert.';
      });
    },

    async resetNoteAiPrompt() {
      if (!window.confirm('Die Anweisung für die Textüberarbeitung auf die Standardfassung zurücksetzen?')) {
        return;
      }
      await this.run(async () => {
        const data = await apiFetch('/api/admin/settings/note-ai', {
          method: 'PATCH',
          body: JSON.stringify({ prompt: '' }),
        });
        this.applyNoteAiSettings(data.note_ai || {});
        this.message = 'Die Standardanweisung wurde wiederhergestellt.';
      });
    },

    /** Desktop-Assistant: Proxy-Einstellungen für die Desktop-App. */
    applyAssistantSettings(assistant) {
      this.assistantEnabled = Boolean(assistant.enabled);
      this.assistantHasApiKey = Boolean(assistant.has_api_key);
      this.assistantApiKeyHint = assistant.api_key_hint || '';
      this.assistantReasoning = assistant.reasoning || '';
    },

    assistantStatusLabel() {
      if (!this.assistantEnabled) {
        return 'ausgeschaltet';
      }

      return this.assistantHasApiKey ? 'aktiv' : 'freigeschaltet, aber ohne OPENAI_KEY unwirksam';
    },

    assistantApiKeyLabel() {
      return this.assistantHasApiKey
        ? `Erkannt (${this.assistantApiKeyHint})`
        : 'Es fehlt OPENAI_KEY in der .env des Servers.';
    },

    async saveAssistantSettings() {
      await this.run(async () => {
        const data = await apiFetch('/api/admin/settings/assistant', {
          method: 'PATCH',
          body: JSON.stringify({
            enabled: this.assistantEnabled,
            reasoning: this.assistantReasoning,
          }),
        });
        this.applyAssistantSettings(data.assistant || {});
        this.message = 'Einstellungen für den Desktop-Assistant gespeichert.';
      });
    },

    /** Modellkosten: Euro je 1 Mio. Tokens, Input und Output getrennt. */
    async saveCost() {
      const model = this.costModel.trim();
      if (!model) {
        this.error = 'Bitte einen Modellnamen eingeben.';
        return;
      }
      await this.run(async () => {
        await apiFetch('/api/admin/model-costs', {
          method: 'POST',
          body: JSON.stringify({
            model,
            input_per_1m: this.costInput,
            output_per_1m: this.costOutput,
            currency: this.costCurrency,
          }),
        });
        this.costModel = '';
        this.costInput = '';
        this.costOutput = '';
        this.message = `Kosten für „${model}“ gespeichert.`;
      });
    },

    async deleteCost(cost) {
      if (!window.confirm(`Kostenpflege für „${cost.model}“ entfernen? Nutzungen dieses Modells kosten danach nichts mehr, bis ein Preis hinterlegt ist.`)) {
        return;
      }
      await this.run(async () => {
        await apiFetch(`/api/admin/model-costs/${encodeURIComponent(cost.model)}`, { method: 'DELETE' });
        this.message = `Kostenpflege für „${cost.model}“ entfernt.`;
      });
    },

    /** Globale Diktier-Vorlagen: legt an oder speichert die gerade bearbeitete. */
    async saveVoiceTemplate() {
      const name = this.voiceTemplateName.trim();
      const instruction = this.voiceTemplateInstruction.trim();
      if (!name || !instruction) {
        this.error = 'Bitte Name und Anweisung angeben.';
        this.message = '';
        return;
      }
      await this.run(async () => {
        const body = JSON.stringify({
          name,
          instruction,
          vocabulary: this.voiceTemplateVocabulary.trim(),
        });
        if (this.editingVoiceTemplateId) {
          await apiFetch(`/api/admin/voice-templates/${this.editingVoiceTemplateId}`, { method: 'PATCH', body });
        } else {
          await apiFetch('/api/admin/voice-templates', { method: 'POST', body });
        }
        this.cancelEditVoiceTemplate();
        this.message = `Vorlage „${name}“ gespeichert.`;
      });
    },

    startEditVoiceTemplate(template) {
      this.editingVoiceTemplateId = template.id;
      this.voiceTemplateName = template.name;
      this.voiceTemplateInstruction = template.instruction;
      this.voiceTemplateVocabulary = template.vocabulary || '';
      this.error = '';
    },

    cancelEditVoiceTemplate() {
      this.editingVoiceTemplateId = null;
      this.voiceTemplateName = '';
      this.voiceTemplateInstruction = '';
      this.voiceTemplateVocabulary = '';
    },

    async deleteVoiceTemplate(template) {
      if (!window.confirm(`Vorlage „${template.name}“ entfernen?`)) {
        return;
      }
      await this.run(async () => {
        await apiFetch(`/api/admin/voice-templates/${template.id}`, { method: 'DELETE' });
        if (this.editingVoiceTemplateId === template.id) {
          this.cancelEditVoiceTemplate();
        }
        this.message = `Vorlage „${template.name}“ entfernt.`;
      });
    },

    formatTokens(value) {
      return Number(value || 0).toLocaleString('de-DE');
    },

    formatCost(value, currency) {
      if (value === null || value === undefined) {
        return '–';
      }

      return `${Number(value).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency || 'EUR'}`;
    },

    usageLabel(user) {
      const used = formatBytes(user.total_bytes);

      return user.effective_quota_mb > 0 ? `${used} / ${user.effective_quota_mb} MB` : used;
    },

    /** Anteil am Kontingent in Prozent, gedeckelt für die Balkenbreite. */
    usagePercent(user) {
      if (!user.effective_quota_mb || user.effective_quota_mb <= 0) {
        return 0;
      }
      const quotaBytes = user.effective_quota_mb * 1024 * 1024;

      return Math.min(100, Math.round((Number(user.total_bytes || 0) / quotaBytes) * 100));
    },

    usageBarStyle(user) {
      const percent = this.usagePercent(user);
      const color = percent >= 90 ? 'var(--color-danger)' : 'var(--color-accent)';

      return `width: ${percent}%; background: ${color};`;
    },

    quotaLabel(user) {
      return user.storage_quota_mb === null
        ? `Standard (${this.defaultQuota > 0 ? `${this.defaultQuota} MB` : 'unbegrenzt'})`
        : `${user.storage_quota_mb} MB`;
    },

    async editQuota(user) {
      const current = user.storage_quota_mb === null ? '' : String(user.storage_quota_mb);
      const input = window.prompt(
        `Speicherkontingent für ${user.name || user.email} in MB.\n`
        + 'Leer lassen für den Standardwert, 0 für unbegrenzt.',
        current,
      );
      if (input === null) {
        return;
      }

      const trimmed = input.trim();
      if (trimmed !== '' && !/^\d+$/.test(trimmed)) {
        this.error = 'Bitte eine ganze Zahl in MB eingeben.';
        return;
      }

      await this.run(async () => {
        await apiFetch(`/api/admin/users/${user.id}/quota`, {
          method: 'PATCH',
          body: JSON.stringify({ storage_quota_mb: trimmed === '' ? null : Number(trimmed) }),
        });
        this.message = 'Kontingent gespeichert.';
      });
    },

    async editDefaultQuota() {
      const input = window.prompt(
        'Standard-Speicherkontingent in MB für alle Nutzer ohne eigenen Wert.\n0 bedeutet unbegrenzt.',
        String(this.defaultQuota),
      );
      if (input === null) {
        return;
      }
      const trimmed = input.trim();
      if (!/^\d+$/.test(trimmed)) {
        this.error = 'Bitte eine ganze Zahl in MB eingeben.';
        return;
      }

      await this.run(async () => {
        await apiFetch('/api/admin/settings/default-quota', {
          method: 'PATCH',
          body: JSON.stringify({ default_quota_mb: Number(trimmed) }),
        });
        this.message = 'Standardkontingent gespeichert.';
      });
    },

    async editMaxAttachment() {
      const input = window.prompt(
        'Maximale Größe je Dateianhang in MB (1 bis 2048).',
        String(this.maxAttachmentMb),
      );
      if (input === null) {
        return;
      }
      const trimmed = input.trim();
      if (!/^\d+$/.test(trimmed)) {
        this.error = 'Bitte eine ganze Zahl in MB eingeben.';
        return;
      }

      await this.run(async () => {
        await apiFetch('/api/admin/settings/max-attachment', {
          method: 'PATCH',
          body: JSON.stringify({ max_attachment_mb: Number(trimmed) }),
        });
        this.message = 'Obergrenze für Anhänge gespeichert.';
      });
    },

    offlineLimitLabel() {
      if (this.offlineAttachmentKb <= 0) {
        return 'aus (nichts wird vorgeladen)';
      }

      return this.offlineAttachmentKb >= 1024
        ? `${(this.offlineAttachmentKb / 1024).toFixed(1)} MB`
        : `${this.offlineAttachmentKb} KB`;
    },

    /**
     * Grenze, bis zu der Clients Anhänge und Bilder automatisch offline
     * vorhalten (FR-OFFLINE-06).
     */
    async editOfflineAttachmentLimit() {
      const input = window.prompt(
        'Bis zu welcher Größe (KB) sollen Anhänge und Bilder automatisch offline '
        + 'verfügbar sein?\nGrößere Dateien brauchen zum Öffnen eine Internetverbindung.\n'
        + '0 lädt nichts vor, Höchstwert 102400 (100 MB).',
        String(this.offlineAttachmentKb),
      );
      if (input === null) {
        return;
      }
      const trimmed = input.trim();
      if (!/^\d+$/.test(trimmed)) {
        this.error = 'Bitte eine ganze Zahl in KB eingeben.';
        return;
      }

      await this.run(async () => {
        await apiFetch('/api/admin/settings/offline-attachment', {
          method: 'PATCH',
          body: JSON.stringify({ offline_attachment_max_kb: Number(trimmed) }),
        });
        this.message = 'Offline-Limit gespeichert. Clients übernehmen es beim nächsten Abgleich.';
      });
    },

    /**
     * Zwei Rückfragen: Das Löschen entfernt sämtliche Inhalte des Nutzers und
     * ist nicht rückgängig zu machen.
     */
    async deleteUser(user) {
      const label = user.name || user.email;
      if (!window.confirm(
        `„${label}" endgültig löschen?\n\n`
        + `Dabei verschwinden ${user.page_count} Seite(n), ${user.task_count} Aufgabe(n) `
        + `und ${user.attachment_count} Bild(er) unwiderruflich.`,
      )) {
        return;
      }
      if (window.prompt(`Zur Bestätigung bitte die E-Mail-Adresse eingeben:\n${user.email}`) !== user.email) {
        this.error = 'Die Eingabe stimmte nicht mit der E-Mail-Adresse überein - es wurde nichts gelöscht.';
        return;
      }

      await this.run(async () => {
        const result = await apiFetch(`/api/admin/users/${user.id}`, { method: 'DELETE' });
        this.message = `„${label}" wurde gelöscht (${result.deleted_files} Datei(en) entfernt).`;
      });
    },

    async compressUserImages(user) {
      const label = user.name || user.email;
      if (Number(user.image_count || 0) === 0) {
        return;
      }
      if (!window.confirm(
        `Alle ${user.image_count} eingebetteten Bilder von „${label}“ komprimieren?\n\n`
        + 'Einstellung: Qualität 82 %, maximale Breite 1960 px. '
        + 'Die Originaldateien werden ersetzt und können nicht über Versionen wiederhergestellt werden.',
      )) {
        return;
      }

      await this.run(async () => {
        const result = await apiFetch(`/api/admin/users/${user.id}/compress-images`, { method: 'POST' });
        this.message = `${result.compressed} von ${result.images} Bild(ern) komprimiert · `
          + `${formatBytes(result.saved_bytes)} eingespart.`;
      });
    },

    async purgeOrphans() {
      if (this.orphans.count === 0) {
        return;
      }
      if (!window.confirm(
        `${this.orphans.count} verwaiste Datei(en) mit ${formatBytes(this.orphans.bytes)} löschen?\n\n`
        + 'Betroffen sind nur Bilder, die in keiner Notiz und in keiner Notizversion mehr vorkommen.',
      )) {
        return;
      }

      await this.run(async () => {
        const result = await apiFetch('/api/admin/attachments/purge-orphans', { method: 'POST' });
        this.message = `${result.count} Datei(en) entfernt, ${formatBytes(result.bytes)} freigegeben.`;
      });
    },

    async run(action) {
      this.busy = true;
      this.error = '';
      this.message = '';
      try {
        await action();
        await this.refresh();
      } catch (error) {
        this.error = error.message || 'Die Aktion ist fehlgeschlagen.';
      } finally {
        this.busy = false;
      }
    },
  };
}
