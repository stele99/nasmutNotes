import { apiFetch } from '../api.js';
import { diffNoteDocuments, documentToDiffBlocks } from '../noteHistoryDiff.js';
import * as db from './db.js';

/**
 * Aufbereitung eines blockierten Outbox-Eintrags für die Sync-Übersicht.
 *
 * Ein blockierter Eintrag allein sagt dem Nutzer wenig („Notiz #365“,
 * „Diese Freigabe ist nur lesend.“). Hier wird zusammengetragen, was er für
 * eine Entscheidung braucht: um welche Seite es geht und ob es sie noch gibt,
 * was lokal geändert wurde, was auf dem Server steht, eine verständliche
 * Erklärung und welche Auswege sinnvoll sind.
 *
 * `describeBlocked` ist rein (testbar), `loadBlockedDetails` holt die Daten.
 */

const KIND_LABELS = {
  'page.create': 'Neue Seite',
  'note.putContent': 'Notizinhalt',
  'task.create': 'Neue Aufgabe',
  'task.update': 'Aufgabe geändert',
  'task.delete': 'Aufgabe gelöscht',
  'log.createEntry': 'Neuer Logbuch-Eintrag',
  'log.updateEntry': 'Logbuch-Eintrag geändert',
  'log.deleteEntry': 'Logbuch-Eintrag gelöscht',
};

const TASK_FIELD_LABELS = {
  title: 'Titel',
  is_done: 'Erledigt',
  due_date: 'Fällig',
  responsible: 'Verantwortlich',
  description: 'Beschreibung',
  link: 'Link',
};

const NUMBER_FORMAT = new Intl.NumberFormat('de-DE', { maximumFractionDigits: 2 });
const DATE_TIME_FORMAT = new Intl.DateTimeFormat('de-DE', { dateStyle: 'short', timeStyle: 'short' });

export function kindLabel(type) {
  return KIND_LABELS[type] || 'Änderung';
}

function isNetworkError(error) {
  return !error?.status;
}

function formatDateTime(value) {
  const date = value ? new Date(value) : null;

  return date && !Number.isNaN(date.getTime()) ? DATE_TIME_FORMAT.format(date) : '—';
}

function formatTaskValue(field, value) {
  if (field === 'is_done') {
    return value ? 'Ja' : 'Nein';
  }
  if (value === null || value === undefined || value === '') {
    return '—';
  }
  if (field === 'due_date') {
    const [year, month, day] = String(value).split('-');
    return day ? `${day}.${month}.${year}` : String(value);
  }

  return String(value);
}

/** Eingabewert eines Logbuch-Dialogs (lokal) lesbar machen. */
function formatLocalLogValue(column, raw) {
  if (raw === null || raw === undefined || raw === '') {
    return '—';
  }
  if (typeof raw === 'object') {
    return raw.label || (raw.lat !== undefined ? `${Number(raw.lat).toFixed(5)}, ${Number(raw.lon).toFixed(5)}` : '—');
  }
  if (column?.is_numeric) {
    const number = Number(String(raw).replace(',', '.'));
    if (Number.isFinite(number)) {
      return formatNumber(column, number);
    }
  }

  return String(raw);
}

/** Anzeigewert eines Logbuch-Eintrags (Server) lesbar machen. */
function formatServerLogValue(column, value) {
  if (!value) {
    return '—';
  }
  if (value.number !== null && value.number !== undefined && column?.type !== 'rating') {
    return formatNumber(column, Number(value.number));
  }
  if (value.text) {
    return String(value.text);
  }
  if (value.lat !== null && value.lat !== undefined) {
    return `${Number(value.lat).toFixed(5)}, ${Number(value.lon).toFixed(5)}`;
  }
  if (value.number !== null && value.number !== undefined) {
    return String(value.number);
  }

  return '—';
}

function formatNumber(column, number) {
  if (column?.type === 'money') {
    return `${NUMBER_FORMAT.format(number)} €`;
  }
  if (column?.type === 'hours') {
    return `${NUMBER_FORMAT.format(number)} h`;
  }

  return NUMBER_FORMAT.format(number);
}

function documentText(content) {
  if (!content || typeof content !== 'object') {
    return '';
  }

  return documentToDiffBlocks(content).map((block) => block.text).filter(Boolean).join('\n\n');
}

function isConflictMessage(message) {
  return /dasselbe geändert/.test(String(message || ''));
}

function isGoneRecordMessage(message) {
  return /inzwischen gelöscht/.test(String(message || ''));
}

/**
 * @param {Record<string, unknown>} item Outbox-Eintrag
 * @param {{
 *   page?: Record<string, unknown>|null,
 *   pageStatus: 'ok'|'missing'|'unknown',
 *   server?: Record<string, unknown>|null,
 * }} context
 */
export function describeBlocked(item, context) {
  const type = String(item.type || '');
  const page = context.page || null;
  const payload = item.payload || {};
  const reason = String(item.last_error || 'Die Übertragung ist fehlgeschlagen.');
  const isNote = type === 'note.putContent' || type === 'page.create';
  const recordNoun = type.startsWith('task.') ? 'die Aufgabe' : 'der Eintrag';

  let pageState = context.pageStatus === 'missing' ? 'missing' : 'unknown';
  if (context.pageStatus === 'ok' && page) {
    if (page.deleted_at) {
      pageState = 'trashed';
    } else if (page.can_edit === false) {
      pageState = 'readonly';
    } else {
      pageState = 'ok';
    }
  }

  const pageTitle = page?.title || payload.page_title || payload.title || null;
  const encrypted = type === 'note.putContent' && payload.content?.zk === 1;

  // ---------------------------------------------------------- Erklärung
  let explanation;
  let retryLabel = 'Erneut versuchen';
  const actions = {
    open: Number(item.page_id) > 0 && pageState !== 'missing',
    restorePage: false,
    saveAsCopy: false,
    retry: true,
  };

  if (Number(item.page_id) < 0) {
    explanation = 'Diese Seite wurde offline angelegt und ist noch nicht auf dem Server. '
      + 'Ihr Anlegen ist gescheitert, deshalb können auch ihre Inhalte nicht übertragen werden.';
  } else if (pageState === 'missing') {
    explanation = 'Die Seite gibt es auf dem Server nicht mehr: Sie wurde endgültig gelöscht, oder dir wurde der '
      + 'Zugriff entzogen. Deine Änderung kann dort nicht mehr gespeichert werden.';
    actions.retry = false;
    actions.saveAsCopy = type === 'note.putContent' && !encrypted;
  } else if (pageState === 'trashed') {
    explanation = page?.is_shared
      ? 'Die Seite liegt beim Eigentümer im Papierkorb. Erst wenn er sie wiederherstellt, lässt sich deine Änderung übertragen.'
      : 'Die Seite liegt im Papierkorb. Stelle sie wieder her - deine Änderung wird dann übertragen.';
    actions.restorePage = !page?.is_shared;
    actions.retry = false;
    actions.saveAsCopy = type === 'note.putContent' && !encrypted;
  } else if (pageState === 'readonly') {
    explanation = 'Du darfst diese Seite nur noch lesen - die Freigabe wurde auf „Nur lesen“ gestellt. '
      + 'Deine Änderung kann dort nicht gespeichert werden.';
    actions.retry = false;
    actions.saveAsCopy = type === 'note.putContent' && !encrypted;
  } else if (isConflictMessage(reason)) {
    explanation = 'Jemand anderes hat dieselben Felder inzwischen anders geändert. Unten stehen beide Fassungen: '
      + '„Meine Fassung übernehmen“ überschreibt den Serverstand, „Serverstand behalten“ verwirft deine Änderung.';
    retryLabel = 'Meine Fassung übernehmen';
  } else if (isGoneRecordMessage(reason)) {
    explanation = `Auf dem Server wurde ${recordNoun} inzwischen gelöscht. Deine Änderung hat kein Ziel mehr. `
      + (type.startsWith('task.') ? 'Du kannst die Aufgabe bei Bedarf neu anlegen.' : 'Du kannst den Eintrag bei Bedarf neu erfassen.');
    actions.retry = false;
  } else {
    explanation = `Der Server hat die Änderung abgelehnt: ${reason} `
      + 'Prüfe die Seite; „Erneut versuchen“ sendet sie noch einmal, „Verwerfen“ behält den Serverstand.';
  }

  // ------------------------------------------------------------ Vergleich
  let compare = null;
  let localText = '';

  if (type === 'note.putContent') {
    localText = encrypted ? '' : documentText(payload.content);
    if (encrypted) {
      compare = { mode: 'note', text: 'Verschlüsselte Notiz - der Vergleich ist nur auf der entsperrten Notiz möglich.' };
    } else if (context.server?.content) {
      const serverEncrypted = context.server.content?.zk === 1;
      compare = serverEncrypted
        ? { mode: 'note', text: 'Auf dem Server ist die Notiz inzwischen verschlüsselt - ein Vergleich ist hier nicht möglich.' }
        : {
          mode: 'diff',
          rows: diffNoteDocuments(context.server.content, payload.content)
            .map((row, index) => ({ ...row, key: `${index}:${row.type}` })),
          serverMeta: context.server.last_editor_name
            ? `Serverstand vom ${formatDateTime(context.server.updated_at)} · ${context.server.last_editor_name}`
            : `Serverstand vom ${formatDateTime(context.server.updated_at)}`,
        };
    } else {
      compare = { mode: 'text', local: localText || '(leer)', server: null };
    }
  } else if (type === 'page.create') {
    compare = {
      mode: 'fields',
      rows: [{ label: 'Titel', local: String(payload.title || '—'), server: '— (noch nicht angelegt)', changed: true }],
    };
    localText = String(payload.title || '');
  } else if (type.startsWith('task.')) {
    const current = context.server || null;
    const fields = type === 'task.delete' ? {} : (payload.fields || {});
    const rows = Object.entries(fields)
      .filter(([field]) => Object.hasOwn(TASK_FIELD_LABELS, field))
      .map(([field, value]) => ({
        label: TASK_FIELD_LABELS[field],
        local: formatTaskValue(field, value),
        server: type === 'task.create' ? '— (noch nicht angelegt)' : (current ? formatTaskValue(field, current[field]) : '— (gelöscht)'),
        changed: type === 'task.create' || !current || formatTaskValue(field, value) !== formatTaskValue(field, current[field]),
      }));
    if (type === 'task.delete') {
      rows.push({
        label: 'Aktion',
        local: 'Löschen',
        server: current ? `vorhanden: „${current.title}“` : '— (bereits gelöscht)',
        changed: true,
      });
    }
    compare = { mode: 'fields', rows };
    localText = rows.map((row) => `${row.label}: ${row.local}`).join('\n');
  } else if (type.startsWith('log.')) {
    const columns = context.server?.columns || [];
    const entry = context.server?.entry || null;
    const rows = [];
    if (type !== 'log.deleteEntry') {
      rows.push({
        label: 'Zeitpunkt',
        local: formatDateTime(payload.occurred_at),
        server: type === 'log.createEntry' ? '— (noch nicht angelegt)' : (entry ? formatDateTime(entry.occurred_at) : '— (gelöscht)'),
        changed: !entry || formatDateTime(payload.occurred_at) !== formatDateTime(entry.occurred_at),
      });
      for (const [key, raw] of Object.entries(payload.values || {})) {
        const column = columns.find((candidate) => String(candidate.id) === String(key));
        const local = formatLocalLogValue(column, raw);
        const server = type === 'log.createEntry'
          ? '— (noch nicht angelegt)'
          : (entry ? formatServerLogValue(column, entry.values?.[String(key)]) : '— (gelöscht)');
        rows.push({
          label: column ? column.name : `Spalte ${key} (gelöscht)`,
          local,
          server,
          changed: local !== server,
        });
      }
    } else {
      rows.push({
        label: 'Aktion',
        local: 'Löschen',
        server: entry ? `vorhanden (${formatDateTime(entry.occurred_at)})` : '— (bereits gelöscht)',
        changed: true,
      });
    }
    compare = { mode: 'fields', rows };
    localText = rows.map((row) => `${row.label}: ${row.local}`).join('\n');
  }

  return {
    id: Number(item.id),
    page_id: Number(item.page_id),
    type,
    kind: kindLabel(type),
    pageTitle,
    pageState,
    explanation,
    reason,
    compare,
    localText,
    retryLabel,
    actions,
  };
}

/**
 * Holt Seite und Serverstand zu einem blockierten Eintrag und liefert die
 * Aufbereitung für die Oberfläche. Offline bleibt der Vergleich lokal.
 *
 * @param {number} outboxId
 */
export async function loadBlockedDetails(outboxId) {
  const item = await db.getOutbox(Number(outboxId));
  if (!item) {
    throw new Error('Der Eintrag ist nicht mehr vorhanden.');
  }
  const pageId = Number(item.page_id);
  const context = { page: await db.getPage(pageId).catch(() => null), pageStatus: 'unknown', server: null };

  if (pageId > 0 && navigator.onLine) {
    try {
      context.page = await apiFetch(`/api/pages/${pageId}`);
      context.pageStatus = 'ok';
    } catch (error) {
      if (error.status === 404) {
        context.pageStatus = 'missing';
      } else if (!isNetworkError(error)) {
        context.pageStatus = 'unknown';
      }
    }

    if (context.pageStatus === 'ok') {
      try {
        context.server = await loadServerState(item);
      } catch {
        context.server = null;
      }
    }
  }

  // Den Titel für später merken - offline ist er sonst nicht mehr abrufbar.
  if (context.page?.title && item.payload?.page_title !== context.page.title) {
    await db.patchOutbox(Number(item.id), { payload: { ...item.payload, page_title: context.page.title } }).catch(() => undefined);
  }

  return describeBlocked(item, context);
}

async function loadServerState(item) {
  const pageId = Number(item.page_id);
  if (item.type === 'note.putContent') {
    return apiFetch(`/api/pages/${pageId}/content`);
  }
  if (String(item.type).startsWith('task.') && item.type !== 'task.create') {
    const board = await apiFetch(`/api/pages/${pageId}/board`);
    for (const category of board.categories || []) {
      const task = (category.tasks || []).find((candidate) => Number(candidate.id) === Number(item.payload.task_id));
      if (task) {
        return task;
      }
    }
    return null;
  }
  if (String(item.type).startsWith('log.')) {
    const board = await apiFetch(`/api/pages/${pageId}/log`);
    const entry = item.type === 'log.createEntry'
      ? null
      : (board.entries || []).find((candidate) => Number(candidate.id) === Number(item.payload.entry_id)) || null;
    return { columns: board.columns || [], entry };
  }

  return null;
}

/**
 * Titel für die Übersichtsliste: lokal zwischengespeicherte Seite, der im
 * Eintrag gemerkte Titel oder - online - der Serverstand. Eine Seite, die es
 * nicht mehr gibt, wird als solche benannt statt als „Notiz #365“.
 *
 * @param {Record<string, unknown>} item
 * @returns {Promise<{ title: string, missing: boolean }>}
 */
export async function resolvePageTitle(item) {
  const pageId = Number(item.page_id);
  const cached = await db.getPage(pageId).catch(() => null);
  const known = cached?.title || item.payload?.page_title || item.payload?.title || null;
  if (known) {
    return { title: String(known), missing: false };
  }
  if (pageId > 0 && navigator.onLine) {
    try {
      const page = await apiFetch(`/api/pages/${pageId}`);
      await db.patchOutbox(Number(item.id), { payload: { ...item.payload, page_title: page.title } }).catch(() => undefined);
      return { title: String(page.title), missing: false };
    } catch (error) {
      if (error.status === 404) {
        return { title: 'Gelöschte oder nicht mehr freigegebene Seite', missing: true };
      }
    }
  }

  return { title: `Seite #${pageId}`, missing: false };
}
