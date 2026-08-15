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
// damit auf den sichtbaren Ausschnitt zusammen. Das nimmt Safari meist schon den
// Grund zu verschieben - meist, nicht immer. Der Rest wird deshalb gemessen
// statt gerechnet: `--viewport-shift` ist die Strecke, um die die Oberkante der
// Anwendung tatsächlich neben dem sichtbaren Ausschnitt liegt. Ein aus
// `offsetTop`/`scrollY` abgeleiteter Wert bleibt dagegen stehen, wenn Safari
// seinen Versatz wieder zurücknimmt, ohne das zu melden - und schiebt die
// Anwendung dann grundlos nach unten.

// Unterhalb dieses Werts stammt die Differenz von der ein- und ausfahrenden
// Adressleiste, nicht von der Tastatur. Ohne die Schwelle änderte die Anwendung
// bei jedem Scrollen ihre Höhe.
const KEYBOARD_MIN_INSET = 120;

// Safari meldet während der Tastatur-Animation Zwischenstände und schickt zum
// Endstand nicht zuverlässig ein weiteres Ereignis. Nach jedem Wechsel wird
// deshalb einmal nachgemessen.
const SETTLE_MS = 300;

// Messrauschen (Teilpixel, ein Frame Rückstand) soll den Wert nicht bei jedem
// Ereignis neu setzen.
const SHIFT_DEAD_ZONE = 2;

// Mehr als eine halbe Bildschirmhöhe kann kein sinnvoller Versatz sein; die
// Grenze verhindert, dass ein Messfehler die Anwendung aus dem Bild schiebt.
const SHIFT_MAX = 400;

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
  let shift = 0;

  const apply = () => {
    pending = false;
    // Ohne `offsetTop`: Verschiebt Safari den sichtbaren Ausschnitt nach unten,
    // um die Einfügemarke freizustellen, verdeckt die Tastatur trotzdem
    // unverändert viel. Wurde der Versatz mitgerechnet, fiel die Summe unter die
    // Schwelle und die Tastatur galt als eingeklappt - die Anwendung reichte
    // wieder hinter sie.
    const covered = win.innerHeight - viewport.height;
    const inset = covered >= KEYBOARD_MIN_INSET ? Math.round(covered) : 0;

    root.style.setProperty('--keyboard-inset', `${inset}px`);
    root.dataset.keyboard = inset > 0 ? 'open' : 'closed';

    // getBoundingClientRect() liegt im Layout-Viewport, offsetTop gibt darin die
    // Oberkante des sichtbaren Ausschnitts an. Die Differenz ist der Fehler des
    // aktuellen Ausgleichs - und wird 0, sobald er stimmt. Deshalb ist der Wert
    // selbstkorrigierend: Er wächst, wenn die Anwendung zu hoch sitzt, und
    // schrumpft wieder, sobald Safari den Versatz zurücknimmt.
    //
    // Gemessen wird der Scroll-Container, nicht die Shell: Deren Rechteck ist
    // das Rahmenrechteck und bewegt sich nicht mit, wenn der Ausgleich als
    // Innenabstand gesetzt wird - der Fehler bliebe dann in jedem Durchlauf
    // gleich groß und der Wert liefe hoch.
    const scroller = win.document.querySelector('.workspace-main');
    if (scroller) {
      const error = viewport.offsetTop - scroller.getBoundingClientRect().top;
      if (Math.abs(error) >= SHIFT_DEAD_ZONE) {
        shift = Math.min(SHIFT_MAX, Math.max(0, Math.round(shift + error)));
        root.style.setProperty('--viewport-shift', `${shift}px`);
      }
    }

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
