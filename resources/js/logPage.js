import { apiFetch } from './api.js';
import { voiceRecorderMixin, voiceFormData } from './voice.js';
import { locationMapUrl, parseLocationInput, requestLocation } from './geo.js';
import { consumeNewPageTitleEdit } from './newPageTitle.js';
import { pageLocationMixin } from './pageLocation.js';

/**
 * Logbuch-Seite (FR-LOG-01..09): Einträge mit Zeitpunkt und frei definierten
 * Spalten. Neueste stehen oben, sortiert werden kann nach jeder Spalte.
 */

const DATE_TIME_FORMAT = new Intl.DateTimeFormat('de-DE', { dateStyle: 'short', timeStyle: 'short' });
const NUMBER_FORMAT = new Intl.NumberFormat('de-DE', { maximumFractionDigits: 2 });
const MONEY_FORMAT = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' });

/** Ortszeit im Format, das ein `datetime-local`-Feld erwartet. */
function toLocalInput(value) {
  const date = value ? new Date(value) : new Date();
  if (Number.isNaN(date.getTime())) {
    return toLocalInput(null);
  }
  const pad = (part) => String(part).padStart(2, '0');

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
    + `T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function fromLocalInput(value) {
  const date = new Date(value);

  return Number.isNaN(date.getTime()) ? new Date().toISOString() : date.toISOString();
}

export function logPage() {
  return {
    ...voiceRecorderMixin(),
    ...pageLocationMixin(),
    pageId: null,
    pageTitle: '',
    savedPageTitle: '',
    editingPageTitle: false,
    savingPageTitle: false,
    canEditPage: true,
    columns: [],
    entries: [],
    types: [],
    sort: 'occurred_at',
    direction: 'desc',
    entryCount: 0,
    loading: true,
    error: '',

    entryDialogOpen: false,
    editingEntryId: null,
    entryTime: '',
    entryValues: {},
    // Koordinaten der geladenen Ortsspalten: Im Feld steht die Anschrift, die
    // Lage darf beim Speichern trotzdem nicht verloren gehen.
    entryCoordinates: {},
    entryBusy: false,
    entryError: '',
    locatingColumnId: null,

    columnDialogOpen: false,
    newColumnName: '',
    newColumnType: 'text',
    columnBusy: false,
    columnError: '',

    async init() {
      const root = this.$root;
      this.pageId = Number(root?.dataset.pageId || window.__CURRENT_PAGE_ID__ || 0);
      this.pageTitle = root?.dataset.pageTitle || window.__CURRENT_PAGE_TITLE__ || '';
      this.canEditPage = root?.dataset.pageCanEdit
        ? root.dataset.pageCanEdit === '1'
        : Boolean(window.__CURRENT_PAGE_CAN_EDIT__);
      this.savedPageTitle = this.pageTitle;
      this.initPageLocation(root);

      // Der Vorschlagstitel einer frisch angelegten Seite ist gleich
      // überschreibbar - wie bei Notizen.
      if (this.canEditPage && consumeNewPageTitleEdit(this.pageId)) {
        this.startEditingPageTitle(true);
      }

      await this.load();
    },

    // ------------------------------------------------------------ Seitentitel

    startEditingPageTitle(selectAll) {
      if (!this.canEditPage) {
        return;
      }
      this.editingPageTitle = true;
      this.$nextTick(() => {
        const input = this.$refs.titleInput;
        if (!input) {
          return;
        }
        input.focus();
        // Strikter Vergleich: Alpine übergibt bei `@click="startEditingPageTitle"`
        // das Event als erstes Argument, das darf nicht als „markieren" gelten.
        if (selectAll === true) {
          input.select();
        }
      });
    },

    async savePageTitle() {
      if (this.savingPageTitle) {
        return;
      }
      const title = this.pageTitle.trim();
      if (!title) {
        this.cancelPageTitleEdit();
        return;
      }
      if (title === this.savedPageTitle) {
        this.editingPageTitle = false;
        return;
      }

      this.savingPageTitle = true;
      try {
        const page = await apiFetch(`/api/pages/${this.pageId}`, {
          method: 'PATCH',
          body: JSON.stringify({ title }),
        });
        this.pageTitle = page.title;
        this.savedPageTitle = page.title;
        document.title = page.title;
        this.$dispatch('pages-changed');
      } catch (error) {
        this.error = error.message || 'Der Titel konnte nicht gespeichert werden.';
        this.pageTitle = this.savedPageTitle;
      } finally {
        this.savingPageTitle = false;
        this.editingPageTitle = false;
      }
    },

    cancelPageTitleEdit() {
      this.pageTitle = this.savedPageTitle;
      this.editingPageTitle = false;
    },

    async load() {
      if (!this.pageId) {
        this.loading = false;
        return;
      }

      this.loading = true;
      this.error = '';
      try {
        const query = new URLSearchParams({ sort: this.sort, direction: this.direction });
        const data = await apiFetch(`/api/pages/${this.pageId}/log?${query.toString()}`);
        this.columns = data.columns || [];
        this.entries = data.entries || [];
        this.types = data.types || [];
        this.entryCount = Number(data.entry_count || 0);
        this.sort = data.sort;
        this.direction = data.direction;
      } catch (error) {
        this.error = navigator.onLine
          ? (error.message || 'Das Logbuch konnte nicht geladen werden.')
          : 'Logbücher sind offline nicht verfügbar.';
      } finally {
        this.loading = false;
      }
    },

    /** Erneuter Klick auf dieselbe Spalte dreht die Richtung um. */
    async sortBy(key) {
      const next = String(key);
      this.direction = this.sort === next && this.direction === 'desc' ? 'asc' : 'desc';
      if (this.sort !== next) {
        this.direction = next === 'occurred_at' ? 'desc' : 'asc';
      }
      this.sort = next;
      await this.load();
    },

    isSortedBy(key) {
      return this.sort === String(key);
    },

    sortIndicator(key) {
      if (!this.isSortedBy(key)) {
        return '';
      }

      return this.direction === 'asc' ? '▲' : '▼';
    },

    // ---------------------------------------------------------------- Anzeige

    entryTimeLabel(entry) {
      return DATE_TIME_FORMAT.format(new Date(entry.occurred_at));
    },

    cellValue(entry, column) {
      return entry.values?.[String(column.id)] || null;
    },

    cellLabel(entry, column) {
      if (column.type === 'user') {
        return entry.created_by_name || '';
      }
      const value = this.cellValue(entry, column);
      if (!value) {
        return '';
      }

      if (column.type === 'money') {
        return value.number === null ? '' : MONEY_FORMAT.format(value.number);
      }
      if (column.type === 'hours') {
        return value.number === null ? '' : `${NUMBER_FORMAT.format(value.number)} h`;
      }
      if (column.type === 'number') {
        return value.number === null ? '' : NUMBER_FORMAT.format(value.number);
      }
      if (column.type === 'location') {
        return value.text || this.coordinateLabel(value);
      }

      return value.text || '';
    },

    coordinateLabel(value) {
      if (value?.lat === null || value?.lat === undefined) {
        return '';
      }

      return `${Number(value.lat).toFixed(2)}, ${Number(value.lon).toFixed(2)}`;
    },

    /** Ortsspalten verlinken auf die Karte, sofern Koordinaten vorliegen. */
    cellMapUrl(entry, column) {
      const value = this.cellValue(entry, column);

      return value && value.lat !== null && value.lat !== undefined ? locationMapUrl(value) : '';
    },

    hasCellMapUrl(entry, column) {
      return this.cellMapUrl(entry, column) !== '';
    },

    /** In der Zelle steht die Anschrift, die Koordinaten stehen im Tooltip. */
    cellTitle(entry, column) {
      const value = this.cellValue(entry, column);
      if (!value || value.lat === null || value.lat === undefined) {
        return '';
      }

      return `${this.coordinateLabel(value)} · auf der Karte öffnen`;
    },

    /** Summe einer Zahlenspalte über die geladenen Einträge. */
    columnTotal(column) {
      if (!column.is_numeric) {
        return '';
      }

      const total = this.entries.reduce((sum, entry) => {
        const value = this.cellValue(entry, column);

        return sum + (value && value.number !== null ? Number(value.number) : 0);
      }, 0);

      if (total === 0) {
        return '';
      }
      if (column.type === 'money') {
        return MONEY_FORMAT.format(total);
      }

      return column.type === 'hours' ? `${NUMBER_FORMAT.format(total)} h` : NUMBER_FORMAT.format(total);
    },

    /** Zeitspalte + eigene Spalten + Aktionsspalte. */
    columnSpan() {
      return this.columns.length + 2;
    },

    hasTotals() {
      return this.columns.some((column) => column.is_numeric);
    },

    entryCountLabel() {
      if (this.loading) {
        return 'Lädt…';
      }
      const shown = this.entries.length;

      return shown < this.entryCount
        ? `${shown} von ${this.entryCount} Einträgen`
        : `${shown} ${shown === 1 ? 'Eintrag' : 'Einträge'}`;
    },

    // ------------------------------------------------------------- Einträge

    openNewEntry() {
      if (!this.canEditPage) {
        return;
      }
      this.editingEntryId = null;
      this.entryTime = toLocalInput(null);
      this.entryValues = {};
      this.entryCoordinates = {};
      this.entryError = '';
      this.entryDialogOpen = true;
    },

    openEntry(entry) {
      if (!this.canEditPage) {
        return;
      }
      this.editingEntryId = entry.id;
      this.entryTime = toLocalInput(entry.occurred_at);
      this.entryError = '';

      const values = {};
      const coordinates = {};
      this.columns.forEach((column) => {
        if (column.type === 'user') {
          return;
        }
        const value = entry.values?.[String(column.id)];
        values[String(column.id)] = value ? this.editableValue(column, value) : '';
        if (value && value.lat !== null && value.lat !== undefined) {
          coordinates[String(column.id)] = { lat: value.lat, lon: value.lon };
        }
      });
      this.entryValues = values;
      this.entryCoordinates = coordinates;
      this.entryDialogOpen = true;
    },

    /** Der Wert, wie er im Eingabefeld stehen soll. */
    editableValue(column, value) {
      if (column.is_numeric) {
        return value.number === null ? '' : String(value.number);
      }
      if (column.type === 'location') {
        return value.text || this.coordinateLabel(value);
      }

      return value.text || '';
    },

    closeEntryDialog() {
      this.entryDialogOpen = false;
      this.entryBusy = false;
      this.entryError = '';
      this.locatingColumnId = null;
    },

    // Dynamische Schlüssel gehen im CSP-Build nicht über x-model, deshalb
    // Lesen und Schreiben über Methoden.
    valueInput(column) {
      return this.entryValues[String(column.id)] ?? '';
    },

    setValueInput(column, value) {
      this.entryValues = { ...this.entryValues, [String(column.id)]: value };
    },

    onValueInput(column, event) {
      this.setValueInput(column, event.target.value);
    },

    inputType(column) {
      if (column.type === 'time') {
        return 'time';
      }

      return column.is_numeric ? 'number' : 'text';
    },

    inputStep(column) {
      if (column.type === 'money') {
        return '0.01';
      }

      return column.type === 'hours' ? '0.25' : 'any';
    },

    inputPlaceholder(column) {
      if (column.type === 'location') {
        return 'Ort, Koordinaten oder Kartenlink';
      }
      if (column.type === 'money') {
        return '0,00';
      }

      return '';
    },

    isLocationColumn(column) {
      return column.type === 'location';
    },

    hasLocationColumn() {
      return this.columns.some((column) => column.type === 'location');
    },

    /** Ortsspalte mit dem aktuellen Standort füllen. */
    async useCurrentLocationFor(column) {
      this.locatingColumnId = column.id;
      this.entryError = '';
      try {
        const location = await requestLocation();
        if (!location) {
          this.entryError = 'Der Standort konnte nicht ermittelt werden.';
          return;
        }
        // Die Anschrift dazu sucht der Server beim Speichern (FR-NOTE-26).
        this.setValueInput(column, `${location.lat.toFixed(6)}, ${location.lon.toFixed(6)}`);
        this.entryCoordinates = {
          ...this.entryCoordinates,
          [String(column.id)]: { lat: location.lat, lon: location.lon },
        };
      } finally {
        this.locatingColumnId = null;
      }
    },

    isLocating(column) {
      return this.locatingColumnId === column.id;
    },

    /**
     * Ortsspalte über die Karte füllen (FR-LOG-05): dieselbe Auswahl wie beim
     * Aufnahmeort der Seite - Adresssuche, Karte, aktueller Standort. Anders
     * als jener hängt die Spalte am Eintrag, nicht an der Seite; geteilte
     * Seiten mit Schreibrecht dürfen sie deshalb setzen.
     */
    openLocationPickerForColumn(column) {
      if (!this.canEditPage || this.entryBusy) {
        return;
      }
      const known = this.entryCoordinates[String(column.id)] || null;
      this.openLocationPicker(known);
      this.locationPickerTarget = column.id;
    },

    /**
     * Ohne Ziel bleibt es beim Aufnahmeort der Seite (Vorgabe des Bausteins),
     * mit Ziel wandert der Ort in die Ortsspalte des offenen Eintrags. Ein
     * bereits eingetragener Ortsname bleibt stehen - die Koordinaten
     * ersetzen ihn nicht, sondern präzisieren ihn (siehe payloadValue).
     */
    async applyPickedLocation(location) {
      const columnId = this.locationPickerTarget;
      if (columnId === null) {
        await this.saveLocation(location);
        return;
      }

      const column = this.columns.find((item) => item.id === columnId);
      if (column) {
        this.setValueInput(column, `${location.lat.toFixed(6)}, ${location.lon.toFixed(6)}`);
        this.entryCoordinates = {
          ...this.entryCoordinates,
          [String(columnId)]: { lat: location.lat, lon: location.lon },
        };
      }
      this.closeLocationDialog();
    },

    /**
     * Ortsspalten dürfen sowohl Koordinaten als auch einen Ortsnamen tragen;
     * erkennbare Koordinaten werden getrennt abgelegt, damit die Karte
     * verlinkt werden kann. Steht im Feld eine Anschrift, bleiben die zuvor
     * erfassten Koordinaten erhalten - auch wenn der Name geändert wird.
     */
    payloadValue(column) {
      const raw = String(this.valueInput(column) ?? '').trim();
      if (raw === '') {
        return '';
      }
      if (column.type !== 'location') {
        return raw;
      }

      const parsed = parseLocationInput(raw) || this.entryCoordinates[String(column.id)];

      return parsed ? { lat: parsed.lat, lon: parsed.lon, label: raw } : { label: raw };
    },

    async saveEntry() {
      if (!this.canEditPage || this.entryBusy) {
        return;
      }

      const values = {};
      this.columns.forEach((column) => {
        if (column.type !== 'user') {
          values[String(column.id)] = this.payloadValue(column);
        }
      });

      this.entryBusy = true;
      this.entryError = '';
      try {
        const payload = { occurred_at: fromLocalInput(this.entryTime), values };
        if (this.editingEntryId) {
          await apiFetch(`/api/log-entries/${this.editingEntryId}`, {
            method: 'PATCH',
            body: JSON.stringify(payload),
          });
        } else {
          await apiFetch(`/api/pages/${this.pageId}/log/entries`, {
            method: 'POST',
            body: JSON.stringify(payload),
          });
        }
        this.closeEntryDialog();
        await this.load();
        this.$dispatch('pages-changed');
      } catch (error) {
        this.entryError = error.message || 'Der Eintrag konnte nicht gespeichert werden.';
      } finally {
        this.entryBusy = false;
      }
    },

    async deleteEntry() {
      if (!this.editingEntryId || !window.confirm('Diesen Eintrag löschen?')) {
        return;
      }

      this.entryBusy = true;
      try {
        await apiFetch(`/api/log-entries/${this.editingEntryId}`, { method: 'DELETE' });
        this.closeEntryDialog();
        await this.load();
      } catch (error) {
        this.entryError = error.message || 'Der Eintrag konnte nicht gelöscht werden.';
      } finally {
        this.entryBusy = false;
      }
    },

    // -------------------------------------------------------------- Spalten

    openColumnDialog() {
      if (!this.canEditPage) {
        return;
      }
      this.newColumnName = '';
      this.newColumnType = 'text';
      this.columnError = '';
      this.columnDialogOpen = true;
    },

    closeColumnDialog() {
      this.columnDialogOpen = false;
      this.columnBusy = false;
      this.columnError = '';
    },

    async addColumn() {
      const name = this.newColumnName.trim();
      if (name === '') {
        this.columnError = 'Bitte einen Spaltennamen eingeben.';
        return;
      }

      await this.runColumnAction(async () => {
        await apiFetch(`/api/pages/${this.pageId}/log/columns`, {
          method: 'POST',
          body: JSON.stringify({ name, type: this.newColumnType }),
        });
        this.newColumnName = '';
      });
    },

    async renameColumn(column) {
      const name = window.prompt('Neuer Spaltenname', column.name);
      if (name === null || name.trim() === '' || name === column.name) {
        return;
      }

      await this.runColumnAction(async () => {
        await apiFetch(`/api/log-columns/${column.id}`, {
          method: 'PATCH',
          body: JSON.stringify({ name: name.trim() }),
        });
      });
    },

    async moveColumn(column, direction) {
      await this.runColumnAction(async () => {
        await apiFetch(`/api/log-columns/${column.id}`, {
          method: 'PATCH',
          body: JSON.stringify({ move: direction }),
        });
      });
    },

    async removeColumn(column) {
      if (!window.confirm(
        `Spalte „${column.name}" löschen? Die darin erfassten Werte gehen verloren.`,
      )) {
        return;
      }

      await this.runColumnAction(async () => {
        await apiFetch(`/api/log-columns/${column.id}`, { method: 'DELETE' });
      });
    },

    async runColumnAction(action) {
      this.columnBusy = true;
      this.columnError = '';
      try {
        await action();
        await this.load();
      } catch (error) {
        this.columnError = error.message || 'Die Spalte konnte nicht geändert werden.';
      } finally {
        this.columnBusy = false;
      }
    },

    typeLabel(column) {
      return this.types.find((type) => type.value === column.type)?.label || column.type;
    },

    // --------------------------------------------------------------- Diktat

    /**
     * Diktierter Eintrag (FR-LOG-08): Der Server verteilt das Transkript auf
     * die Spalten. Die Ortszeit des Geräts geht mit, damit „gestern früh"
     * richtig gedeutet wird.
     */
    async handleVoiceRecording(recording) {
      const body = voiceFormData(recording);
      body.append('now', new Date().toISOString());

      // Ortsspalten füllt der Ortungsdienst des Geräts, nicht das Modell
      // (FR-LOG-11). Ohne Ortung bleibt die Spalte leer.
      let locationHint = '';
      if (this.hasLocationColumn()) {
        const location = await requestLocation();
        if (location) {
          body.append('lat', String(location.lat));
          body.append('lon', String(location.lon));
        } else {
          locationHint = ' Der Standort war nicht zu ermitteln.';
        }
      }

      const data = await apiFetch(`/api/pages/${this.pageId}/log/voice`, { method: 'POST', body });
      await this.load();
      this.voiceNotice = (data.transcript ? `Erfasst: „${data.transcript}"` : 'Eintrag erfasst.') + locationHint;
      this.$dispatch('pages-changed');
    },

    destroy() {
      this.destroyPageLocation();
      this.cancelVoice();
    },
  };
}
