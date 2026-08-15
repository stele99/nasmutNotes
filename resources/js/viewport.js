// Die Bildschirmtastatur verkleinert auf iOS das Layout-Viewport nicht: `100dvh`
// und `window.innerHeight` behalten die volle Bildschirmhöhe. Safari verschiebt
// stattdessen nur den sichtbaren Ausschnitt nach oben, damit die Einfügemarke
// über der Tastatur liegt. Die Anwendung reicht damit hinter die Tastatur, und
// `.workspace-main` - der Scroll-Container, an dessen Oberkante Notizkopf und
// Werkzeugleiste kleben - beginnt oberhalb des Bildschirms. Die klebenden
// Leisten wandern dadurch aus dem Blick; bei langen Notizen besonders deutlich,
// weil Safari dort um die volle Tastaturhöhe schiebt.
//
// Diese Maße machen die Tastatur für CSS sichtbar. app.css zieht die Anwendung
// damit auf genau den sichtbaren Ausschnitt zusammen, sodass die klebenden
// Leisten wieder an einer sichtbaren Kante hängen.

// Unterhalb dieses Werts stammt die Differenz von der ein- und ausfahrenden
// Adressleiste, nicht von der Tastatur. Ohne die Schwelle änderte die Anwendung
// bei jedem Scrollen ihre Höhe.
const KEYBOARD_MIN_INSET = 120;

export function initViewportMetrics(win = globalThis.window) {
  const viewport = win?.visualViewport;
  const root = win?.document?.documentElement;
  // Ohne Visual-Viewport-API (ältere Browser) bleiben die Variablen ungesetzt;
  // die Fallback-Werte in app.css ergeben dann das bisherige Verhalten.
  if (!viewport || !root) {
    return;
  }

  let pending = false;

  const apply = () => {
    pending = false;
    const covered = win.innerHeight - viewport.height - viewport.offsetTop;
    const inset = covered >= KEYBOARD_MIN_INSET ? Math.round(covered) : 0;
    // Safari verschiebt entweder das Visual Viewport (`offsetTop`) oder - bei
    // nicht scrollbarem Dokument - das Fenster selbst (`scrollY`). Beide Wege
    // rücken die Oberkante der Anwendung um dieselbe Strecke aus dem Blick.
    const shift = inset > 0
      ? Math.max(0, Math.round(viewport.offsetTop + (win.scrollY || 0)))
      : 0;

    root.style.setProperty('--keyboard-inset', `${inset}px`);
    root.style.setProperty('--viewport-shift', `${shift}px`);
    root.dataset.keyboard = inset > 0 ? 'open' : 'closed';
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
