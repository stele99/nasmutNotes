// Die Bildschirmtastatur verkleinert auf iOS das Layout-Viewport nicht: `100dvh`
// und `window.innerHeight` behalten die volle Bildschirmhöhe. Die Anwendung
// reicht damit hinter die Tastatur, und `.workspace-main` - der Scroll-Container,
// an dessen Oberkante die Werkzeugleiste klebt - beginnt oberhalb des
// Bildschirms, weil Safari den sichtbaren Ausschnitt verschiebt, um die
// Einfügemarke freizustellen. Die klebende Leiste wandert dadurch aus dem Blick.
//
// Zwei Maße machen das für CSS sichtbar:
//
// `--keyboard-inset` - wie viel die Tastatur verdeckt. Damit endet die Anwendung
// über der Tastatur, statt dahinter zu reichen.
//
// `--viewport-shift` - wie weit Safari den sichtbaren Ausschnitt verschoben hat.
// Ausgeglichen wird damit ausschließlich die Position der klebenden Leiste, nicht
// die des Inhalts. Der Unterschied ist entscheidend: Rückt der Inhalt nach,
// gerät die Einfügemarke wieder tiefer, Safari verschiebt erneut und der
// Ausgleich schaukelt sich auf - gemessen wurden so schon 200 px Leerraum über
// der Leiste. Eine Werkzeugleiste löst dagegen kein Nachführen aus, der Wert
// bleibt stehen, wo er hingehört.

// Unterhalb dieses Werts stammt die Differenz von der ein- und ausfahrenden
// Adressleiste, nicht von der Tastatur. Ohne die Schwelle änderte die Anwendung
// bei jedem Scrollen ihre Höhe.
const KEYBOARD_MIN_INSET = 120;

// Safari meldet während der Tastatur-Animation Zwischenstände und schickt zum
// Endstand nicht zuverlässig ein weiteres Ereignis. Nach jedem Wechsel wird
// deshalb einmal nachgemessen.
const SETTLE_MS = 300;

// Ein Versatz größer als eine halbe Bildschirmhöhe wäre ein Messfehler; die
// Grenze hält die Leiste in jedem Fall im Bild.
const SHIFT_MAX = 400;

let currentShift = 0;

/**
 * Strecke, um die die Oberkante der Anwendung über dem sichtbaren Ausschnitt
 * liegt. notePage.js hält damit die Einfügemarke unterhalb der Werkzeugleiste,
 * die um denselben Betrag nachgerückt ist.
 */
export function viewportShift() {
  return currentShift;
}

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
  const probe = createProbe(win);

  const apply = () => {
    pending = false;
    // Ohne `offsetTop`: Verschiebt Safari den sichtbaren Ausschnitt, verdeckt die
    // Tastatur trotzdem unverändert viel. Wurde der Versatz mitgerechnet, fiel
    // die Summe unter die Schwelle und die Tastatur galt als eingeklappt - die
    // Anwendung reichte wieder hinter sie.
    const covered = win.innerHeight - viewport.height;
    const inset = covered >= KEYBOARD_MIN_INSET ? Math.round(covered) : 0;

    // Reine Messung, keine Regelung: Der Scroll-Container bleibt, wo er ist -
    // ausgeglichen wird nur die klebende Leiste in ihm. Deshalb ändert der
    // gesetzte Wert die Messung nicht und kann sich nicht aufschaukeln.
    const scroller = win.document.querySelector('.workspace-main');
    const shift = scroller && inset > 0
      ? Math.min(SHIFT_MAX, Math.max(0, Math.round(viewport.offsetTop - scroller.getBoundingClientRect().top)))
      : 0;
    currentShift = shift;

    root.style.setProperty('--keyboard-inset', `${inset}px`);
    root.style.setProperty('--viewport-shift', `${shift}px`);
    root.dataset.keyboard = inset > 0 ? 'open' : 'closed';
    probe?.(inset, shift);

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

/**
 * Diagnoseanzeige, nur mit `?vp=1` in der Adresse. Das Verhalten der
 * Bildschirmtastatur lässt sich weder im Simulator noch in der responsiven
 * Ansicht nachstellen - ohne die Zahlen vom Gerät bleibt jede Erklärung für
 * einen Rand oder eine abgeschnittene Leiste geraten.
 */
function createProbe(win) {
  const doc = win.document;
  if (!win.location?.search?.includes('vp=1') || !doc?.body) {
    return null;
  }

  const box = doc.createElement('div');
  box.style.cssText = [
    'position: fixed',
    'top: 0',
    'left: 0',
    'right: 0',
    'z-index: 2147483647',
    'padding: 4px 6px',
    'font: 11px/1.35 ui-monospace, monospace',
    'white-space: pre',
    'background: #000',
    'color: #0f0',
    'pointer-events: none',
  ].join(';');
  doc.body.appendChild(box);

  return (inset, shift) => {
    const viewport = win.visualViewport;
    const scroller = doc.querySelector('.workspace-main');
    const toolbar = doc.querySelector('.note-sticky-toolbar');
    const rect = (el) => (el ? Math.round(el.getBoundingClientRect().top) : '-');
    box.textContent = [
      `inner ${win.innerHeight}  vv ${Math.round(viewport.height)}  off ${Math.round(viewport.offsetTop)}`,
      `page ${Math.round(viewport.pageTop)}  scrollY ${Math.round(win.scrollY)}`,
      `inset ${inset}  shift ${shift}`,
      `main ${rect(scroller)}  toolbar ${rect(toolbar)}  scroll ${Math.round(scroller?.scrollTop ?? -1)}`,
    ].join('\n');
  };
}
