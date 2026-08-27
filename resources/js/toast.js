/**
 * Globales Toast-System (NFR-UI-07, NFR-A11Y-03): eine einzelne Live-Region
 * am Fensterrand statt lokaler Erfolgsmeldungen je Dialog. Jede Komponente
 * ruft einfach showToast(message) auf - unabhängig davon, ob gerade ein
 * Dialog offen ist oder die Aktion vom Board/der Seitenliste selbst ausgeht.
 */

const TOAST_DURATION_MS = 5000;
let nextId = 1;

export function showToast(message, variant = 'success') {
  window.dispatchEvent(new CustomEvent('toast:show', { detail: { message, variant } }));
}

export function toast() {
  return {
    items: [],

    init() {
      window.addEventListener('toast:show', (event) => {
        const { message, variant } = event.detail || {};
        if (!message) {
          return;
        }
        const id = nextId++;
        this.items.push({ id, message, variant: variant === 'error' ? 'error' : 'success' });
        window.setTimeout(() => this.dismiss(id), TOAST_DURATION_MS);
      });
    },

    dismiss(id) {
      this.items = this.items.filter((item) => item.id !== id);
    },
  };
}
