/**
 * Ladebalken am oberen Rand (app.css, `html[data-loading]`).
 *
 * Zwei Phasen halten ihn: der Seitenwechsel selbst (pageList.js) und danach
 * das Nachladen des Inhalts durch die Seite (Notiz, Aufgabenliste, Logbuch).
 * Ohne die zweite Phase verschwand der Balken, sobald die leere Seite stand -
 * der Inhalt kam bei schlechtem Netz aber erst Sekunden später, und bis dahin
 * sah die Notiz einfach leer aus.
 *
 * Kurze Vorgänge sollen nicht flackern: Der Balken erscheint erst nach
 * DELAY_MS. Steht er schon (Seitenwechsel lief lange), bleibt er nahtlos
 * stehen, wenn die Seite ihr Nachladen anmeldet.
 */
const DELAY_MS = 150;

/** Scheitert eine Seite, ohne sich abzumelden, steht der Balken nicht ewig. */
const MAX_PHASE_MS = 30_000;

const phases = new Set();
const phaseLimits = new Map();
let timer = 0;

function update() {
  const root = document.documentElement;
  if (phases.size === 0) {
    window.clearTimeout(timer);
    timer = 0;
    delete root.dataset.loading;
    return;
  }
  if (root.dataset.loading === 'true' || timer) {
    return;
  }
  timer = window.setTimeout(() => {
    timer = 0;
    if (phases.size > 0) {
      root.dataset.loading = 'true';
    }
  }, DELAY_MS);
}

/**
 * @param {'navigation'|'content'} phase
 * @param {boolean} active
 */
export function setLoading(phase, active) {
  window.clearTimeout(phaseLimits.get(phase));
  phaseLimits.delete(phase);
  if (active) {
    phases.add(phase);
    phaseLimits.set(phase, window.setTimeout(() => setLoading(phase, false), MAX_PHASE_MS));
  } else {
    phases.delete(phase);
  }
  update();
}
