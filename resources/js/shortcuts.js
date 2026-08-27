/**
 * Globale Tastaturkürzel außerhalb des Editors (FR-SRCH-02, NFR-UI-12):
 * Strg/Cmd+K öffnet die Suche, „?" die Kürzelübersicht. Beide sind
 * dokumentweit gebunden, weil es dafür keinen naheliegenden Container gibt -
 * anders als z. B. die Editor-Kürzel, die TipTap selbst innerhalb des
 * `contenteditable`-Bereichs verwaltet.
 */

function isEditableTarget(target) {
  if (!(target instanceof HTMLElement)) {
    return false;
  }
  if (['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)) {
    return true;
  }

  return target.isContentEditable;
}

function focusSidebarSearch() {
  const inputs = Array.from(document.querySelectorAll('.sidebar-search'));
  const visibleInput = inputs.find((el) => el.offsetParent !== null);
  if (visibleInput) {
    visibleInput.focus();
    visibleInput.select();
    return;
  }

  // Mobil ist die Seitenleiste zunächst ausgeblendet (eigene Ebene) - erst
  // die Seitenauswahl zeigen, dann fokussieren.
  const shellEl = document.querySelector('.workspace-shell');
  const shell = shellEl && window.Alpine ? window.Alpine.$data(shellEl) : null;
  if (shell && typeof shell.showPages === 'function') {
    shell.showPages();
  }
  requestAnimationFrame(() => {
    const input = document.querySelector('.sidebar-search');
    input?.focus();
    input?.select();
  });
}

function handleGlobalShortcut(event) {
  if ((event.ctrlKey || event.metaKey) && !event.altKey && event.key.toLowerCase() === 'k') {
    event.preventDefault();
    focusSidebarSearch();
    return;
  }

  if (
    event.key === '?'
    && !event.ctrlKey
    && !event.metaKey
    && !event.altKey
    && !isEditableTarget(event.target)
  ) {
    event.preventDefault();
    window.dispatchEvent(new CustomEvent('shortcuts:open'));
  }
}

export function initGlobalShortcuts() {
  document.addEventListener('keydown', handleGlobalShortcut);
}
