/**
 * Globales Toast-System (NFR-UI-07, NFR-A11Y-03): eine einzelne Live-Region
 * am Fensterrand statt lokaler Erfolgsmeldungen je Dialog. Jede Komponente
 * ruft einfach showToast(message) auf - unabhängig davon, ob gerade ein
 * Dialog offen ist oder die Aktion vom Board/der Seitenliste selbst ausgeht.
 *
 * Mit `options.action` trägt der Toast eine Schaltfläche, etwa „Rückgängig“
 * nach dem Löschen. Solche Toasts bleiben länger stehen, damit man auf dem
 * Handy auch mit Handschuhen noch rechtzeitig tippt.
 */

const TOAST_DURATION_MS = 5000;
const ACTION_TOAST_DURATION_MS = 10000;
let nextId = 1;
// Außerhalb des reaktiven Zustands: Funktionen haben in Alpine-Proxys nichts verloren.
const toastActions = new Map();

/**
 * @param {string} message
 * @param {'success'|'error'} [variant]
 * @param {{ action?: { label: string, run: () => unknown } }} [options]
 */
export function showToast(message, variant = 'success', options = {}) {
  window.dispatchEvent(new CustomEvent('toast:show', {
    detail: { message, variant, action: options.action || null },
  }));
}

/**
 * Lösch-Toast mit „Rückgängig“. `restore` holt das Objekt über die API zurück;
 * scheitert das, erscheint eine Fehlermeldung statt eines stillen Verlusts.
 *
 * @param {string} message
 * @param {() => Promise<unknown>} restore
 */
export function showUndoToast(message, restore) {
  showToast(message, 'success', {
    action: {
      label: 'Rückgängig',
      run: async () => {
        try {
          await restore();
        } catch (error) {
          showToast(error?.message || 'Das Wiederherstellen ist fehlgeschlagen.', 'error');
        }
      },
    },
  });
}

export function toast() {
  return {
    items: [],

    init() {
      window.addEventListener('toast:show', (event) => {
        const { message, variant, action } = event.detail || {};
        if (!message) {
          return;
        }
        const id = nextId++;
        const hasAction = Boolean(action && typeof action.run === 'function');
        if (hasAction) {
          toastActions.set(id, action.run);
        }
        this.items.push({
          id,
          message,
          variant: variant === 'error' ? 'error' : 'success',
          actionLabel: hasAction ? String(action.label || 'Rückgängig') : '',
        });
        window.setTimeout(() => this.dismiss(id), hasAction ? ACTION_TOAST_DURATION_MS : TOAST_DURATION_MS);
      });
    },

    hasAction(item) {
      return item.actionLabel !== '';
    },

    runAction(id) {
      const run = toastActions.get(id);
      this.dismiss(id);
      if (run) {
        void run();
      }
    },

    dismiss(id) {
      toastActions.delete(id);
      this.items = this.items.filter((item) => item.id !== id);
    },
  };
}
