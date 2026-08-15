// Die Bildschirmtastatur verkleinert auf iOS das Layout-Viewport nicht: `100dvh`
// und `window.innerHeight` behalten die volle Bildschirmhöhe. Die Anwendung
// reicht damit hinter die Tastatur, und `.workspace-main` - der Scroll-Container,
// an dessen Oberkante Notizkopf und Werkzeugleiste kleben - beginnt oberhalb des
// Bildschirms, weil Safari den sichtbaren Ausschnitt nach oben schiebt, um die
// Einfügemarke freizustellen. Die klebenden Leisten wandern dadurch aus dem
// Blick; bei langen Notizen besonders deutlich, weil Safari dort um die volle
// Tastaturhöhe schiebt.
//
// Dieses Maß macht die Tastatur für CSS sichtbar. app.css zieht die Anwendung
// damit auf den sichtbaren Ausschnitt zusammen. Damit entfällt für Safari der
// Grund zu verschieben - der Versatz muss deshalb nicht ausgeglichen werden,
// ein Ausgleich bliebe im Gegenteil als Leerraum am oberen Rand stehen.

// Unterhalb dieses Werts stammt die Differenz von der ein- und ausfahrenden
// Adressleiste, nicht von der Tastatur. Ohne die Schwelle änderte die Anwendung
// bei jedem Scrollen ihre Höhe.
const KEYBOARD_MIN_INSET = 120;

// Safari meldet während der Tastatur-Animation Zwischenstände und schickt zum
// Endstand nicht zuverlässig ein weiteres Ereignis. Nach jedem Wechsel wird
// deshalb einmal nachgemessen.
const SETTLE_MS = 300;

export function initViewportMetrics(win = globalThis.window) {
  const viewport = win?.visualViewport;
  const root = win?.document?.documentElement;
  // Ohne Visual-Viewport-API (ältere Browser) bleibt die Variable ungesetzt; der
  // Fallback-Wert in app.css ergibt dann das bisherige Verhalten.
  if (!viewport || !root) {
    return;
  }

  let pending = false;
  let settleTimer = 0;
  let open = false;

  const apply = () => {
    pending = false;
    const covered = win.innerHeight - viewport.height - viewport.offsetTop;
    const inset = covered >= KEYBOARD_MIN_INSET ? Math.round(covered) : 0;

    root.style.setProperty('--keyboard-inset', `${inset}px`);
    root.dataset.keyboard = inset > 0 ? 'open' : 'closed';

    if (open !== inset > 0) {
      open = inset > 0;
      win.clearTimeout(settleTimer);
      settleTimer = win.setTimeout(apply, SETTLE_MS);
    }
  };

  // Während die Tastatur einfährt, feuern `resize` und `scroll` in dichter
  // Folge. Ein Frame Sammelzeit hält daraus einen einzigen Stilwechsel.
  const schedule = () => {
    if (pending) {
      return;
    }
    pending = true;
    win.requestAnimationFrame(apply);
  };

  viewport.addEventListener('resize', schedule);
  viewport.addEventListener('scroll', schedule);
  win.addEventListener('orientationchange', schedule);
  apply();
}
