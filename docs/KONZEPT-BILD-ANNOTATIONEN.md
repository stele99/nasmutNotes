# Umsetzungsplan: Bild-Annotationen als Overlay

| Feld | Wert |
|---|---|
| Planungsstand | ausformuliert, noch nicht implementiert |
| Datum | 2026-08-28 |
| Bezug | FR-NOTE-01/02/03/10 (bestehend), neu vorgeschlagen: FR-ANNO-01–12 |
| Neue Abhängigkeiten | keine |
| Neue Migration | keine |
| Neue Endpunkte | keine |
| Vertragsänderungen | keine (Antwortkörper und Routen bleiben unverändert) |

Dieses Dokument ist so geschrieben, dass es direkt abgearbeitet werden kann:
Jede neue Datei steht vollständig darin, jede geänderte Datei mit der genauen
Fundstelle und dem Vorher/Nachher. Abschnitt 9 nennt die Reihenfolge und die
Abnahmekriterien je Schritt.

**Stand der Vorlagen.** Der Code in diesem Dokument wurde beim Verfassen
geprüft, nicht nur aufgeschrieben:

- `schema.js`, `render.js`, `annotatedImage.js` und `annotator.js` bestehen
  `node --check`; `schema.js` und `render.js` wurden zusätzlich ausgeführt und
  erfüllen die Zusicherungen aus Abschnitt 8.1 (Verwerfen unerlaubter Farben,
  Typen und Zahlen, Rundung, Elementdeckel, Douglas-Peucker, Maskierung von
  Text, Budget-Trimmen, 200-Zeilen-Deckel bei `rules`).
- `ImageAnnotationValidator` und `ImageAnnotationSvgRenderer` bestehen
  unverändert `composer cs` (PHP-CS-Fixer) und `composer stan` (PHPStan
  Level 8) und liefern in einem Durchlauf genau die Ergebnisse aus den
  Prüftabellen 8.2 und 8.3.

Ungeprüft bleibt naturgemäß alles, was einen Browser braucht: NodeView,
Zeigerbedienung, Messung der Textmaße und die CSS-Positionierung.

---

## 1. Ziel

Bilder in Notizen sollen mit Text, Freihandzeichnungen, Pfeilen, Rahmen,
Markierungen und Zeilenlinien versehen werden können. Diese Ergänzungen werden
**nicht in die Bilddatei eingebrannt**, sondern als **Overlay über dem
unveränderten Bild** dargestellt und bleiben dadurch dauerhaft bearbeitbar. Das
Original im Attachment-Speicher wird nie verändert.

### 1.1 Im Umfang

- Annotationen auf `image`-Knoten in Notizen (`page_type = note`).
- Werkzeuge: Freihand, Marker, Linie/Pfeil, Rechteck, Ellipse, Text,
  Zeilenlinien, nummerierter Marker, Abdeckung.
- Vollbild-Annotationseditor für Maus, Touch und Stift.
- Darstellung im Editor, im Lesemodus, im Bildbetrachter, im Druck/PDF und in
  der öffentlichen Freigabe.
- Serverseitige Allowlist-Validierung; Annotationstexte in der Volltextsuche.

### 1.2 Außerhalb des Umfangs

- Kein Einbrennen in die Bilddatei — der ausdrückliche Gegenentwurf.
- Keine Annotationen auf Datei-Anhängen (`page_attachments`, z. B. PDF).
- Keine Annotationen auf verschlüsselten Notizen; diese enthalten
  konstruktionsbedingt keine Bilder (`ENCRYPTION_HAS_ATTACHMENTS`).
- Keine gleichzeitige Bearbeitung durch mehrere Nutzer im Annotationseditor.
- Keine neuen Libraries: gezeichnet wird mit SVG-DOM und Pointer-Events.
- Kein Rückimport von Annotationen beim ZIP-Import (siehe 12.3).

## 2. Architekturentscheidung

Die Annotationen liegen als Attribut `annotations` **am `image`-Knoten im
ProseMirror-JSON**, nicht in einer eigenen Tabelle und nicht als Sidecar-Datei.

| Option | Ablage | Ergebnis |
|---|---|---|
| A | Attribut am `image`-Knoten | **gewählt** |
| B | Tabelle `note_image_annotations` | verworfen |
| C | Sidecar-SVG im Upload-Speicher | verworfen |

Begründung:

1. *Ein Bild kann mehrfach im Dokument stehen.* Nach einem Kopieren zeigen
   beide Vorkommen auf dasselbe Attachment. Annotationen gehören zum Vorkommen;
   Option B bräuchte dafür eine zusätzliche Vorkommens-ID im Knoten — also eine
   halbe Option A.
2. *Alle Synchronisationswege existieren bereits.* Autosave (FR-NOTE-04),
   Offline-Entwürfe, Versions-Snapshots (FR-NOTE-09), Wiederherstellung,
   Konfliktprüfung über `note_contents.version`, `PageCopyService` und der
   ZIP-Export arbeiten auf dem Dokument-JSON.
3. *Der Versionsverlauf bleibt stimmig.* Bei Option B lieferte eine
   Wiederherstellung alten Text mit neuen Pfeilen.
4. *Kein Aufräumproblem.* Verwaiste Annotationen kann es nicht geben.

Der Preis ist Platz im Dokument, das serverseitig auf 1 000 000 Byte begrenzt
ist (`NoteService::MAX_BYTES`). Abschnitt 3.5 setzt dafür ein hartes Budget.

## 3. Datenmodell

### 3.1 Ort im Knoten

```json
{
  "type": "image",
  "attrs": {
    "src": "/api/attachments/<64 hex>",
    "alt": "Eingefügter Screenshot",
    "width": 980,
    "height": 735,
    "annotations": { "v": 1, "space": { "w": 1960, "h": 1470 }, "items": [] }
  }
}
```

`annotations` fehlt oder ist `null`, solange ein Bild nicht annotiert ist.
Bestandsnotizen bleiben damit unverändert gültig; es gibt nichts zu migrieren.

### 3.2 Koordinatenraum

Alle Koordinaten liegen im **Annotationsraum** `space`, der bei der ersten
Annotation auf die damalige Pixelgröße des Bildes gesetzt und danach nie wieder
verändert wird. Gerendert wird als

```html
<svg viewBox="0 0 space.w space.h" preserveAspectRatio="none">
```

im exakten Kasten des `<img>`. Daraus folgt:

- Skaliert der Nutzer das Bild in der Notiz (`width`/`height`,
  `ResizableNodeView`), skaliert das Overlay mit; nichts verrutscht.
- Auf schmalen Displays gilt dasselbe (`max-width: 100%`).
- **Die serverseitige Massenkompression bleibt gefahrlos.** Der
  `ImageCompressionService` ersetzt die Bilddatei und ändert dabei die
  Pixelmaße; da `space` fest ist, bleiben die Annotationen relativ an Ort und
  Stelle. Die bestehende Warnung im Kompressionsdialog braucht keine Ergänzung.

### 3.3 Elementtypen

Kurze Schlüsselnamen sind Absicht: Bei 1 MB Dokumentbudget spart das je
Freihandpfad spürbar Platz.

Gemeinsame Felder jedes Elements: `id` (8 Zeichen `[a-z0-9]`), `t` (Typ),
`c` (Strichfarbe `#rrggbb` oder `#rrggbbaa`), optional `o` (Deckkraft
0,05–1; fehlt, wenn 1).

| `t` | Bedeutung | Weitere Felder |
|---|---|---|
| `pen` | Freihand und Marker | `w` Strichstärke, `p` Punktliste `[[x,y],…]` |
| `line` | Linie oder Pfeil | `w`, `x1`,`y1`,`x2`,`y2`, `head` (`none`/`end`/`both`), `d` (gestrichelt, nur wenn `true`) |
| `rect` | Rechteck | `w`, `x`,`y`,`rw`,`rh`, `f` Füllfarbe oder `null` |
| `ellipse` | Ellipse | `w`, `x`,`y`,`rw`,`rh`, `f` |
| `text` | Textkasten | `x`,`y` (linke obere Ecke), `s` Schriftgröße, `f` Hintergrundfarbe oder `null`, `bw`,`bh` gemessene Textmaße, `text` |
| `rules` | Zeilenlinien | `w`, `x`,`y`,`rw`,`rh`, `gap` Zeilenabstand |
| `marker` | nummerierter Kreis | `x`,`y` Mittelpunkt, `r` Radius, `n` Nummer 1–99 |
| `mask` | deckende Abdeckung | `x`,`y`,`rw`,`rh` |
| `dim` | Maßband (Abschnitt 14) | `w`, `x1`,`y1`,`x2`,`y2`, `s` Schriftgröße der Länge, `f` Hintergrundfarbe oder `null`, `bw`,`bh` gemessene Textmaße, `text` Länge (freiwillig, einzeilig, höchstens 40 Zeichen) |

Der Werkzeugkasten bildet mehr Werkzeuge ab, als es Typen gibt: „Marker" ist
ein `pen` mit dicker Strichstärke und `o: 0.4`, „Hervorheben" ein `rect` mit
Füllung und `o: 0.35`. Weniger Typen heißt weniger Validierungs- und
Renderpfade auf beiden Seiten.

**Zwei Festlegungen, die den Renderer deterministisch machen:**

1. **Kein automatischer Zeilenumbruch.** `text` enthält die Zeilen so, wie der
   Nutzer sie mit Zeilenumbrüchen eingegeben hat (höchstens 12 Zeilen). SVG
   kann nicht umbrechen; jede Umbruchlogik müsste Textbreiten messen und wäre
   auf Client und Server verschieden. Jede Zeile wird zu einem `<tspan>`.
2. **Textmaße werden beim Anlegen gemessen und gespeichert** (`bw`/`bh`, per
   `getBBox()` im Annotationseditor). Der Hintergrundkasten und die
   Auswahlfläche brauchen sie; der Server soll keine Schriftmetrik kennen
   müssen. Fehlen sie, wird kein Hintergrund gezeichnet.

Beispiel:

```json
{
  "v": 1,
  "space": { "w": 1960, "h": 1470 },
  "items": [
    { "id": "k3n8qp1a", "t": "line", "c": "#e11d48", "w": 8,
      "x1": 210, "y1": 980.5, "x2": 640, "y2": 610, "head": "end" },
    { "id": "m0w4zt7c", "t": "text", "c": "#111827", "s": 44,
      "x": 120, "y": 1010, "f": "#ffffff", "bw": 712.5, "bh": 55,
      "text": "Hier läuft der Import auf den falschen Pfad" },
    { "id": "b7f2xa9d", "t": "marker", "c": "#2563eb",
      "x": 1480, "y": 300, "r": 46, "n": 1 }
  ]
}
```

### 3.4 Farbpalette

Acht feste Werte plus freier Farbwähler; validiert wird ausschließlich gegen
`#rrggbb` bzw. `#rrggbbaa`:

`#e11d48` Rot · `#f97316` Orange · `#eab308` Gelb · `#16a34a` Grün ·
`#2563eb` Blau · `#7c3aed` Violett · `#111827` Schwarz · `#ffffff` Weiß

### 3.5 Grenzen

| Grenze | Wert | Durchgesetzt in |
|---|---|---|
| Elemente je Bild | 200 | `schema.js`, `ImageAnnotationValidator` |
| Punkte je Freihandpfad | 400 | beide |
| Textlänge je `text` | 500 Zeichen | beide |
| Textzeilen je `text` | 12 | beide |
| Länge je `dim` | 40 Zeichen, eine Zeile | beide |
| Zeilen je `rules` | 200 (Renderergrenze) | `render.js`, `ImageAnnotationSvgRenderer` |
| JSON je Bild | 40 000 Byte | beide |
| JSON aller Annotationen je Dokument | 300 000 Byte | `ImageAnnotationValidator` |
| Betrag jeder Koordinate | 100 000 | beide |
| `space.w` / `space.h` | 1–20 000 | beide |
| Dokument gesamt | 1 000 000 Byte (unverändert) | `NoteService` |

Koordinaten werden auf **eine Nachkommastelle** gerundet. Freihandpfade laufen
vor dem Ablegen durch eine Douglas-Peucker-Vereinfachung mit einer Toleranz von
0,3 % der Bildbreite. Erreicht ein Bild eine Grenze, blockiert der
Annotationseditor weitere Elemente mit einem Hinweis, statt den Speichervorgang
später scheitern zu lassen — dasselbe Prinzip wie in `sanitize.js`.

## 4. Dateiübersicht

**Neu (7 Dateien):**

| Datei | Inhalt |
|---|---|
| `resources/js/editor/annotations/schema.js` | Grenzen, Palette, Normalisierung, Pfadvereinfachung |
| `resources/js/editor/annotations/render.js` | SVG-Markup aus dem Modell, Textmessung |
| `resources/js/editor/annotations/annotator.js` | Alpine-Mixin des Annotationseditors |
| `resources/js/editor/annotatedImage.js` | TipTap-Erweiterung: Attribut, NodeView-Overlay |
| `resources/views/partials/image_annotation_dialog.php` | Markup des Vollbild-Dialogs |
| `app/Domain/Notes/ImageAnnotationValidator.php` | serverseitige Allowlist, Textextraktion |
| `app/Domain/Notes/ImageAnnotationSvgRenderer.php` | serverseitiges SVG (öffentliche Freigabe, Export) |

**Geändert (10 Dateien):**

| Datei | Änderung |
|---|---|
| `resources/js/editor/index.js` | `Image` → `AnnotatedImage` |
| `resources/js/editor/sanitize.js` | Annotationen beim Einfügen und im Dokument bereinigen |
| `resources/js/notePage.js` | Mixin einbinden, Einstiege, Editorzugriff |
| `resources/js/noteHistoryDiff.js` | Bildsignatur und Annotationstexte |
| `resources/views/page_note.php` | Werkzeugknopf, Knopf im Bildbetrachter, Dialog einbinden |
| `resources/css/app.css` | Overlay, Annotationseditor, Druck, öffentliche Freigabe |
| `app/Domain/Notes/ProseMirrorValidator.php` | Annotationsprüfung, Dokumentbudget, `extractText` |
| `app/Domain/Notes/ProseMirrorHtmlRenderer.php` | Bild als Figur mit Overlay |
| `app/Domain/Export/MarkdownRenderer.php` | Annotationstexte unter dem Bild |
| `app/Domain/Export/NotebookExportService.php` | SVG-Sidecars ins Archiv |

**Tests (4 Dateien):** `tests/Frontend/annotations.test.js` (neu),
`tests/Unit/Domain/Notes/ImageAnnotationValidatorTest.php` (neu),
`tests/Unit/Domain/Notes/ImageAnnotationSvgRendererTest.php` (neu),
`tests/Unit/Domain/Notes/ProseMirrorValidatorTest.php` (ergänzt).

Kein DI-Eintrag ist nötig: Beide neuen PHP-Klassen sind parameterlos
konstruierbar und werden als Vorgabewert im Konstruktor gesetzt (5.11/6.2),
damit die bestehenden `new ProseMirrorValidator()` in den Tests unverändert
weiterlaufen.

## 5. Frontend

### 5.1 Neu: `resources/js/editor/annotations/schema.js`

```js
/**
 * Datenmodell der Bild-Annotationen (FR-ANNO-04/05). Diese Datei ist die
 * einzige Stelle im Frontend, die entscheidet, was ein gültiges
 * Annotationsobjekt ist - benutzt von der TipTap-Erweiterung (beim Parsen aus
 * der Zwischenablage), vom Annotationseditor (beim Übernehmen) und von
 * sanitize.js (beim Reparieren eines Dokuments).
 *
 * Der Client ist nachsichtig und wirft Unbrauchbares weg; der Server ist
 * streng und lehnt es ab (ImageAnnotationValidator). Beides zusammen sorgt
 * dafür, dass eine Notiz nie in einen Zustand gerät, in dem sie sich nicht
 * mehr speichern lässt.
 */

export const ANNOTATION_VERSION = 1;
export const MAX_ITEMS = 200;
export const MAX_POINTS = 400;
export const MAX_TEXT_LENGTH = 500;
export const MAX_TEXT_LINES = 12;
export const MAX_JSON_BYTES = 40000;
export const MAX_SPACE = 20000;
export const COORD_LIMIT = 100000;

/** Toleranz der Pfadvereinfachung, als Anteil der Bildbreite. */
export const SIMPLIFY_RATIO = 0.003;

export const PALETTE = [
  '#e11d48', '#f97316', '#eab308', '#16a34a',
  '#2563eb', '#7c3aed', '#111827', '#ffffff',
];

const HEX_COLOR = /^#[0-9a-f]{6}([0-9a-f]{2})?$/i;
const ITEM_ID = /^[a-z0-9]{8}$/;
const HEAD_VALUES = ['none', 'end', 'both'];

/**
 * Feldbeschreibung je Elementtyp. `num` sind Pflichtzahlen, `pos` davon die,
 * die größer als 0 sein müssen. Diese Tabelle hat ihr wortgleiches Gegenstück
 * in ImageAnnotationValidator::TYPES - Änderungen immer an beiden Stellen.
 */
export const TYPES = {
  pen: { num: ['w'], pos: ['w'], points: true },
  line: { num: ['w', 'x1', 'y1', 'x2', 'y2'], pos: ['w'], head: true, dash: true },
  rect: { num: ['w', 'x', 'y', 'rw', 'rh'], pos: ['w', 'rw', 'rh'], fill: true },
  ellipse: { num: ['w', 'x', 'y', 'rw', 'rh'], pos: ['w', 'rw', 'rh'], fill: true },
  text: { num: ['x', 'y', 's', 'bw', 'bh'], pos: ['s'], fill: true, text: true },
  rules: { num: ['w', 'x', 'y', 'rw', 'rh', 'gap'], pos: ['w', 'rw', 'rh', 'gap'] },
  marker: { num: ['x', 'y', 'r', 'n'], pos: ['r', 'n'] },
  mask: { num: ['x', 'y', 'rw', 'rh'], pos: ['rw', 'rh'] },
};

function isNumber(value) {
  return typeof value === 'number' && Number.isFinite(value);
}

function isColor(value) {
  return typeof value === 'string' && HEX_COLOR.test(value);
}

export function round1(value) {
  return Math.round(value * 10) / 10;
}

function clamp(value, min, max) {
  return Math.min(max, Math.max(min, value));
}

export function createItemId() {
  const alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
  const bytes = new Uint8Array(8);
  if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
    crypto.getRandomValues(bytes);
  } else {
    for (let index = 0; index < bytes.length; index += 1) {
      bytes[index] = Math.floor(Math.random() * 256);
    }
  }

  return Array.from(bytes, (byte) => alphabet[byte % alphabet.length]).join('');
}

export function emptyAnnotations(width, height) {
  return {
    v: ANNOTATION_VERSION,
    space: { w: Math.round(clamp(width, 1, MAX_SPACE)), h: Math.round(clamp(height, 1, MAX_SPACE)) },
    items: [],
  };
}

function normalizeSpace(space) {
  if (!space || typeof space !== 'object') {
    return null;
  }
  const width = Math.round(Number(space.w));
  const height = Math.round(Number(space.h));
  if (!isNumber(width) || !isNumber(height)) {
    return null;
  }
  if (width < 1 || height < 1 || width > MAX_SPACE || height > MAX_SPACE) {
    return null;
  }

  return { w: width, h: height };
}

function normalizeText(value) {
  if (typeof value !== 'string') {
    return null;
  }
  const lines = value
    .replace(/\r\n?/g, '\n')
    .split('\n')
    .map((line) => line.replace(/\s+$/, ''))
    .slice(0, MAX_TEXT_LINES);
  const text = lines.join('\n').slice(0, MAX_TEXT_LENGTH).trim();

  return text === '' ? null : text;
}

/**
 * Zu lange Pfade werden gleichmäßig ausgedünnt statt verworfen: Ein Strich,
 * den der Nutzer gerade gezogen hat, soll nicht wortlos verschwinden.
 */
function normalizePoints(value) {
  if (!Array.isArray(value) || value.length === 0) {
    return null;
  }

  const points = [];
  for (const point of value) {
    if (!Array.isArray(point) || point.length < 2) {
      continue;
    }
    const [x, y] = point;
    if (!isNumber(x) || !isNumber(y) || Math.abs(x) > COORD_LIMIT || Math.abs(y) > COORD_LIMIT) {
      continue;
    }
    points.push([round1(x), round1(y)]);
  }
  if (points.length === 0) {
    return null;
  }
  if (points.length <= MAX_POINTS) {
    return points;
  }

  const step = (points.length - 1) / (MAX_POINTS - 1);
  const thinned = [];
  for (let index = 0; index < MAX_POINTS; index += 1) {
    thinned.push(points[Math.round(index * step)]);
  }

  return thinned;
}

function normalizeItem(raw) {
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) {
    return null;
  }
  const spec = TYPES[raw.t];
  if (!spec || !isColor(raw.c)) {
    return null;
  }

  const item = {
    id: typeof raw.id === 'string' && ITEM_ID.test(raw.id) ? raw.id : createItemId(),
    t: raw.t,
    c: raw.c.toLowerCase(),
  };

  if (isNumber(raw.o) && raw.o < 1) {
    item.o = Math.round(clamp(raw.o, 0.05, 1) * 100) / 100;
  }

  for (const key of spec.num) {
    const value = raw[key];
    if (!isNumber(value) || Math.abs(value) > COORD_LIMIT) {
      return null;
    }
    if (spec.pos.includes(key) && value <= 0) {
      return null;
    }
    item[key] = round1(value);
  }

  if (spec.fill) {
    item.f = isColor(raw.f) ? raw.f.toLowerCase() : null;
  }
  if (spec.head) {
    item.head = HEAD_VALUES.includes(raw.head) ? raw.head : 'none';
  }
  if (spec.dash && raw.d === true) {
    item.d = true;
  }
  if (spec.text) {
    const text = normalizeText(raw.text);
    if (text === null) {
      return null;
    }
    item.text = text;
  }
  if (spec.points) {
    const points = normalizePoints(raw.p);
    if (points === null) {
      return null;
    }
    item.p = points;
  }
  if (raw.t === 'marker') {
    if (!Number.isInteger(raw.n) || raw.n < 1 || raw.n > 99) {
      return null;
    }
  }

  return item;
}

/**
 * @returns {{ v: number, space: { w: number, h: number }, items: object[] } | null}
 *   `null`, wenn nichts Gültiges übrig bleibt - dann trägt der Bildknoten
 *   `annotations: null` und das Dokument bleibt so klein wie zuvor.
 */
export function normalizeAnnotations(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return null;
  }
  if (value.v !== ANNOTATION_VERSION) {
    return null;
  }
  const space = normalizeSpace(value.space);
  if (space === null) {
    return null;
  }

  const items = [];
  const seen = new Set();
  for (const raw of Array.isArray(value.items) ? value.items : []) {
    if (items.length >= MAX_ITEMS) {
      break;
    }
    const item = normalizeItem(raw);
    if (item === null || seen.has(item.id)) {
      continue;
    }
    seen.add(item.id);
    items.push(item);
  }

  return items.length === 0 ? null : { v: ANNOTATION_VERSION, space, items };
}

export function annotationBytes(value) {
  if (value === null || value === undefined) {
    return 0;
  }

  return new TextEncoder().encode(JSON.stringify(value)).length;
}

/**
 * Letzte Verteidigungslinie vor dem Schreiben in den Knoten: Passt das Objekt
 * nicht ins Budget, fallen Elemente von hinten weg. Der Annotationseditor
 * verhindert diesen Fall bereits beim Zeichnen (annoCanAdd), damit hier
 * niemandem unbemerkt etwas abhandenkommt.
 */
export function serializeAnnotations(value) {
  let normalized = normalizeAnnotations(value);
  while (normalized !== null && annotationBytes(normalized) > MAX_JSON_BYTES) {
    const items = normalized.items.slice(0, -1);
    normalized = items.length === 0 ? null : { ...normalized, items };
  }

  return normalized;
}

/** Texte in Lesereihenfolge - für Suche, Alternativtext und Versions-Diff. */
export function annotationTexts(value) {
  const normalized = normalizeAnnotations(value);
  if (normalized === null) {
    return [];
  }

  return normalized.items
    .filter((item) => item.t === 'text')
    .map((item) => item.text);
}

/**
 * Douglas-Peucker, iterativ statt rekursiv: Ein mit dem Finger gezogener
 * Strich liefert schnell mehrere hundert Punkte, und eine Rekursion darüber
 * wäre auf schwachen Geräten unnötig tief.
 */
export function simplifyPath(points, tolerance) {
  if (!Array.isArray(points) || points.length < 3 || tolerance <= 0) {
    return Array.isArray(points) ? points.slice() : [];
  }

  const keep = new Array(points.length).fill(false);
  keep[0] = true;
  keep[points.length - 1] = true;
  const stack = [[0, points.length - 1]];

  while (stack.length > 0) {
    const [start, end] = stack.pop();
    let farthest = -1;
    let distance = tolerance;
    for (let index = start + 1; index < end; index += 1) {
      const current = perpendicularDistance(points[index], points[start], points[end]);
      if (current > distance) {
        distance = current;
        farthest = index;
      }
    }
    if (farthest !== -1) {
      keep[farthest] = true;
      stack.push([start, farthest], [farthest, end]);
    }
  }

  return points.filter((_point, index) => keep[index]);
}

function perpendicularDistance(point, start, end) {
  const dx = end[0] - start[0];
  const dy = end[1] - start[1];
  const length = Math.hypot(dx, dy);
  if (length === 0) {
    return Math.hypot(point[0] - start[0], point[1] - start[1]);
  }

  return Math.abs(dy * point[0] - dx * point[1] + end[0] * start[1] - end[1] * start[0]) / length;
}
```

### 5.2 Neu: `resources/js/editor/annotations/render.js`

```js
import { normalizeAnnotations } from './schema.js';

/**
 * Erzeugt das Overlay-SVG aus dem Annotationsmodell. Genau diese Datei bedient
 * alle Client-Pfade: die NodeView im Editor, den Lesemodus, den
 * Bildbetrachter und die Zeichenfläche des Annotationseditors. Ihr
 * Gegenstück auf dem Server ist ImageAnnotationSvgRenderer; beide erzeugen für
 * dasselbe Modell dasselbe Markup.
 *
 * buildOverlayMarkup() ist bewusst eine reine Funktion über Zeichenketten -
 * so lässt sie sich mit `node --test` ohne DOM prüfen.
 */

const SVG_NS = 'http://www.w3.org/2000/svg';
export const FONT_STACK = "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";
const MAX_RULE_LINES = 200;

function escapeXml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function num(value) {
  return Number.isFinite(value) ? String(Math.round(value * 10) / 10) : '0';
}

function common(item) {
  const opacity = Number.isFinite(item.o) && item.o < 1 ? ` opacity="${num(item.o)}"` : '';

  return opacity;
}

function stroke(item) {
  return ` stroke="${escapeXml(item.c)}" stroke-width="${num(item.w)}"`
    + ' stroke-linecap="round" stroke-linejoin="round" fill="none"';
}

/**
 * Weiße oder schwarze Schrift auf farbigem Grund, nach wahrgenommener
 * Helligkeit. Betrifft nur die Ziffer im nummerierten Marker.
 */
export function readableInk(color) {
  const hex = color.slice(1, 7);
  const red = parseInt(hex.slice(0, 2), 16);
  const green = parseInt(hex.slice(2, 4), 16);
  const blue = parseInt(hex.slice(4, 6), 16);

  return (red * 299 + green * 587 + blue * 114) / 1000 > 150 ? '#111827' : '#ffffff';
}

function penMarkup(item) {
  if (item.p.length === 1) {
    const [x, y] = item.p[0];

    return `<circle cx="${num(x)}" cy="${num(y)}" r="${num(item.w / 2)}"`
      + ` fill="${escapeXml(item.c)}"${common(item)}/>`;
  }

  const path = item.p
    .map((point, index) => `${index === 0 ? 'M' : 'L'} ${num(point[0])} ${num(point[1])}`)
    .join(' ');

  return `<path d="${path}"${stroke(item)}${common(item)}/>`;
}

/**
 * Pfeilspitzen werden als eigener Pfad gezeichnet, nicht als <marker>: Ein
 * <marker> braucht eine ID in <defs>, und auf einer Notiz mit mehreren
 * annotierten Bildern kollidieren diese IDs.
 */
function arrowHeadMarkup(item, x, y, angle) {
  const size = Math.max(item.w * 4, 12);
  const spread = Math.PI / 7;
  const ax = x - size * Math.cos(angle - spread);
  const ay = y - size * Math.sin(angle - spread);
  const bx = x - size * Math.cos(angle + spread);
  const by = y - size * Math.sin(angle + spread);

  return `<path d="M ${num(ax)} ${num(ay)} L ${num(x)} ${num(y)} L ${num(bx)} ${num(by)}"`
    + `${stroke(item)}${common(item)}/>`;
}

function lineMarkup(item) {
  const dash = item.d === true ? ` stroke-dasharray="${num(item.w * 3)} ${num(item.w * 2)}"` : '';
  let markup = `<path d="M ${num(item.x1)} ${num(item.y1)} L ${num(item.x2)} ${num(item.y2)}"`
    + `${stroke(item)}${dash}${common(item)}/>`;

  const angle = Math.atan2(item.y2 - item.y1, item.x2 - item.x1);
  if (item.head === 'end' || item.head === 'both') {
    markup += arrowHeadMarkup(item, item.x2, item.y2, angle);
  }
  if (item.head === 'both') {
    markup += arrowHeadMarkup(item, item.x1, item.y1, angle + Math.PI);
  }

  return markup;
}

function rectMarkup(item) {
  const fill = item.f === null ? 'none' : escapeXml(item.f);

  return `<rect x="${num(item.x)}" y="${num(item.y)}" width="${num(item.rw)}"`
    + ` height="${num(item.rh)}" fill="${fill}" stroke="${escapeXml(item.c)}"`
    + ` stroke-width="${num(item.w)}"${common(item)}/>`;
}

function ellipseMarkup(item) {
  const fill = item.f === null ? 'none' : escapeXml(item.f);

  return `<ellipse cx="${num(item.x + item.rw / 2)}" cy="${num(item.y + item.rh / 2)}"`
    + ` rx="${num(item.rw / 2)}" ry="${num(item.rh / 2)}" fill="${fill}"`
    + ` stroke="${escapeXml(item.c)}" stroke-width="${num(item.w)}"${common(item)}/>`;
}

function maskMarkup(item) {
  return `<rect x="${num(item.x)}" y="${num(item.y)}" width="${num(item.rw)}"`
    + ` height="${num(item.rh)}" fill="${escapeXml(item.c)}"${common(item)}/>`;
}

function rulesMarkup(item) {
  const count = Math.min(MAX_RULE_LINES, Math.floor(item.rh / item.gap));
  let markup = '';
  for (let index = 1; index <= count; index += 1) {
    const y = item.y + index * item.gap;
    markup += `<path d="M ${num(item.x)} ${num(y)} L ${num(item.x + item.rw)} ${num(y)}"`
      + `${stroke(item)}${common(item)}/>`;
  }

  return markup;
}

function markerMarkup(item) {
  const ink = readableInk(item.c);

  return `<circle cx="${num(item.x)}" cy="${num(item.y)}" r="${num(item.r)}"`
    + ` fill="${escapeXml(item.c)}"${common(item)}/>`
    + `<text x="${num(item.x)}" y="${num(item.y)}" fill="${ink}"`
    + ` font-family="${escapeXml(FONT_STACK)}" font-size="${num(item.r * 1.2)}"`
    + ' font-weight="700" text-anchor="middle" dominant-baseline="central"'
    + `${common(item)}>${escapeXml(item.n)}</text>`;
}

/**
 * Die gespeicherten Maße bw/bh stammen aus der Messung beim Anlegen
 * (measureTextBox). Ohne sie gibt es keinen Hintergrundkasten - der Text
 * selbst wird trotzdem gezeichnet.
 */
export function textLineOffsets(item) {
  const lines = item.text.split('\n');
  const lineHeight = item.s * 1.25;

  return lines.map((line, index) => ({ line, y: item.y + item.s + index * lineHeight }));
}

function textMarkup(item) {
  const padding = item.s * 0.25;
  let markup = '';
  if (item.f !== null && Number.isFinite(item.bw) && Number.isFinite(item.bh)) {
    markup += `<rect x="${num(item.x - padding)}" y="${num(item.y - padding)}"`
      + ` width="${num(item.bw + padding * 2)}" height="${num(item.bh + padding * 2)}"`
      + ` rx="${num(padding)}" fill="${escapeXml(item.f)}"${common(item)}/>`;
  }

  const tspans = textLineOffsets(item)
    .map((entry) => `<tspan x="${num(item.x)}" y="${num(entry.y)}">${escapeXml(entry.line)}</tspan>`)
    .join('');

  return markup + `<text fill="${escapeXml(item.c)}" font-family="${escapeXml(FONT_STACK)}"`
    + ` font-size="${num(item.s)}"${common(item)}>${tspans}</text>`;
}

function itemMarkup(item) {
  switch (item.t) {
    case 'pen': return penMarkup(item);
    case 'line': return lineMarkup(item);
    case 'rect': return rectMarkup(item);
    case 'ellipse': return ellipseMarkup(item);
    case 'text': return textMarkup(item);
    case 'rules': return rulesMarkup(item);
    case 'marker': return markerMarkup(item);
    case 'mask': return maskMarkup(item);
    default: return '';
  }
}

/** @returns {string} vollständiges `<svg>` oder `''`, wenn nichts zu zeichnen ist. */
export function buildOverlayMarkup(annotations) {
  const value = normalizeAnnotations(annotations);
  if (value === null) {
    return '';
  }

  const body = value.items.map(itemMarkup).join('');

  return `<svg xmlns="${SVG_NS}" viewBox="0 0 ${value.space.w} ${value.space.h}"`
    + ' preserveAspectRatio="none" aria-hidden="true" focusable="false"'
    + ` class="note-image-overlay-svg">${body}</svg>`;
}

/**
 * Hängt das Overlay in einen Wirtsknoten. Der Umweg über den DOMParser statt
 * innerHTML ist nötig, weil SVG-Elemente im HTML-Namensraum sonst nicht
 * entstehen; er verwirft außerdem unvollständiges Markup, statt es halb
 * darzustellen.
 */
export function renderOverlay(host, annotations) {
  host.replaceChildren();
  const markup = buildOverlayMarkup(annotations);
  if (markup === '') {
    return null;
  }

  const parsed = new DOMParser().parseFromString(markup, 'image/svg+xml');
  if (parsed.querySelector('parsererror') !== null) {
    return null;
  }
  const svg = document.importNode(parsed.documentElement, true);
  host.appendChild(svg);

  return svg;
}

/**
 * Misst einen Textkasten in Bildkoordinaten. Gemessen wird im echten
 * Ziel-SVG, damit Schriftfamilie und Größe exakt dieselben sind wie beim
 * späteren Zeichnen.
 */
export function measureTextBox(svg, text, size) {
  const probe = document.createElementNS(SVG_NS, 'text');
  probe.setAttribute('font-family', FONT_STACK);
  probe.setAttribute('font-size', String(size));
  probe.setAttribute('visibility', 'hidden');
  for (const line of text.split('\n')) {
    const tspan = document.createElementNS(SVG_NS, 'tspan');
    tspan.setAttribute('x', '0');
    tspan.setAttribute('dy', String(size * 1.25));
    tspan.textContent = line === '' ? ' ' : line;
    probe.appendChild(tspan);
  }
  svg.appendChild(probe);
  let box = { width: 0, height: 0 };
  try {
    box = probe.getBBox();
  } finally {
    probe.remove();
  }

  return {
    bw: Math.round(box.width * 10) / 10,
    bh: Math.round(Math.max(box.height, size * 1.25 * text.split('\n').length) * 10) / 10,
  };
}
```

### 5.3 Neu: `resources/js/editor/annotatedImage.js`

```js
import { Image } from '@tiptap/extension-image';
import { normalizeAnnotations, serializeAnnotations } from './annotations/schema.js';
import { renderOverlay } from './annotations/render.js';

/**
 * Bildknoten mit Annotations-Overlay (FR-ANNO-01/07).
 *
 * Zwei Dinge sind hier heikel und deshalb ausführlich kommentiert:
 *
 * 1. Das Attribut muss den Weg über ein DOM-Attribut nehmen. ProseMirror
 *    serialisiert beim Kopieren innerhalb des Editors über HTML; ohne
 *    `data-annotations` verlöre eine kopierte Bildzeile ihre Annotationen.
 * 2. `Image` ist mit `resize.enabled` konfiguriert und bringt bereits eine
 *    NodeView mit (data-resize-container/-wrapper/-handle). Statt sie zu
 *    ersetzen, wird sie umhüllt.
 */

function attachLayer(dom) {
  const image = dom instanceof HTMLImageElement ? dom : dom.querySelector('img');
  if (image === null) {
    return null;
  }

  const host = image.parentElement ?? dom;
  host.classList.add('note-image-host');
  const layer = document.createElement('span');
  layer.className = 'note-image-overlay';
  layer.setAttribute('aria-hidden', 'true');
  host.appendChild(layer);

  // Das Overlay wird auf den Kasten des <img> gelegt, nicht auf den des
  // Wirtsknotens: Der Wirt ist beim zentrierten Bild breiter als das Bild
  // selbst, und das Overlay säße dann daneben.
  const place = () => {
    layer.style.left = `${image.offsetLeft}px`;
    layer.style.top = `${image.offsetTop}px`;
    layer.style.width = `${image.offsetWidth}px`;
    layer.style.height = `${image.offsetHeight}px`;
  };

  place();
  const observer = new ResizeObserver(place);
  observer.observe(image);
  image.addEventListener('load', place);

  return {
    layer,
    destroy() {
      observer.disconnect();
      image.removeEventListener('load', place);
      layer.remove();
    },
  };
}

export const AnnotatedImage = Image.extend({
  addAttributes() {
    return {
      ...this.parent?.(),
      annotations: {
        default: null,
        parseHTML: (element) => {
          const raw = element.getAttribute('data-annotations');
          if (raw === null || raw === '') {
            return null;
          }
          try {
            return normalizeAnnotations(JSON.parse(raw));
          } catch (error) {
            // Fremdes oder abgeschnittenes JSON: Das Bild bleibt, die
            // Annotation entfällt.
            return null;
          }
        },
        renderHTML: (attributes) => {
          const value = serializeAnnotations(attributes.annotations);

          return value === null ? {} : { 'data-annotations': JSON.stringify(value) };
        },
      },
    };
  },

  addNodeView() {
    const parentFactory = this.parent?.();
    if (typeof parentFactory !== 'function') {
      // Ohne NodeView der Basis-Erweiterung gäbe es auch keine Skaliergriffe;
      // dann trägt der Editor nur das nackte Bild und das Overlay entfällt.
      return null;
    }

    return (props) => {
      const view = parentFactory(props);
      const attached = attachLayer(view.dom);
      if (attached === null) {
        return view;
      }

      let current = props.node.attrs.annotations;
      renderOverlay(attached.layer, current);

      // Die NodeView der Basis-Erweiterung ist eine Klasseninstanz. Sie wird
      // ergänzt statt kopiert - ein Spread ({ ...view }) verlöre alle
      // Methoden, die am Prototyp hängen.
      const parentUpdate = typeof view.update === 'function' ? view.update.bind(view) : null;
      const parentDestroy = typeof view.destroy === 'function' ? view.destroy.bind(view) : null;

      view.update = (node, decorations, innerDecorations) => {
        const accepted = parentUpdate
          ? parentUpdate(node, decorations, innerDecorations)
          : node.type.name === 'image';
        if (!accepted) {
          return false;
        }
        if (node.attrs.annotations !== current) {
          current = node.attrs.annotations;
          renderOverlay(attached.layer, current);
        }

        return true;
      };

      view.destroy = () => {
        attached.destroy();
        parentDestroy?.();
      };

      return view;
    };
  },
});
```

### 5.4 Geändert: `resources/js/editor/index.js`

Zeile 4 und der `Image.configure(…)`-Block. Die Konfiguration bleibt Wort für
Wort erhalten, nur der Erweiterungsname wechselt.

```diff
-import { Image } from '@tiptap/extension-image';
+import { AnnotatedImage } from './annotatedImage.js';
```

```diff
-      Image.configure({
+      AnnotatedImage.configure({
         allowBase64: false,
```

### 5.5 Geändert: `resources/js/editor/sanitize.js`

Zwei Ergänzungen, beide aus demselben Grund wie die bestehenden: Ein Dokument,
das der Server ablehnt, ließe sich sonst überhaupt nicht mehr speichern.

```diff
+import { normalizeAnnotations } from './annotations/schema.js';
```

In `sanitizePastedHtml`, in der bestehenden Bildschleife:

```diff
   for (const image of parsed.body.querySelectorAll('img')) {
     if (!ALLOWED_IMAGE_SRC.test(image.getAttribute('src') || '')) {
       image.remove();
+      continue;
+    }
+    const raw = image.getAttribute('data-annotations');
+    if (raw !== null) {
+      let value = null;
+      try {
+        value = normalizeAnnotations(JSON.parse(raw));
+      } catch (error) {
+        value = null;
+      }
+      if (value === null) {
+        image.removeAttribute('data-annotations');
+      } else {
+        image.setAttribute('data-annotations', JSON.stringify(value));
+      }
     }
   }
```

`NEEDS_CLEANUP` muss zusätzlich auf das Attribut anspringen, sonst wird das
Fragment gar nicht erst geparst:

```diff
-const NEEDS_CLEANUP = /<h[456][\s>]|<img[\s>]/i;
+const NEEDS_CLEANUP = /<h[456][\s>]|<img[\s>]|data-annotations/i;
```

In `sanitizeNoteDoc`, in der bestehenden Kindschleife: Bilder mit ungültigen
Annotationen werden nicht verworfen, sondern entschärft.

```diff
     for (const child of node.content) {
       if (child?.type === 'image' && !ALLOWED_IMAGE_SRC.test(child.attrs?.src || '')) {
         changed = true;
         continue;
       }
-      content.push(walk(child));
+      if (child?.type === 'image' && child.attrs?.annotations != null) {
+        const value = normalizeAnnotations(child.attrs.annotations);
+        if (JSON.stringify(value) !== JSON.stringify(child.attrs.annotations)) {
+          changed = true;
+          content.push({ ...child, attrs: { ...child.attrs, annotations: value } });
+          continue;
+        }
+      }
+      content.push(walk(child));
     }
```

### 5.6 Neu: `resources/js/editor/annotations/annotator.js`

Alpine-Mixin nach dem Muster von `voiceRecorderMixin()`. Alpine läuft im
CSP-Build: Im Markup stehen nur Methodennamen und einfache Eigenschaften,
keine Pfeilfunktionen und keine Template-Strings. Aktive Werkzeugknöpfe
bekommen ihre Klasse imperativ (`annoSyncToolbar`), genau wie die
Notiz-Werkzeugleiste es mit `data-editor-command` hält.

```js
import {
  MAX_ITEMS,
  MAX_JSON_BYTES,
  PALETTE,
  SIMPLIFY_RATIO,
  annotationBytes,
  createItemId,
  emptyAnnotations,
  normalizeAnnotations,
  round1,
  serializeAnnotations,
  simplifyPath,
} from './schema.js';
import { measureTextBox, renderOverlay } from './render.js';

const HISTORY_LIMIT = 50;

/** Voreinstellungen je Werkzeug; alles Übrige kommt aus der Eigenschaftsleiste. */
const TOOL_DEFAULTS = {
  pen: { width: 6, opacity: 1 },
  highlighter: { width: 36, opacity: 0.4 },
  line: { width: 6, opacity: 1 },
  arrow: { width: 6, opacity: 1 },
  rect: { width: 6, opacity: 1 },
  ellipse: { width: 6, opacity: 1 },
  text: { width: 6, opacity: 1 },
  rules: { width: 3, opacity: 1 },
  marker: { width: 6, opacity: 1 },
  mask: { width: 6, opacity: 1 },
};

export function imageAnnotatorMixin() {
  // Außerhalb des Alpine-Zustands: Der laufende Zeigerzug und der Zugriff auf
  // den ProseMirror-Editor dürfen nicht reaktiv werden.
  let getEditor = () => null;
  let drag = null;

  return {
    annoOpen: false,
    annoSrc: '',
    annoAlt: '',
    annoPos: null,
    annoSpace: { w: 0, h: 0 },
    annoItems: [],
    annoTool: 'pen',
    annoColor: PALETTE[0],
    annoWidth: 6,
    annoOpacity: 1,
    annoFontSize: 44,
    annoSelectedId: '',
    annoSelectionStyle: '',
    annoNotice: '',
    annoError: '',
    annoMaskHintShown: false,
    annoTextOpen: false,
    annoTextDraft: '',
    annoTextEditId: '',
    annoTextAnchor: null,
    annoHistory: [],
    annoFuture: [],
    annoDirty: false,

    annoSetEditorAccessor(accessor) {
      getEditor = accessor;
    },

    annoEditor() {
      return getEditor();
    },

    /** Einstieg aus der Werkzeugleiste: Es muss ein Bildknoten ausgewählt sein. */
    openImageAnnotator() {
      const editor = this.annoEditor();
      if (!editor || !this.canEditPage || this.isEncrypted()) {
        return;
      }
      const selected = editor.state.selection.node;
      if (!selected || selected.type.name !== 'image') {
        this.annoError = 'Bitte zuerst ein Bild antippen.';

        return;
      }
      this.annoBegin(editor.state.selection.from, selected);
    },

    /** Einstieg aus dem Vollbild-Betrachter (Handy). */
    openImageAnnotatorFromViewer() {
      const editor = this.annoEditor();
      if (!editor || !this.canEditPage || this.isEncrypted() || !this.imageViewerSrc) {
        return;
      }
      const path = new URL(this.imageViewerSrc, window.location.origin).pathname;
      let position = null;
      let node = null;
      editor.state.doc.descendants((candidate, pos) => {
        if (position !== null) {
          return false;
        }
        if (candidate.type.name === 'image' && candidate.attrs.src === path) {
          position = pos;
          node = candidate;
        }

        return position === null;
      });
      if (position === null) {
        return;
      }
      this.closeImageViewer();
      this.annoBegin(position, node);
    },

    /**
     * Der Annotationsraum wird einmal festgelegt und danach nie geändert -
     * sonst wanderten alle vorhandenen Elemente, sobald das Bild
     * serverseitig komprimiert wurde.
     */
    annoBegin(position, node) {
      const stored = normalizeAnnotations(node.attrs.annotations);
      const dom = this.annoEditor()?.view.nodeDOM(position) ?? null;
      const image = dom instanceof HTMLElement ? dom.querySelector('img') : null;
      const width = stored?.space.w ?? image?.naturalWidth ?? node.attrs.width ?? 0;
      const height = stored?.space.h ?? image?.naturalHeight ?? node.attrs.height ?? 0;
      if (width < 1 || height < 1) {
        this.annoError = 'Das Bild ist noch nicht vollständig geladen.';

        return;
      }

      this.annoPos = position;
      this.annoSrc = node.attrs.src;
      this.annoAlt = node.attrs.alt || '';
      this.annoSpace = stored?.space ?? emptyAnnotations(width, height).space;
      this.annoItems = stored ? JSON.parse(JSON.stringify(stored.items)) : [];
      this.annoSelectedId = '';
      this.annoSelectionStyle = '';
      this.annoHistory = [];
      this.annoFuture = [];
      this.annoDirty = false;
      this.annoError = '';
      this.annoNotice = '';
      this.annoOpen = true;
      this.$nextTick(() => {
        this.annoRenderAll();
        this.annoSyncToolbar();
      });
    },

    closeImageAnnotator() {
      if (!this.annoOpen) {
        return;
      }
      if (this.annoDirty && !window.confirm('Änderungen an den Bildnotizen verwerfen?')) {
        return;
      }
      this.annoOpen = false;
      this.annoItems = [];
      this.annoPos = null;
      drag = null;
    },

    /**
     * Geschrieben wird über die gemerkte Position, nicht über die aktuelle
     * Auswahl: Der Einstieg aus dem Vollbild-Betrachter hat gar keine.
     */
    applyImageAnnotations() {
      const editor = this.annoEditor();
      const position = this.annoPos;
      if (!editor || position === null) {
        return;
      }
      const value = serializeAnnotations({
        v: 1,
        space: this.annoSpace,
        items: JSON.parse(JSON.stringify(this.annoItems)),
      });

      editor
        .chain()
        .focus()
        .command(({ tr }) => {
          const node = tr.doc.nodeAt(position);
          if (!node || node.type.name !== 'image') {
            return false;
          }
          tr.setNodeMarkup(position, undefined, { ...node.attrs, annotations: value });

          return true;
        })
        .run();

      this.annoDirty = false;
      this.annoOpen = false;
      this.annoItems = [];
      this.annoPos = null;
      drag = null;
    },

    // --- Werkzeuge und Eigenschaften -----------------------------------

    annoSelectTool(event) {
      const tool = event.currentTarget?.dataset.tool;
      if (!tool) {
        return;
      }
      this.annoTool = tool;
      const defaults = TOOL_DEFAULTS[tool];
      if (defaults) {
        this.annoWidth = defaults.width;
        this.annoOpacity = defaults.opacity;
      }
      if (tool === 'mask' && !this.annoMaskHintShown) {
        this.annoMaskHintShown = true;
        this.annoNotice = 'Abdecken verbirgt den Bildbereich nur in der Anzeige. '
          + 'Der Bildinhalt bleibt in der Datei erhalten.';
      }
      if (tool !== 'select') {
        this.annoSelectedId = '';
        this.annoSelectionStyle = '';
      }
      this.annoSyncToolbar();
    },

    annoPickColor(event) {
      const color = event.currentTarget?.dataset.color;
      if (!color) {
        return;
      }
      this.annoColor = color;
      this.annoApplyToSelection('c', color);
      this.annoSyncToolbar();
    },

    annoColorInput(event) {
      this.annoColor = event.target.value;
      this.annoApplyToSelection('c', this.annoColor);
      this.annoSyncToolbar();
    },

    annoWidthInput(event) {
      this.annoWidth = Number(event.target.value);
      this.annoApplyToSelection('w', this.annoWidth);
    },

    annoOpacityInput(event) {
      this.annoOpacity = Number(event.target.value);
      this.annoApplyToSelection('o', this.annoOpacity);
    },

    /** Eigenschaftsänderungen wirken auf das ausgewählte Element, sonst auf das nächste neue. */
    annoApplyToSelection(key, value) {
      const item = this.annoSelectedItem();
      if (!item || !(key in item)) {
        return;
      }
      this.annoPushHistory();
      item[key] = value;
      this.annoRenderAll();
    },

    annoSelectedItem() {
      return this.annoItems.find((item) => item.id === this.annoSelectedId) ?? null;
    },

    annoSyncToolbar() {
      const bar = this.$refs.annoToolbar;
      if (!bar) {
        return;
      }
      for (const button of bar.querySelectorAll('[data-tool]')) {
        button.classList.toggle('is-active', button.dataset.tool === this.annoTool);
      }
      for (const button of bar.querySelectorAll('[data-color]')) {
        button.classList.toggle('is-active', button.dataset.color === this.annoColor);
      }
    },

    annoCanAdd() {
      if (this.annoItems.length >= MAX_ITEMS) {
        this.annoError = `Mehr als ${MAX_ITEMS} Elemente sind auf einem Bild nicht möglich.`;

        return false;
      }
      if (annotationBytes({ v: 1, space: this.annoSpace, items: this.annoItems }) > MAX_JSON_BYTES * 0.95) {
        this.annoError = 'Die Bildnotizen sind zu umfangreich geworden. Bitte etwas entfernen.';

        return false;
      }
      this.annoError = '';

      return true;
    },

    // --- Zeichnen -------------------------------------------------------

    /**
     * Bildkoordinaten aus einem Zeigerereignis. Der Weg über
     * getBoundingClientRect() macht Zoom und Verschieben der Bühne
     * automatisch mit - dort ist keine Umrechnung nötig.
     */
    annoPoint(event) {
      const stage = this.$refs.annoStage;
      const rect = stage.getBoundingClientRect();

      return {
        x: round1(((event.clientX - rect.left) / rect.width) * this.annoSpace.w),
        y: round1(((event.clientY - rect.top) / rect.height) * this.annoSpace.h),
      };
    },

    annoPointerDown(event) {
      if (event.button !== undefined && event.button !== 0) {
        return;
      }
      // Handballenabweisung: Während ein Stift zeichnet, wird jede Berührung
      // ignoriert.
      if (drag !== null && drag.pointerType === 'pen' && event.pointerType === 'touch') {
        return;
      }
      event.preventDefault();
      this.$refs.annoStage.setPointerCapture(event.pointerId);
      const point = this.annoPoint(event);

      if (this.annoTool === 'select') {
        this.annoBeginSelect(point, event);

        return;
      }
      if (this.annoTool === 'text') {
        this.annoOpenTextDialog(point, '');

        return;
      }
      if (!this.annoCanAdd()) {
        return;
      }
      drag = {
        kind: 'create',
        pointerId: event.pointerId,
        pointerType: event.pointerType,
        origin: point,
        item: this.annoCreateItem(point, event),
      };
      this.annoRenderPreview(drag.item);
    },

    annoPointerMove(event) {
      if (drag === null || event.pointerId !== drag.pointerId) {
        return;
      }
      event.preventDefault();
      const point = this.annoPoint(event);

      if (drag.kind === 'create') {
        this.annoExtendItem(drag.item, drag.origin, point);
        this.annoRenderPreview(drag.item);

        return;
      }
      if (drag.kind === 'move') {
        this.annoTranslate(drag.item, point.x - drag.last.x, point.y - drag.last.y);
        drag.last = point;
        this.annoRenderAll();
        this.annoUpdateSelectionFrame();
      }
      if (drag.kind === 'resize') {
        this.annoResize(drag.item, point);
        this.annoRenderAll();
        this.annoUpdateSelectionFrame();
      }
    },

    annoPointerUp(event) {
      if (drag === null || event.pointerId !== drag.pointerId) {
        return;
      }
      this.$refs.annoStage.releasePointerCapture?.(event.pointerId);

      if (drag.kind === 'create') {
        const item = drag.item;
        drag = null;
        if (item.t === 'pen') {
          item.p = simplifyPath(item.p, this.annoSpace.w * SIMPLIFY_RATIO);
        }
        this.annoRenderPreview(null);
        if (!this.annoIsMeaningful(item)) {
          return;
        }
        this.annoPushHistory();
        this.annoItems.push(item);
        this.annoRenderAll();

        return;
      }

      drag = null;
      this.annoRenderAll();
    },

    annoPointerCancel(event) {
      if (drag !== null && event.pointerId === drag.pointerId) {
        drag = null;
        this.annoRenderPreview(null);
        this.annoRenderAll();
      }
    },

    /** Ein Klick ohne Zugbewegung soll kein unsichtbares Element hinterlassen. */
    annoIsMeaningful(item) {
      switch (item.t) {
        case 'pen':
          return item.p.length > 1 || item.w > 0;
        case 'line':
          return Math.hypot(item.x2 - item.x1, item.y2 - item.y1) > item.w;
        case 'rect':
        case 'ellipse':
        case 'mask':
        case 'rules':
          return item.rw > 2 && item.rh > 2;
        default:
          return true;
      }
    },

    annoCreateItem(point, event) {
      const base = {
        id: createItemId(),
        c: this.annoColor,
        o: this.annoOpacity,
      };
      // Ein Stift zeichnet feiner als ein Finger, solange der Nutzer die
      // Strichstärke nicht selbst angefasst hat.
      const width = event.pointerType === 'pen' ? Math.max(2, this.annoWidth / 2) : this.annoWidth;

      switch (this.annoTool) {
        case 'pen':
        case 'highlighter':
          return { ...base, t: 'pen', w: width, p: [[point.x, point.y]] };
        case 'line':
          return { ...base, t: 'line', w: width, x1: point.x, y1: point.y, x2: point.x, y2: point.y, head: 'none' };
        case 'arrow':
          return { ...base, t: 'line', w: width, x1: point.x, y1: point.y, x2: point.x, y2: point.y, head: 'end' };
        case 'rect':
          return { ...base, t: 'rect', w: width, x: point.x, y: point.y, rw: 1, rh: 1, f: null };
        case 'ellipse':
          return { ...base, t: 'ellipse', w: width, x: point.x, y: point.y, rw: 1, rh: 1, f: null };
        case 'rules':
          return { ...base, t: 'rules', w: width, x: point.x, y: point.y, rw: 1, rh: 1, gap: this.annoFontSize * 1.6 };
        case 'mask':
          return { ...base, t: 'mask', o: 1, x: point.x, y: point.y, rw: 1, rh: 1 };
        case 'marker':
          return {
            ...base,
            t: 'marker',
            x: point.x,
            y: point.y,
            r: this.annoFontSize * 0.9,
            n: Math.min(99, this.annoItems.filter((item) => item.t === 'marker').length + 1),
          };
        default:
          return { ...base, t: 'pen', w: width, p: [[point.x, point.y]] };
      }
    },

    annoExtendItem(item, origin, point) {
      if (item.t === 'pen') {
        item.p.push([point.x, point.y]);

        return;
      }
      if (item.t === 'line') {
        item.x2 = point.x;
        item.y2 = point.y;

        return;
      }
      if (item.t === 'marker') {
        item.x = point.x;
        item.y = point.y;

        return;
      }
      item.x = round1(Math.min(origin.x, point.x));
      item.y = round1(Math.min(origin.y, point.y));
      item.rw = Math.max(1, round1(Math.abs(point.x - origin.x)));
      item.rh = Math.max(1, round1(Math.abs(point.y - origin.y)));
    },

    // --- Auswahl, Verschieben, Skalieren --------------------------------

    annoBounds(item) {
      switch (item.t) {
        case 'pen': {
          const xs = item.p.map((point) => point[0]);
          const ys = item.p.map((point) => point[1]);
          const pad = item.w / 2;

          return {
            x: Math.min(...xs) - pad,
            y: Math.min(...ys) - pad,
            rw: Math.max(...xs) - Math.min(...xs) + item.w,
            rh: Math.max(...ys) - Math.min(...ys) + item.w,
          };
        }
        case 'line':
          return {
            x: Math.min(item.x1, item.x2) - item.w,
            y: Math.min(item.y1, item.y2) - item.w,
            rw: Math.abs(item.x2 - item.x1) + item.w * 2,
            rh: Math.abs(item.y2 - item.y1) + item.w * 2,
          };
        case 'marker':
          return { x: item.x - item.r, y: item.y - item.r, rw: item.r * 2, rh: item.r * 2 };
        case 'text':
          return { x: item.x, y: item.y, rw: item.bw, rh: item.bh };
        default:
          return { x: item.x, y: item.y, rw: item.rw, rh: item.rh };
      }
    },

    annoHitTest(point) {
      // Von hinten nach vorn: Das zuletzt gezeichnete Element liegt oben.
      for (let index = this.annoItems.length - 1; index >= 0; index -= 1) {
        const item = this.annoItems[index];
        const box = this.annoBounds(item);
        const pad = this.annoSpace.w * 0.006;
        if (point.x >= box.x - pad && point.x <= box.x + box.rw + pad
          && point.y >= box.y - pad && point.y <= box.y + box.rh + pad) {
          return item;
        }
      }

      return null;
    },

    annoBeginSelect(point, event) {
      const handle = event.target?.dataset?.annoHandle;
      const selected = this.annoSelectedItem();
      if (handle && selected) {
        this.annoPushHistory();
        drag = { kind: 'resize', pointerId: event.pointerId, pointerType: event.pointerType, item: selected };

        return;
      }

      const item = this.annoHitTest(point);
      this.annoSelectedId = item?.id ?? '';
      this.annoUpdateSelectionFrame();
      if (item === null) {
        return;
      }
      this.annoColor = item.c;
      this.annoSyncToolbar();
      this.annoPushHistory();
      drag = {
        kind: 'move',
        pointerId: event.pointerId,
        pointerType: event.pointerType,
        item,
        last: point,
      };
    },

    annoTranslate(item, dx, dy) {
      if (item.t === 'pen') {
        item.p = item.p.map((point) => [round1(point[0] + dx), round1(point[1] + dy)]);

        return;
      }
      if (item.t === 'line') {
        item.x1 = round1(item.x1 + dx);
        item.y1 = round1(item.y1 + dy);
        item.x2 = round1(item.x2 + dx);
        item.y2 = round1(item.y2 + dy);

        return;
      }
      item.x = round1(item.x + dx);
      item.y = round1(item.y + dy);
    },

    /** Skaliert wird in Ausbaustufe 1 nur, was ein Rechteck aufspannt. */
    annoResize(item, point) {
      if (!['rect', 'ellipse', 'mask', 'rules'].includes(item.t)) {
        return;
      }
      item.rw = Math.max(2, round1(point.x - item.x));
      item.rh = Math.max(2, round1(point.y - item.y));
    },

    annoUpdateSelectionFrame() {
      const item = this.annoSelectedItem();
      if (item === null) {
        this.annoSelectionStyle = '';

        return;
      }
      const box = this.annoBounds(item);
      const left = (box.x / this.annoSpace.w) * 100;
      const top = (box.y / this.annoSpace.h) * 100;
      const width = (box.rw / this.annoSpace.w) * 100;
      const height = (box.rh / this.annoSpace.h) * 100;
      this.annoSelectionStyle = `left:${left}%;top:${top}%;width:${width}%;height:${height}%;`;
    },

    annoCanResizeSelection() {
      const item = this.annoSelectedItem();

      return item !== null && ['rect', 'ellipse', 'mask', 'rules'].includes(item.t);
    },

    // --- Text -----------------------------------------------------------

    annoOpenTextDialog(anchor, text) {
      if (text === '' && !this.annoCanAdd()) {
        return;
      }
      this.annoTextAnchor = anchor;
      this.annoTextDraft = text;
      this.annoTextOpen = true;
      this.$nextTick(() => this.$refs.annoTextInput?.focus());
    },

    annoEditSelectedText() {
      const item = this.annoSelectedItem();
      if (item === null || item.t !== 'text') {
        return;
      }
      this.annoTextEditId = item.id;
      this.annoOpenTextDialog({ x: item.x, y: item.y }, item.text);
    },

    annoConfirmText() {
      const text = this.annoTextDraft.trim();
      this.annoTextOpen = false;
      if (text === '') {
        this.annoTextEditId = '';

        return;
      }

      // Gemessen wird im echten Ziel-SVG, damit Schrift und Größe exakt die
      // des späteren Renderns sind (siehe render.js).
      const svg = this.$refs.annoLayer?.querySelector('svg') ?? this.annoEnsureLayerSvg();
      const box = measureTextBox(svg, text, this.annoFontSize);

      this.annoPushHistory();
      const existing = this.annoItems.find((item) => item.id === this.annoTextEditId);
      if (existing) {
        existing.text = text;
        existing.s = this.annoFontSize;
        existing.c = this.annoColor;
        existing.bw = box.bw;
        existing.bh = box.bh;
      } else {
        this.annoItems.push({
          id: createItemId(),
          t: 'text',
          c: this.annoColor,
          o: this.annoOpacity,
          x: this.annoTextAnchor.x,
          y: this.annoTextAnchor.y,
          s: this.annoFontSize,
          f: '#ffffff',
          bw: box.bw,
          bh: box.bh,
          text,
        });
      }
      this.annoTextEditId = '';
      this.annoRenderAll();
    },

    annoCancelText() {
      this.annoTextOpen = false;
      this.annoTextDraft = '';
      this.annoTextEditId = '';
    },

    /**
     * Ohne Elemente gibt es noch kein Overlay-SVG - zum Messen wird eines
     * angelegt und bleibt danach als leere Hülle stehen.
     */
    annoEnsureLayerSvg() {
      const host = this.$refs.annoLayer;
      let svg = host.querySelector('svg');
      if (svg === null) {
        svg = renderOverlay(host, {
          v: 1,
          space: this.annoSpace,
          items: [{ id: createItemId(), t: 'mask', c: '#000000', o: 0.05, x: 0, y: 0, rw: 1, rh: 1 }],
        });
        svg.replaceChildren();
      }

      return svg;
    },

    // --- Verlauf, Löschen, Tastatur --------------------------------------

    annoPushHistory() {
      this.annoHistory.push(JSON.stringify(this.annoItems));
      if (this.annoHistory.length > HISTORY_LIMIT) {
        this.annoHistory.shift();
      }
      this.annoFuture = [];
      this.annoDirty = true;
    },

    annoUndoStep() {
      const previous = this.annoHistory.pop();
      if (previous === undefined) {
        return;
      }
      this.annoFuture.push(JSON.stringify(this.annoItems));
      this.annoItems = JSON.parse(previous);
      this.annoSelectedId = '';
      this.annoUpdateSelectionFrame();
      this.annoRenderAll();
    },

    annoRedoStep() {
      const next = this.annoFuture.pop();
      if (next === undefined) {
        return;
      }
      this.annoHistory.push(JSON.stringify(this.annoItems));
      this.annoItems = JSON.parse(next);
      this.annoSelectedId = '';
      this.annoUpdateSelectionFrame();
      this.annoRenderAll();
    },

    annoDeleteSelected() {
      if (this.annoSelectedId === '') {
        return;
      }
      this.annoPushHistory();
      this.annoItems = this.annoItems.filter((item) => item.id !== this.annoSelectedId);
      this.annoSelectedId = '';
      this.annoUpdateSelectionFrame();
      this.annoRenderAll();
    },

    annoClearAll() {
      if (this.annoItems.length === 0 || !window.confirm('Alle Bildnotizen dieses Bildes entfernen?')) {
        return;
      }
      this.annoPushHistory();
      this.annoItems = [];
      this.annoSelectedId = '';
      this.annoUpdateSelectionFrame();
      this.annoRenderAll();
    },

    annoKeydown(event) {
      if (!this.annoOpen || this.annoTextOpen) {
        return;
      }
      if (event.key === 'Delete' || event.key === 'Backspace') {
        event.preventDefault();
        this.annoDeleteSelected();

        return;
      }
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') {
        event.preventDefault();
        if (event.shiftKey) {
          this.annoRedoStep();
        } else {
          this.annoUndoStep();
        }
      }
    },

    // --- Darstellung ------------------------------------------------------

    annoRenderAll() {
      const host = this.$refs.annoLayer;
      if (!host) {
        return;
      }
      renderOverlay(host, { v: 1, space: this.annoSpace, items: this.annoItems });
      this.annoUpdateSelectionFrame();
    },

    annoRenderPreview(item) {
      const host = this.$refs.annoPreview;
      if (!host) {
        return;
      }
      renderOverlay(host, item === null
        ? null
        : { v: 1, space: this.annoSpace, items: [item] });
    },

    annoStageStyle() {
      return `aspect-ratio:${this.annoSpace.w} / ${this.annoSpace.h};`;
    },

    annoCountLabel() {
      return `${this.annoItems.length} von ${MAX_ITEMS} Elementen`;
    },
  };
}
```

### 5.7 Geändert: `resources/js/notePage.js`

**Import und Mixin** (bei den übrigen Mixins, Zeile 15–18 bzw. 68–71):

```diff
 import { pageTrashMixin } from './pageTrash.js';
+import { imageAnnotatorMixin } from './editor/annotations/annotator.js';
```

```diff
     ...pageLocationMixin(),
     ...pageTrashMixin(),
+    ...imageAnnotatorMixin(),
```

**Editorzugriff** (im `init`, direkt nach `createEditor({…})`, Zeile 553–555):

```diff
       this.bindImageViewer();
+      // Das Mixin darf den Editor nicht selbst halten - er liegt bewusst
+      // außerhalb der Alpine-Reaktivität.
+      this.annoSetEditorAccessor(() => editor);
       this.syncToolbar();
```

**Werkzeugleiste** (in `syncToolbar`, Zeile 2275): Der Annotationsknopf wird
nur bei ausgewähltem Bild aktiv. `imageSelected` kommt neu in den Zustand
(`imageSelected: false` bei den übrigen Feldern).

```diff
       this.inTable = editor.isActive('table');
+      this.imageSelected = editor.isActive('image');
```

**Fehleranzeige:** `annoError` wird an derselben Stelle wie `imageUploadError`
ausgegeben (siehe 5.8).

### 5.8 Geändert: `resources/views/page_note.php`

**Werkzeugknopf**, direkt nach dem bestehenden Anhang-Knopf (Zeile 162):

```html
<button x-show="!isEncrypted() && imageSelected" x-cloak type="button"
        @click.prevent="openImageAnnotator" class="toolbar-button"
        title="Bild beschriften" aria-label="Bild beschriften" x-icon="pencil"></button>
```

**Knopf im Vollbild-Betrachter**, neben dem Schließen-Knopf (Zeile 344 ff.):

```html
<button x-show="canEditPage && !isShared" type="button"
        @click.stop="openImageAnnotatorFromViewer"
        class="sidebar-toggle absolute left-3 top-3 flex rounded-full"
        style="background-color: rgb(0 0 0 / 0.5); color: #ffffff;"
        aria-label="Bild beschriften" x-icon="pencil"></button>
```

**Fehlerzeile** ergänzen (Zeile 198):

```diff
-<p x-show="imageUploadError" x-text="imageUploadError" …></p>
+<p x-show="imageUploadError" x-text="imageUploadError" …></p>
+<p x-show="annoError" x-cloak x-text="annoError" class="note-print-hide mb-4 text-sm" style="color: var(--color-danger);" role="alert"></p>
```

**Dialog einbinden**, bei den übrigen Partials am Dateiende:

```php
<?php include __DIR__ . '/partials/image_annotation_dialog.php'; ?>
```

### 5.9 Neu: `resources/views/partials/image_annotation_dialog.php`

```php
<?php /*
    Vollbild-Editor für Bild-Annotationen (FR-ANNO-01/03/09). Alpine läuft im
    CSP-Build: hier stehen nur Methodennamen und einfache Eigenschaften -
    Werkzeugauswahl und Farbe laufen über data-Attribute, die aktive Klasse
    setzt annoSyncToolbar() imperativ (wie bei der Notiz-Werkzeugleiste).
*/ ?>
<div x-show="annoOpen" x-cloak class="fixed inset-0 z-[130] flex flex-col"
     style="background-color: rgb(0 0 0 / 0.92);"
     @keydown.escape.window="closeImageAnnotator"
     @keydown.window="annoKeydown"
     role="dialog" aria-modal="true" aria-labelledby="anno-dialog-title">

    <div class="flex items-center justify-between gap-3 px-4 py-3" style="background: var(--color-bg);">
        <h2 id="anno-dialog-title" class="text-base font-semibold">Bild beschriften</h2>
        <div class="flex items-center gap-2">
            <span class="hidden text-xs sm:inline" style="color: var(--color-text-muted);" x-text="annoCountLabel()"></span>
            <button type="button" @click="closeImageAnnotator" class="btn btn-quiet">Abbrechen</button>
            <button type="button" @click="applyImageAnnotations" class="btn btn-primary">Übernehmen</button>
        </div>
    </div>

    <div x-ref="annoToolbar" class="editor-toolbar flex flex-wrap items-center gap-1 border-b px-4 py-2"
         style="border-color: var(--color-border); background: var(--color-bg);">
        <button type="button" data-tool="select" @click="annoSelectTool" class="toolbar-button" title="Auswählen" aria-label="Auswählen" x-icon="square"></button>
        <button type="button" data-tool="pen" @click="annoSelectTool" class="toolbar-button" title="Freihand" aria-label="Freihand" x-icon="pencil"></button>
        <button type="button" data-tool="highlighter" @click="annoSelectTool" class="toolbar-button" title="Marker" aria-label="Marker" x-icon="highlighter"></button>
        <button type="button" data-tool="arrow" @click="annoSelectTool" class="toolbar-button" title="Pfeil" aria-label="Pfeil" x-icon="chevron-right"></button>
        <button type="button" data-tool="line" @click="annoSelectTool" class="toolbar-button" title="Linie" aria-label="Linie" x-icon="minus"></button>
        <button type="button" data-tool="rect" @click="annoSelectTool" class="toolbar-button" title="Rechteck" aria-label="Rechteck" x-icon="square"></button>
        <button type="button" data-tool="ellipse" @click="annoSelectTool" class="toolbar-button" title="Ellipse" aria-label="Ellipse" x-icon="circle"></button>
        <button type="button" data-tool="text" @click="annoSelectTool" class="toolbar-button" title="Text" aria-label="Text" x-icon="type"></button>
        <button type="button" data-tool="rules" @click="annoSelectTool" class="toolbar-button" title="Zeilenlinien" aria-label="Zeilenlinien" x-icon="list"></button>
        <button type="button" data-tool="marker" @click="annoSelectTool" class="toolbar-button" title="Nummer" aria-label="Nummer" x-icon="circle-dot"></button>
        <button type="button" data-tool="mask" @click="annoSelectTool" class="toolbar-button" title="Abdecken" aria-label="Abdecken" x-icon="eye-off"></button>
        <span class="toolbar-divider"></span>
        <?php foreach (['#e11d48', '#f97316', '#eab308', '#16a34a', '#2563eb', '#7c3aed', '#111827', '#ffffff'] as $color): ?>
            <button type="button" data-color="<?= $color ?>" @click="annoPickColor" class="anno-swatch"
                    style="background: <?= $color ?>;" title="Farbe <?= $color ?>" aria-label="Farbe <?= $color ?>"></button>
        <?php endforeach; ?>
        <input type="color" :value="annoColor" @input="annoColorInput" class="anno-swatch anno-swatch-picker" aria-label="Eigene Farbe">
        <span class="toolbar-divider"></span>
        <label class="sr-only" for="anno-width">Strichstärke</label>
        <input id="anno-width" type="range" min="1" max="80" step="1" :value="annoWidth" @input="annoWidthInput" class="w-24">
        <label class="sr-only" for="anno-opacity">Deckkraft</label>
        <input id="anno-opacity" type="range" min="0.1" max="1" step="0.05" :value="annoOpacity" @input="annoOpacityInput" class="w-20">
        <span class="toolbar-divider"></span>
        <button type="button" @click="annoUndoStep" class="toolbar-button" title="Rückgängig" aria-label="Rückgängig" x-icon="undo"></button>
        <button type="button" @click="annoRedoStep" class="toolbar-button" title="Wiederholen" aria-label="Wiederholen" x-icon="redo"></button>
        <button type="button" @click="annoDeleteSelected" class="toolbar-button toolbar-button-danger" title="Auswahl löschen" aria-label="Auswahl löschen" x-icon="trash"></button>
        <button type="button" @click="annoClearAll" class="toolbar-button toolbar-button-danger" title="Alle entfernen" aria-label="Alle entfernen" x-icon="x"></button>
    </div>

    <p x-show="annoNotice" x-cloak x-text="annoNotice" class="px-4 py-2 text-xs"
       style="background: color-mix(in srgb, var(--color-danger) 12%, transparent); color: var(--color-danger);" role="status"></p>
    <p x-show="annoError" x-cloak x-text="annoError" class="px-4 py-2 text-xs"
       style="background: var(--color-bg); color: var(--color-danger);" role="alert"></p>

    <div class="anno-viewport flex flex-1 items-center justify-center overflow-auto p-4">
        <div x-ref="annoStage" class="anno-stage" :style="annoStageStyle()"
             @pointerdown="annoPointerDown" @pointermove="annoPointerMove"
             @pointerup="annoPointerUp" @pointercancel="annoPointerCancel"
             @dblclick="annoEditSelectedText">
            <img :src="annoSrc" :alt="annoAlt" class="anno-image" draggable="false">
            <span x-ref="annoLayer" class="anno-layer" aria-hidden="true"></span>
            <span x-ref="annoPreview" class="anno-layer" aria-hidden="true"></span>
            <span x-show="annoSelectionStyle" x-cloak class="anno-selection" :style="annoSelectionStyle">
                <span x-show="annoCanResizeSelection()" data-anno-handle="se" class="anno-handle"></span>
            </span>
        </div>
    </div>

    <div x-show="annoTextOpen" x-cloak class="fixed inset-0 z-[140] flex items-center justify-center p-5"
         style="background-color: rgb(0 0 0 / 0.45);" @click.self="annoCancelText"
         role="dialog" aria-modal="true" aria-labelledby="anno-text-title">
        <div class="w-full max-w-md rounded-xl border p-5"
             style="border-color: var(--color-border); background: var(--color-bg); box-shadow: var(--shadow-md);">
            <h3 id="anno-text-title" class="text-lg font-semibold">Text auf dem Bild</h3>
            <textarea x-ref="annoTextInput" x-model="annoTextDraft" rows="4" maxlength="500"
                      class="mt-3 w-full rounded-md border px-3 py-2"
                      style="border-color: var(--color-border); background: var(--color-bg);"></textarea>
            <p class="mt-1 text-xs" style="color: var(--color-text-muted);">
                Zeilenumbrüche werden übernommen; es wird nicht automatisch umbrochen.
            </p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" @click="annoCancelText" class="btn btn-quiet">Abbrechen</button>
                <button type="button" @click="annoConfirmText" class="btn btn-primary">Einfügen</button>
            </div>
        </div>
    </div>
</div>
```

**Benötigte Symbole:** `minus`, `circle`, `circle-dot`, `type` und `highlighter` fehlen in
`resources/js/icons.js` und kommen dort dazu (Lucide: `Minus`, `Circle`,
`CircleDot`, `Type`, `Highlighter`) — Import oben, Eintrag in der `icons`-Tabelle.
Alle fünf sind in der eingesetzten Lucide-Fassung vorhanden (geprüft).

### 5.10 Geändert: `resources/css/app.css`

Anzuhängen bei den bestehenden Bildregeln (nach `.prose-editor [data-resize-handle]`):

```css
/* Annotations-Overlay ------------------------------------------------- */

/* Bezugsrahmen für das Overlay. Positioniert wird auf den Kasten des <img>
   (siehe attachLayer in annotatedImage.js), nicht auf den des Wirts: Das
   zentrierte Bild ist schmaler als sein Wirt. */
.note-image-host {
  position: relative;
}

.note-image-overlay {
  display: block;
  pointer-events: none;
  position: absolute;
  user-select: none;
}

.note-image-overlay-svg {
  display: block;
  height: 100%;
  width: 100%;
}

/* Öffentliche Freigabe: dort kommt das Overlay serverseitig als <span
   class="note-image"> mit <img> und <svg> (ProseMirrorHtmlRenderer). */
.public-note-content .note-image {
  display: block;
  position: relative;
}

.public-note-content .note-image svg {
  height: 100%;
  left: 0;
  position: absolute;
  top: 0;
  width: 100%;
}

/* Annotationseditor ---------------------------------------------------- */

.anno-viewport {
  touch-action: pan-x pan-y;
}

.anno-stage {
  max-height: 100%;
  max-width: 100%;
  position: relative;
  /* Ohne diese Angabe scrollt jeder Strich die Seite statt zu zeichnen. */
  touch-action: none;
  width: min(100%, 1400px);
}

.anno-image,
.anno-layer,
.anno-layer > svg {
  height: 100%;
  left: 0;
  position: absolute;
  top: 0;
  width: 100%;
}

.anno-image {
  object-fit: fill;
  user-select: none;
  -webkit-user-drag: none;
}

.anno-layer {
  pointer-events: none;
}

.anno-selection {
  border: 2px dashed var(--color-accent);
  pointer-events: none;
  position: absolute;
}

.anno-handle {
  background: var(--color-bg);
  border: 2px solid var(--color-accent);
  border-radius: 50%;
  bottom: -8px;
  height: 16px;
  pointer-events: auto;
  position: absolute;
  right: -8px;
  width: 16px;
}

.anno-swatch {
  border: 2px solid var(--color-border);
  border-radius: 50%;
  height: 22px;
  width: 22px;
}

.anno-swatch.is-active {
  border-color: var(--color-accent);
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--color-accent) 35%, transparent);
}

.anno-swatch-picker {
  background: none;
  padding: 0;
}
```

Beim Druck-Stylesheet (`@media print`, Zeile ~1801) muss das Overlay stehen
bleiben. Da `.note-image-overlay` absolut über dem Bild liegt und das Bild dort
bereits erlaubt ist, genügt eine Ergänzung der bestehenden Bildregel:

```diff
   .prose-editor img,
+  .note-image-overlay,
```

### 5.11 Geändert: `resources/js/noteHistoryDiff.js`

Ohne diese Änderung sähe der Versionsvergleich eine reine Annotationsänderung
nicht — Text und Signatur des Bildblocks blieben identisch.

```diff
+import { annotationTexts } from './editor/annotations/schema.js';
```

```diff
-  if (node.type === 'image') return `[Bild: ${node.attrs?.alt || node.attrs?.title || 'ohne Beschreibung'}]`;
+  if (node.type === 'image') {
+    const texts = annotationTexts(node.attrs?.annotations);
+    const label = node.attrs?.alt || node.attrs?.title || 'ohne Beschreibung';
+
+    return texts.length === 0
+      ? `[Bild: ${label}]`
+      : `[Bild: ${label} · Bildnotizen: ${texts.join(' · ')}]`;
+  }
```

Die Signatur (`signature('image', node)`) enthält die Attribute des Knotens und
ändert sich damit automatisch mit; sie braucht keine eigene Anpassung.

## 6. Backend

### 6.1 Neu: `app/Domain/Notes/ImageAnnotationValidator.php`

```php
<?php

declare(strict_types=1);

namespace App\Domain\Notes;

/**
 * Allowlist-Prüfung der Bild-Annotationen (FR-ANNO-05). Gegenstück zu
 * resources/js/editor/annotations/schema.js - die Typtabelle steht dort
 * wortgleich und muss mit dieser hier zusammen geändert werden.
 *
 * Der Client bereinigt nachsichtig, dieser Validator lehnt streng ab: Ein
 * unbekannter Schlüssel ist ein Fehler und kein stiller Verlust. Sonst
 * driften beide Seiten auseinander, ohne dass es jemandem auffällt.
 */
final class ImageAnnotationValidator
{
    public const MAX_ITEMS = 200;
    public const MAX_POINTS = 400;
    public const MAX_TEXT_LENGTH = 500;
    public const MAX_TEXT_LINES = 12;
    public const MAX_BYTES_PER_IMAGE = 40_000;
    public const MAX_BYTES_PER_DOCUMENT = 300_000;
    public const MAX_SPACE = 20_000;

    private const COORD_LIMIT = 100_000;
    private const VERSION = 1;
    private const HEAD_VALUES = ['none', 'end', 'both'];

    /**
     * @var array<string, array{num: string[], pos: string[], points?: bool,
     *     head?: bool, dash?: bool, fill?: bool, text?: bool}>
     */
    private const TYPES = [
        'pen' => ['num' => ['w'], 'pos' => ['w'], 'points' => true],
        'line' => [
            'num' => ['w', 'x1', 'y1', 'x2', 'y2'],
            'pos' => ['w'],
            'head' => true,
            'dash' => true,
        ],
        'rect' => ['num' => ['w', 'x', 'y', 'rw', 'rh'], 'pos' => ['w', 'rw', 'rh'], 'fill' => true],
        'ellipse' => ['num' => ['w', 'x', 'y', 'rw', 'rh'], 'pos' => ['w', 'rw', 'rh'], 'fill' => true],
        'text' => ['num' => ['x', 'y', 's', 'bw', 'bh'], 'pos' => ['s'], 'fill' => true, 'text' => true],
        'rules' => [
            'num' => ['w', 'x', 'y', 'rw', 'rh', 'gap'],
            'pos' => ['w', 'rw', 'rh', 'gap'],
        ],
        'marker' => ['num' => ['x', 'y', 'r', 'n'], 'pos' => ['r', 'n']],
        'mask' => ['num' => ['x', 'y', 'rw', 'rh'], 'pos' => ['rw', 'rh']],
    ];

    /**
     * @return int Byte-Größe des serialisierten Objekts, damit der Aufrufer
     *             das Dokumentbudget aufaddieren kann.
     * @throws NoteContentException
     */
    public function validate(mixed $value): int
    {
        if (!is_array($value)) {
            throw new NoteContentException('Bildnotizen müssen ein Objekt sein.');
        }
        if (($value['v'] ?? null) !== self::VERSION) {
            throw new NoteContentException('Unbekannte Fassung der Bildnotizen.');
        }
        foreach (array_keys($value) as $key) {
            if (!in_array($key, ['v', 'space', 'items'], true)) {
                throw new NoteContentException("Unerlaubtes Feld in den Bildnotizen: {$key}.");
            }
        }

        $this->validateSpace($value['space'] ?? null);

        $items = $value['items'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new NoteContentException('Bildnotizen brauchen eine Liste von Elementen.');
        }
        if (count($items) > self::MAX_ITEMS) {
            throw new NoteContentException(
                'Ein Bild darf höchstens ' . self::MAX_ITEMS . ' Notizelemente tragen.',
            );
        }

        $ids = [];
        foreach ($items as $item) {
            $id = $this->validateItem($item);
            if (isset($ids[$id])) {
                throw new NoteContentException('Doppelte Kennung in den Bildnotizen.');
            }
            $ids[$id] = true;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $bytes = $encoded === false ? PHP_INT_MAX : strlen($encoded);
        if ($bytes > self::MAX_BYTES_PER_IMAGE) {
            throw new NoteContentException('Die Bildnotizen eines Bildes sind zu umfangreich.');
        }

        return $bytes;
    }

    private function validateSpace(mixed $space): void
    {
        if (!is_array($space)) {
            throw new NoteContentException('Bildnotizen ohne Bezugsgröße.');
        }
        foreach (['w', 'h'] as $key) {
            $value = $space[$key] ?? null;
            if (!is_int($value) || $value < 1 || $value > self::MAX_SPACE) {
                throw new NoteContentException("Ungültige Bezugsgröße der Bildnotizen: {$key}.");
            }
        }
        if (count($space) !== 2) {
            throw new NoteContentException('Unerlaubtes Feld in der Bezugsgröße der Bildnotizen.');
        }
    }

    private function validateItem(mixed $item): string
    {
        if (!is_array($item)) {
            throw new NoteContentException('Ungültiges Element in den Bildnotizen.');
        }

        $type = $item['t'] ?? null;
        if (!is_string($type) || !isset(self::TYPES[$type])) {
            throw new NoteContentException('Unbekannter Typ in den Bildnotizen: ' . json_encode($type));
        }
        $spec = self::TYPES[$type];

        $id = $item['id'] ?? null;
        if (!is_string($id) || preg_match('/^[a-z0-9]{8}$/', $id) !== 1) {
            throw new NoteContentException('Ungültige Kennung in den Bildnotizen.');
        }

        $this->validateColor($item['c'] ?? null, 'Strichfarbe');

        $allowed = array_merge(['id', 't', 'c', 'o'], $spec['num']);
        if ($spec['fill'] ?? false) {
            $allowed[] = 'f';
        }
        if ($spec['head'] ?? false) {
            $allowed[] = 'head';
        }
        if ($spec['dash'] ?? false) {
            $allowed[] = 'd';
        }
        if ($spec['text'] ?? false) {
            $allowed[] = 'text';
        }
        if ($spec['points'] ?? false) {
            $allowed[] = 'p';
        }
        foreach (array_keys($item) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new NoteContentException("Unerlaubtes Feld in den Bildnotizen: {$key}.");
            }
        }

        if (array_key_exists('o', $item)) {
            $opacity = $item['o'];
            if ((!is_int($opacity) && !is_float($opacity)) || $opacity < 0.05 || $opacity > 1) {
                throw new NoteContentException('Ungültige Deckkraft in den Bildnotizen.');
            }
        }

        foreach ($spec['num'] as $key) {
            $value = $item[$key] ?? null;
            if (!is_int($value) && !is_float($value)) {
                throw new NoteContentException("Ungültiger Zahlenwert in den Bildnotizen: {$key}.");
            }
            if (!is_finite((float) $value) || abs((float) $value) > self::COORD_LIMIT) {
                throw new NoteContentException("Zahlenwert außerhalb des Bereichs: {$key}.");
            }
            if (in_array($key, $spec['pos'], true) && (float) $value <= 0) {
                throw new NoteContentException("Wert muss größer als 0 sein: {$key}.");
            }
        }

        if ($type === 'marker') {
            $number = $item['n'];
            if (!is_int($number) || $number < 1 || $number > 99) {
                throw new NoteContentException('Ungültige Nummer in den Bildnotizen.');
            }
        }

        if ($spec['fill'] ?? false) {
            $fill = $item['f'] ?? null;
            if ($fill !== null) {
                $this->validateColor($fill, 'Füllfarbe');
            }
        }

        if (($spec['head'] ?? false) && array_key_exists('head', $item)
            && !in_array($item['head'], self::HEAD_VALUES, true)) {
            throw new NoteContentException('Ungültige Pfeilspitze in den Bildnotizen.');
        }

        if (($spec['dash'] ?? false) && array_key_exists('d', $item) && $item['d'] !== true) {
            throw new NoteContentException('Ungültige Strichelung in den Bildnotizen.');
        }

        if ($spec['text'] ?? false) {
            $this->validateText($item['text'] ?? null);
        }

        if ($spec['points'] ?? false) {
            $this->validatePoints($item['p'] ?? null);
        }

        return $id;
    }

    private function validateColor(mixed $value, string $label): void
    {
        if (!is_string($value) || preg_match('/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $value) !== 1) {
            // Nur Hex-Notation: Funktionsschreibweisen und Farbnamen könnten
            // im SVG-Attribut zu etwas anderem als einer Farbe werden.
            throw new NoteContentException("Ungültige {$label} in den Bildnotizen.");
        }
    }

    private function validateText(mixed $value): void
    {
        if (!is_string($value) || trim($value) === '') {
            throw new NoteContentException('Textelement ohne Inhalt in den Bildnotizen.');
        }
        if (mb_strlen($value) > self::MAX_TEXT_LENGTH) {
            throw new NoteContentException('Text in den Bildnotizen ist zu lang.');
        }
        if (str_contains($value, "\r") || substr_count($value, "\n") >= self::MAX_TEXT_LINES) {
            throw new NoteContentException('Text in den Bildnotizen hat zu viele Zeilen.');
        }
    }

    private function validatePoints(mixed $value): void
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new NoteContentException('Freihandpfad ohne Punkte.');
        }
        if (count($value) > self::MAX_POINTS) {
            throw new NoteContentException('Freihandpfad mit zu vielen Punkten.');
        }
        foreach ($value as $point) {
            if (!is_array($point) || !array_is_list($point) || count($point) !== 2) {
                throw new NoteContentException('Ungültiger Punkt im Freihandpfad.');
            }
            foreach ($point as $coordinate) {
                if ((!is_int($coordinate) && !is_float($coordinate))
                    || !is_finite((float) $coordinate)
                    || abs((float) $coordinate) > self::COORD_LIMIT) {
                    throw new NoteContentException('Punkt außerhalb des Bereichs im Freihandpfad.');
                }
            }
        }
    }

    /**
     * Texte in Lesereihenfolge - für die Volltextsuche (ProseMirrorValidator)
     * und den Markdown-Export.
     *
     * @return string[]
     */
    public static function texts(mixed $value): array
    {
        if (!is_array($value) || !is_array($value['items'] ?? null)) {
            return [];
        }

        $texts = [];
        foreach ($value['items'] as $item) {
            if (is_array($item) && ($item['t'] ?? null) === 'text' && is_string($item['text'] ?? null)) {
                $texts[] = $item['text'];
            }
        }

        return $texts;
    }
}
```

### 6.2 Geändert: `app/Domain/Notes/ProseMirrorValidator.php`

Drei Eingriffe: Konstruktor, Bildprüfung mit Dokumentbudget und Textausgabe.

```diff
 final class ProseMirrorValidator
 {
+    /**
+     * Vorgabewert statt DI-Eintrag: Die Tests erzeugen den Validator an
+     * mehreren Stellen mit `new ProseMirrorValidator()`, und PHP-DI verdrahtet
+     * die Abhängigkeit im Betrieb ohnehin selbst.
+     */
+    public function __construct(
+        private readonly ImageAnnotationValidator $annotations = new ImageAnnotationValidator(),
+    ) {
+    }
+
+    /** Summe aller Annotations-Bytes des gerade geprüften Dokuments. */
+    private int $annotationBytes = 0;
+
     private const NODE_TYPES = [
```

```diff
     public function validate(array $doc): void
     {
         if (($doc['type'] ?? null) !== 'doc') {
             throw new NoteContentException('Wurzelelement muss vom Typ "doc" sein.');
         }
 
+        $this->annotationBytes = 0;
         $this->validateNode($doc);
     }
```

In `validateImage()`, am Ende der Methode:

```diff
         foreach (['width', 'height'] as $key) {
             $value = $attrs[$key] ?? null;
             if ($value !== null && (!is_int($value) && !is_float($value) || $value < 1 || $value > 20_000)) {
                 throw new NoteContentException("Ungültiges Bildattribut: {$key}.");
             }
         }
+
+        $annotations = $attrs['annotations'] ?? null;
+        if ($annotations !== null) {
+            $this->annotationBytes += $this->annotations->validate($annotations);
+            if ($this->annotationBytes > ImageAnnotationValidator::MAX_BYTES_PER_DOCUMENT) {
+                throw new NoteContentException(
+                    'Die Bildnotizen dieser Notiz sind insgesamt zu umfangreich.',
+                );
+            }
+        }
```

In `collectText()` (die private Sammelmethode hinter `extractText`) werden die
Annotationstexte mit aufgenommen, damit eine Beschriftung „Rechnung Müller
2026" auf einem Foto auffindbar ist:

```diff
+        if (($node['type'] ?? null) === 'image') {
+            foreach (ImageAnnotationValidator::texts($node['attrs']['annotations'] ?? null) as $text) {
+                $parts[] = $text . "\n";
+            }
+        }
```

### 6.3 Neu: `app/Domain/Notes/ImageAnnotationSvgRenderer.php`

```php
<?php

declare(strict_types=1);

namespace App\Domain\Notes;

/**
 * Serverseitiges Overlay-SVG für die öffentliche Freigabe und den Export
 * (FR-ANNO-07/11). Zwilling von resources/js/editor/annotations/render.js;
 * für dasselbe Modell entsteht dasselbe Markup.
 *
 * Der Aufrufer liefert nur Daten, die ImageAnnotationValidator bereits
 * passiert haben. Trotzdem wird hier jeder Wert erneut gecastet und jeder
 * Text maskiert - das ist die zweite Verteidigungslinie, nicht die erste.
 */
final class ImageAnnotationSvgRenderer
{
    private const FONT_STACK = "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";
    private const MAX_RULE_LINES = 200;

    /** @param array<string, mixed>|null $annotations */
    public function render(mixed $annotations): string
    {
        if (!is_array($annotations) || !is_array($annotations['items'] ?? null)) {
            return '';
        }
        $space = $annotations['space'] ?? null;
        if (!is_array($space)) {
            return '';
        }
        $width = max(1, (int) ($space['w'] ?? 0));
        $height = max(1, (int) ($space['h'] ?? 0));

        $body = '';
        foreach ($annotations['items'] as $item) {
            if (is_array($item)) {
                $body .= $this->item($item);
            }
        }
        if ($body === '') {
            return '';
        }

        return '<svg viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none"'
            . ' aria-hidden="true" focusable="false">' . $body . '</svg>';
    }

    /** @param array<string, mixed> $item */
    private function item(array $item): string
    {
        return match ($item['t'] ?? '') {
            'pen' => $this->pen($item),
            'line' => $this->line($item),
            'rect' => $this->rect($item),
            'ellipse' => $this->ellipse($item),
            'text' => $this->text($item),
            'rules' => $this->rules($item),
            'marker' => $this->marker($item),
            'mask' => $this->mask($item),
            default => '',
        };
    }

    private function num(mixed $value): string
    {
        $number = is_int($value) || is_float($value) ? (float) $value : 0.0;
        if (!is_finite($number)) {
            $number = 0.0;
        }

        return rtrim(rtrim(number_format(round($number, 1), 1, '.', ''), '0'), '.') ?: '0';
    }

    private function color(mixed $value): string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $value) === 1
            ? $value
            : '#000000';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string, mixed> $item */
    private function opacity(array $item): string
    {
        $value = $item['o'] ?? null;
        if ((!is_int($value) && !is_float($value)) || (float) $value >= 1.0) {
            return '';
        }

        return ' opacity="' . $this->num(max(0.0, (float) $value)) . '"';
    }

    /** @param array<string, mixed> $item */
    private function stroke(array $item): string
    {
        return ' stroke="' . $this->color($item['c'] ?? null) . '"'
            . ' stroke-width="' . $this->num($item['w'] ?? 1) . '"'
            . ' stroke-linecap="round" stroke-linejoin="round" fill="none"';
    }

    /** @param array<string, mixed> $item */
    private function pen(array $item): string
    {
        $points = is_array($item['p'] ?? null) ? $item['p'] : [];
        if ($points === []) {
            return '';
        }
        if (count($points) === 1) {
            $point = $points[0];

            return '<circle cx="' . $this->num($point[0] ?? 0) . '" cy="' . $this->num($point[1] ?? 0) . '"'
                . ' r="' . $this->num(((float) ($item['w'] ?? 1)) / 2) . '"'
                . ' fill="' . $this->color($item['c'] ?? null) . '"' . $this->opacity($item) . '/>';
        }

        $path = '';
        foreach ($points as $index => $point) {
            if (!is_array($point)) {
                continue;
            }
            $path .= ($index === 0 ? 'M ' : ' L ') . $this->num($point[0] ?? 0) . ' ' . $this->num($point[1] ?? 0);
        }

        return '<path d="' . $path . '"' . $this->stroke($item) . $this->opacity($item) . '/>';
    }

    /**
     * Pfeilspitzen als eigener Pfad statt als <marker>: Ein <marker> braucht
     * eine ID in <defs>, und auf einer Seite mit mehreren annotierten Bildern
     * kollidieren diese IDs.
     *
     * @param array<string, mixed> $item
     */
    private function arrowHead(array $item, float $x, float $y, float $angle): string
    {
        $size = max(((float) ($item['w'] ?? 1)) * 4, 12.0);
        $spread = M_PI / 7;
        $ax = $x - $size * cos($angle - $spread);
        $ay = $y - $size * sin($angle - $spread);
        $bx = $x - $size * cos($angle + $spread);
        $by = $y - $size * sin($angle + $spread);

        return '<path d="M ' . $this->num($ax) . ' ' . $this->num($ay)
            . ' L ' . $this->num($x) . ' ' . $this->num($y)
            . ' L ' . $this->num($bx) . ' ' . $this->num($by) . '"'
            . $this->stroke($item) . $this->opacity($item) . '/>';
    }

    /** @param array<string, mixed> $item */
    private function line(array $item): string
    {
        $x1 = (float) ($item['x1'] ?? 0);
        $y1 = (float) ($item['y1'] ?? 0);
        $x2 = (float) ($item['x2'] ?? 0);
        $y2 = (float) ($item['y2'] ?? 0);
        $width = (float) ($item['w'] ?? 1);
        $dash = ($item['d'] ?? null) === true
            ? ' stroke-dasharray="' . $this->num($width * 3) . ' ' . $this->num($width * 2) . '"'
            : '';

        $markup = '<path d="M ' . $this->num($x1) . ' ' . $this->num($y1)
            . ' L ' . $this->num($x2) . ' ' . $this->num($y2) . '"'
            . $this->stroke($item) . $dash . $this->opacity($item) . '/>';

        $head = $item['head'] ?? 'none';
        $angle = atan2($y2 - $y1, $x2 - $x1);
        if ($head === 'end' || $head === 'both') {
            $markup .= $this->arrowHead($item, $x2, $y2, $angle);
        }
        if ($head === 'both') {
            $markup .= $this->arrowHead($item, $x1, $y1, $angle + M_PI);
        }

        return $markup;
    }

    /** @param array<string, mixed> $item */
    private function rect(array $item): string
    {
        $fill = ($item['f'] ?? null) === null ? 'none' : $this->color($item['f']);

        return '<rect x="' . $this->num($item['x'] ?? 0) . '" y="' . $this->num($item['y'] ?? 0) . '"'
            . ' width="' . $this->num($item['rw'] ?? 0) . '" height="' . $this->num($item['rh'] ?? 0) . '"'
            . ' fill="' . $fill . '" stroke="' . $this->color($item['c'] ?? null) . '"'
            . ' stroke-width="' . $this->num($item['w'] ?? 1) . '"' . $this->opacity($item) . '/>';
    }

    /** @param array<string, mixed> $item */
    private function ellipse(array $item): string
    {
        $x = (float) ($item['x'] ?? 0);
        $y = (float) ($item['y'] ?? 0);
        $rw = (float) ($item['rw'] ?? 0);
        $rh = (float) ($item['rh'] ?? 0);
        $fill = ($item['f'] ?? null) === null ? 'none' : $this->color($item['f']);

        return '<ellipse cx="' . $this->num($x + $rw / 2) . '" cy="' . $this->num($y + $rh / 2) . '"'
            . ' rx="' . $this->num($rw / 2) . '" ry="' . $this->num($rh / 2) . '"'
            . ' fill="' . $fill . '" stroke="' . $this->color($item['c'] ?? null) . '"'
            . ' stroke-width="' . $this->num($item['w'] ?? 1) . '"' . $this->opacity($item) . '/>';
    }

    /** @param array<string, mixed> $item */
    private function mask(array $item): string
    {
        return '<rect x="' . $this->num($item['x'] ?? 0) . '" y="' . $this->num($item['y'] ?? 0) . '"'
            . ' width="' . $this->num($item['rw'] ?? 0) . '" height="' . $this->num($item['rh'] ?? 0) . '"'
            . ' fill="' . $this->color($item['c'] ?? null) . '"' . $this->opacity($item) . '/>';
    }

    /** @param array<string, mixed> $item */
    private function rules(array $item): string
    {
        $gap = (float) ($item['gap'] ?? 0);
        if ($gap <= 0) {
            return '';
        }
        $x = (float) ($item['x'] ?? 0);
        $y = (float) ($item['y'] ?? 0);
        $rw = (float) ($item['rw'] ?? 0);
        $count = min(self::MAX_RULE_LINES, (int) floor(((float) ($item['rh'] ?? 0)) / $gap));

        $markup = '';
        for ($index = 1; $index <= $count; ++$index) {
            $lineY = $y + $index * $gap;
            $markup .= '<path d="M ' . $this->num($x) . ' ' . $this->num($lineY)
                . ' L ' . $this->num($x + $rw) . ' ' . $this->num($lineY) . '"'
                . $this->stroke($item) . $this->opacity($item) . '/>';
        }

        return $markup;
    }

    /** @param array<string, mixed> $item */
    private function marker(array $item): string
    {
        $color = $this->color($item['c'] ?? null);
        $radius = (float) ($item['r'] ?? 1);

        return '<circle cx="' . $this->num($item['x'] ?? 0) . '" cy="' . $this->num($item['y'] ?? 0) . '"'
            . ' r="' . $this->num($radius) . '" fill="' . $color . '"' . $this->opacity($item) . '/>'
            . '<text x="' . $this->num($item['x'] ?? 0) . '" y="' . $this->num($item['y'] ?? 0) . '"'
            . ' fill="' . $this->readableInk($color) . '"'
            . ' font-family="' . $this->escape(self::FONT_STACK) . '"'
            . ' font-size="' . $this->num($radius * 1.2) . '" font-weight="700"'
            . ' text-anchor="middle" dominant-baseline="central"' . $this->opacity($item) . '>'
            . $this->escape((string) (int) ($item['n'] ?? 0)) . '</text>';
    }

    private function readableInk(string $color): string
    {
        $red = (int) hexdec(substr($color, 1, 2));
        $green = (int) hexdec(substr($color, 3, 2));
        $blue = (int) hexdec(substr($color, 5, 2));

        return ($red * 299 + $green * 587 + $blue * 114) / 1000 > 150 ? '#111827' : '#ffffff';
    }

    /**
     * Kein automatischer Zeilenumbruch: Die Zeilen stehen so im Modell, wie
     * der Nutzer sie eingegeben hat (siehe Abschnitt 3.3).
     *
     * @param array<string, mixed> $item
     */
    private function text(array $item): string
    {
        $text = (string) ($item['text'] ?? '');
        if ($text === '') {
            return '';
        }
        $size = (float) ($item['s'] ?? 16);
        $x = (float) ($item['x'] ?? 0);
        $y = (float) ($item['y'] ?? 0);
        $padding = $size * 0.25;

        $markup = '';
        $fill = $item['f'] ?? null;
        $boxWidth = $item['bw'] ?? null;
        $boxHeight = $item['bh'] ?? null;
        if ($fill !== null && (is_int($boxWidth) || is_float($boxWidth))
            && (is_int($boxHeight) || is_float($boxHeight))) {
            $markup .= '<rect x="' . $this->num($x - $padding) . '" y="' . $this->num($y - $padding) . '"'
                . ' width="' . $this->num((float) $boxWidth + $padding * 2) . '"'
                . ' height="' . $this->num((float) $boxHeight + $padding * 2) . '"'
                . ' rx="' . $this->num($padding) . '" fill="' . $this->color($fill) . '"'
                . $this->opacity($item) . '/>';
        }

        $tspans = '';
        foreach (explode("\n", $text) as $index => $line) {
            $lineY = $y + $size + $index * $size * 1.25;
            $tspans .= '<tspan x="' . $this->num($x) . '" y="' . $this->num($lineY) . '">'
                . $this->escape($line) . '</tspan>';
        }

        return $markup . '<text fill="' . $this->color($item['c'] ?? null) . '"'
            . ' font-family="' . $this->escape(self::FONT_STACK) . '"'
            . ' font-size="' . $this->num($size) . '"' . $this->opacity($item) . '>'
            . $tspans . '</text>';
    }
}
```

### 6.4 Geändert: `app/Domain/Notes/ProseMirrorHtmlRenderer.php`

```diff
 final class ProseMirrorHtmlRenderer
 {
+    public function __construct(
+        private readonly ImageAnnotationSvgRenderer $overlay = new ImageAnnotationSvgRenderer(),
+    ) {
+    }
+
     /** @param array<string, mixed> $document */
```

```diff
     private function image(array $node, string $shareToken): string
     {
         …
-        return '<img src="' . $url . '" alt="' . $alt . '"' . $width . $height . ' loading="lazy">';
+        $image = '<img src="' . $url . '" alt="' . $alt . '"' . $width . $height . ' loading="lazy">';
+        $overlay = $this->overlay->render($attrs['annotations'] ?? null);
+
+        // Ohne Annotationen bleibt es beim nackten <img> - der zusätzliche
+        // Rahmen entsteht nur, wenn er auch etwas zu tragen hat.
+        return $overlay === '' ? $image : '<span class="note-image">' . $image . $overlay . '</span>';
     }
```

`resources/views/public_share_layout.php` braucht keine Änderung; die
Positionierung steht in `app.css` (5.10).

### 6.5 Geändert: `app/Domain/Export/MarkdownRenderer.php`

Annotationen lassen sich in Markdown nicht darstellen, ohne sie einzubrennen —
was dieses Konzept ausschließt. Damit trotzdem nichts verloren geht, wandern
die Texte als Bildunterschrift unter das Bild:

```diff
     private function image(array $node, ?callable $resolveImage): string
     {
         …
-        return $target === null ? '' : '![' . $alt . '](' . $this->url($target) . ')';
+        if ($target === null) {
+            return '';
+        }
+
+        $markdown = '![' . $alt . '](' . $this->url($target) . ')';
+        $texts = ImageAnnotationValidator::texts($attrs['annotations'] ?? null);
+        if ($texts !== []) {
+            $labels = [];
+            foreach ($texts as $index => $text) {
+                $labels[] = ($index + 1) . '. ' . $this->escape(str_replace("\n", ' ', $text));
+            }
+            $markdown .= "\n\n_Bildnotizen: " . implode(' · ', $labels) . '_';
+        }
+
+        return $markdown;
     }
```

### 6.6 Geändert: `app/Domain/Export/NotebookExportService.php`

Zusätzlich zur unveränderten Bilddatei kommt das reine Overlay als eigene
SVG-Datei ins Archiv. Wer das Bild mit Annotationen außerhalb der Anwendung
braucht, legt beide Dateien übereinander.

Konstruktor um `ImageAnnotationSvgRenderer $overlay` erweitern (mit
Vorgabewert, damit der bestehende DI-Eintrag unverändert bleiben kann), und
nach dem Schreiben des Markdowns:

```php
if (($page['type'] ?? 'note') === 'note') {
    foreach ($this->annotationSidecars($pageId, $imageTargets) as $target => $svg) {
        $zip->addFromString($folder . '/' . $target, $svg);
        ++$written;
    }
}
```

```php
/**
 * Ein Overlay je annotiertem Bild, benannt nach der Bilddatei:
 * `files/bild-ab12cd34ef56.png` → `files/bild-ab12cd34ef56.annotations.svg`.
 *
 * @param array<string, string> $imageTargets Token-Hash → Pfad im Archiv
 * @return array<string, string> Pfad im Archiv → SVG-Inhalt
 */
private function annotationSidecars(int $pageId, array $imageTargets): array
{
    $row = $this->contents->find($pageId);
    if ($row === null) {
        return [];
    }
    $document = json_decode((string) $row['content'], true);
    if (!is_array($document)) {
        return [];
    }

    $sidecars = [];
    $this->collectSidecars($document, $imageTargets, $sidecars);

    return $sidecars;
}

/**
 * @param array<string, mixed> $node
 * @param array<string, string> $imageTargets
 * @param array<string, string> $sidecars
 */
private function collectSidecars(array $node, array $imageTargets, array &$sidecars): void
{
    if (($node['type'] ?? null) === 'image') {
        $src = (string) ($node['attrs']['src'] ?? '');
        $svg = $this->overlay->render($node['attrs']['annotations'] ?? null);
        if ($svg !== '' && preg_match('#^/api/attachments/([a-f0-9]{64})$#', $src, $matches) === 1) {
            $target = $imageTargets[hash('sha256', $matches[1])] ?? null;
            if ($target !== null) {
                $name = preg_replace('/\.[^.]+$/', '', $target) . '.annotations.svg';
                $sidecars[$name] = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                    . str_replace('<svg ', '<svg xmlns="http://www.w3.org/2000/svg" ', $svg) . "\n";
            }
        }
    }

    foreach ((array) ($node['content'] ?? []) as $child) {
        if (is_array($child)) {
            $this->collectSidecars($child, $imageTargets, $sidecars);
        }
    }
}
```

Der Namensraum wird beim Sidecar nachgetragen, weil das eingebettete SVG in
der öffentlichen Freigabe innerhalb von HTML steht und ihn dort nicht braucht,
eine eigenständige `.svg`-Datei ohne ihn aber von keinem Programm geöffnet wird.

### 6.7 Unberührt

`AttachmentService`, `ImageCompressionService`, `UploadStorage`,
`PageCopyService`, `NoteService`, sämtliche Repositories, die Speicherquote,
`ZipImportService` und `NoteRewriteService` bleiben unverändert. Es entsteht
kein neuer Speicherverbrauch im Dateisystem, keine neue Route und keine neue
Ratenbegrenzung, weil kein Upload stattfindet.

## 7. Auswirkungen im Überblick

| Pfad | Verhalten mit Annotationen | Aufwand |
|---|---|---|
| Autosave / Konflikt / Version | unverändert | keiner |
| Versionswiederherstellung | stellt Text und Annotationen gemeinsam wieder her | keiner |
| Offline-Entwurf und Sync | unverändert; auch offline vollständig bearbeitbar, da kein Serveraufruf beteiligt ist | keiner |
| Offline hochgeladene Bilder | `uploadOfflineAttachments` tauscht nur `src`, `annotations` bleiben | keiner |
| Seitenkopie, Notizbuch-Freigabe | unverändert | keiner |
| Bildkompression | Annotationen bleiben positionsgenau (fester `space`) | keiner |
| Verschlüsselte Notiz | nicht anwendbar (keine Bilder) | keiner |
| KI-Überarbeitung | Bildknoten bleiben unverändert, wie bisher zugesichert | keiner |
| Öffentliche Freigabe | serverseitig gerendertes Overlay | neuer Renderer |
| Druck / PDF | Overlay wird mitgedruckt | CSS |
| Bildbetrachter (Handy) | Overlay im Vollbild, Einstieg in den Editor | klein |
| Versions-Diff | erkennt geänderte Annotationen, zeigt deren Texte | klein |
| Suche | Annotationstexte werden gefunden | klein |
| Markdown-Export | Bild + Textliste + SVG-Sidecar | mittel |
| ZIP-Import | Annotationen gehen verloren (bewusst, siehe 12.3) | keiner |

## 8. Tests

### 8.1 Neu: `tests/Frontend/annotations.test.js`

Läuft mit `node --test` ohne DOM — deshalb sind `schema.js` und
`buildOverlayMarkup` reine Funktionen über Daten und Zeichenketten.

```js
import assert from 'node:assert/strict';
import test from 'node:test';

const { normalizeAnnotations, serializeAnnotations, annotationTexts, simplifyPath, MAX_ITEMS }
  = await import('../../resources/js/editor/annotations/schema.js');
const { buildOverlayMarkup } = await import('../../resources/js/editor/annotations/render.js');

function annotations(items) {
  return { v: 1, space: { w: 1000, h: 800 }, items };
}

const line = {
  id: 'abcd1234', t: 'line', c: '#e11d48', w: 4,
  x1: 10, y1: 20, x2: 30, y2: 40, head: 'end',
};

test('verwirft Elemente mit unerlaubter Farbe, Typ oder Zahl', () => {
  assert.equal(normalizeAnnotations(annotations([{ ...line, c: 'red' }])), null);
  assert.equal(normalizeAnnotations(annotations([{ ...line, c: 'url(#x)' }])), null);
  assert.equal(normalizeAnnotations(annotations([{ ...line, t: 'script' }])), null);
  assert.equal(normalizeAnnotations(annotations([{ ...line, x1: Number.NaN }])), null);
  assert.equal(normalizeAnnotations(annotations([{ ...line, x1: 1e9 }])), null);
  assert.equal(normalizeAnnotations(annotations([{ ...line, w: 0 }])), null);
});

test('verwirft eine unbrauchbare Bezugsgröße und eine fremde Fassung', () => {
  assert.equal(normalizeAnnotations({ v: 2, space: { w: 10, h: 10 }, items: [line] }), null);
  assert.equal(normalizeAnnotations({ v: 1, space: { w: 0, h: 10 }, items: [line] }), null);
  assert.equal(normalizeAnnotations({ v: 1, space: { w: 99999, h: 10 }, items: [line] }), null);
});

test('rundet Koordinaten auf eine Nachkommastelle und deckelt die Elementzahl', () => {
  const value = normalizeAnnotations(annotations([{ ...line, x1: 10.06 }]));
  assert.equal(value.items[0].x1, 10.1);

  const many = Array.from({ length: MAX_ITEMS + 20 }, (_item, index) => ({
    ...line, id: `a${String(index).padStart(7, '0')}`.slice(0, 8),
  }));
  assert.equal(normalizeAnnotations(annotations(many)).items.length, MAX_ITEMS);
});

test('Douglas-Peucker behält Anfang und Ende und dünnt die Mitte aus', () => {
  const points = Array.from({ length: 200 }, (_point, index) => [index, 0]);
  const simplified = simplifyPath(points, 1);
  assert.deepEqual(simplified[0], [0, 0]);
  assert.deepEqual(simplified.at(-1), [199, 0]);
  assert.ok(simplified.length < 10);
});

test('liefert Texte in Lesereihenfolge', () => {
  const text = {
    id: 'text0001', t: 'text', c: '#111827', x: 1, y: 2, s: 20,
    f: null, bw: 100, bh: 20, text: 'Zuerst',
  };
  assert.deepEqual(annotationTexts(annotations([text, line])), ['Zuerst']);
});

test('erzeugt für jeden Typ Markup und für unbekannte Typen keines', () => {
  const markup = buildOverlayMarkup(annotations([line]));
  assert.match(markup, /^<svg /);
  assert.match(markup, /viewBox="0 0 1000 800"/);
  assert.match(markup, /<path /);
  assert.equal(buildOverlayMarkup(annotations([{ ...line, t: 'iframe' }])), '');
  assert.equal(buildOverlayMarkup(null), '');
});

test('maskiert Text statt ihn als Markup auszugeben', () => {
  const text = {
    id: 'text0002', t: 'text', c: '#111827', x: 0, y: 0, s: 20,
    f: '#ffffff', bw: 10, bh: 10, text: '<script>alert("x")</script> & "Anführung"',
  };
  const markup = buildOverlayMarkup(annotations([text]));
  assert.ok(!markup.includes('<script>'));
  assert.match(markup, /&lt;script&gt;/);
  assert.match(markup, /&quot;/);
});

test('trimmt bei Budgetüberschreitung von hinten statt alles zu verwerfen', () => {
  const long = Array.from({ length: 150 }, (_item, index) => ({
    ...line,
    id: `b${String(index).padStart(7, '0')}`.slice(0, 8),
    t: 'pen',
    p: Array.from({ length: 200 }, (_point, step) => [step, step]),
  }));
  const value = serializeAnnotations(annotations(long));
  assert.ok(value.items.length > 0);
  assert.ok(value.items.length < 150);
});
```

Ergänzend in derselben Datei: `sanitizeNoteDoc` meldet `changed`, wenn ein
Bildknoten kaputte Annotationen trägt, und lässt ein Bild ohne Annotationen
unverändert.

### 8.2 Neu: `tests/Unit/Domain/Notes/ImageAnnotationValidatorTest.php`

Prüffälle, je einer pro Zeile:

| Fall | Erwartung |
|---|---|
| gültiges Minimalobjekt (ein `line`) | kein Fehler, Rückgabe > 0 |
| jeder der acht Typen einmal gültig | kein Fehler |
| `v: 2` | `NoteContentException` |
| unbekannter Typ `iframe` | `NoteContentException` |
| unbekannter Schlüssel `onload` am Element | `NoteContentException` |
| unbekannter Schlüssel in `space` | `NoteContentException` |
| Farbe `red`, `rgb(1,2,3)`, `url(#x)` | je `NoteContentException` |
| `INF`, `NAN`, `1e9` als Koordinate | je `NoteContentException` |
| `w: 0` bzw. `rw: -5` | je `NoteContentException` |
| Text leer / 501 Zeichen / 13 Zeilen | je `NoteContentException` |
| 401 Punkte im Freihandpfad | `NoteContentException` |
| 201 Elemente | `NoteContentException` |
| doppelte `id` | `NoteContentException` |
| Objekt > 40 000 Byte | `NoteContentException` |
| `texts()` liefert nur Textelemente, in Reihenfolge | Zusicherung |

### 8.3 Neu: `tests/Unit/Domain/Notes/ImageAnnotationSvgRendererTest.php`

| Fall | Erwartung |
|---|---|
| Text mit `<script>`, `"` und `&` | maskiert, kein `<script>` in der Ausgabe |
| Farbe außerhalb der Hex-Notation (am Validator vorbei) | fällt auf `#000000` zurück |
| unbekannter Typ | leere Ausgabe für dieses Element |
| leere Elementliste | Rückgabe `''` (kein leeres `<svg>`) |
| `rules` mit `gap` 0 | Rückgabe `''`, keine Endlosschleife |
| `rules` mit `rh/gap` > 200 | höchstens 200 Linien |
| `line` mit `head: both` | drei Pfade (Linie und zwei Spitzen) |
| `viewBox` entspricht `space` | Zusicherung |

### 8.4 Ergänzt: `tests/Unit/Domain/Notes/ProseMirrorValidatorTest.php`

| Fall | Erwartung |
|---|---|
| Bild **ohne** `annotations` | weiterhin gültig (Regression für Bestandsnotizen) |
| Bild mit gültigen `annotations` | gültig |
| Bild mit kaputten `annotations` | `NoteContentException` |
| zehn Bilder mit je 35 000 Byte | `NoteContentException` (Dokumentbudget) |
| `extractText` | enthält die Annotationstexte |

### 8.5 Ergänzt: `tests/Integration/Domain/PublicShareServiceTest.php`

| Fall | Erwartung |
|---|---|
| freigegebene Notiz mit annotiertem Bild | HTML enthält `<span class="note-image">`, `<img` und `<svg` |
| freigegebene Notiz ohne Annotationen | HTML enthält weiterhin nur `<img`, keinen zusätzlichen Rahmen |

### 8.6 Ergänzt: `tests/Integration/Domain/Export/…`

| Fall | Erwartung |
|---|---|
| Export einer Notiz mit annotiertem Bild | Archiv enthält Bilddatei **und** `…annotations.svg` |
| dasselbe Markdown | enthält die Zeile `_Bildnotizen: 1. …_` |

### 8.7 Manuelle Prüfmatrix

| # | Fall | Erwartung |
|---|---|---|
| 1 | Annotieren, Bild in der Notiz verkleinern | Overlay skaliert mit, nichts verrutscht |
| 2 | Annotieren, dann „Bilder komprimieren" | Annotationen unverändert positioniert |
| 3 | Annotieren, Seite neu laden | Annotationen vorhanden |
| 4 | Annotieren offline, danach online | Annotationen nach Sync serverseitig vorhanden |
| 5 | Annotierten Bildknoten kopieren und einfügen | beide Vorkommen tragen die Annotationen |
| 6 | Notiz öffentlich teilen | Overlay im anonymen Browser sichtbar |
| 7 | Notiz drucken / als PDF | Overlay im Ausdruck sichtbar |
| 8 | Ältere Version wiederherstellen | Textstand und Annotationsstand passen zusammen |
| 9 | Zeichnen auf iPhone mit Finger, auf iPad mit Stift | kein Wegscrollen, Handballen wird ignoriert |
| 10 | 200 Elemente erreicht | verständlicher Hinweis statt Speicherfehler |
| 11 | Export | Sidecar und Textliste im Archiv |
| 12 | Nur-Lese-Freigabe öffnen | Overlay sichtbar, kein Einstieg in den Editor |
| 13 | Werkzeug „Abdecken" erstmals wählen | Hinweis, dass der Bildinhalt erhalten bleibt |
| 14 | Zwei annotierte Bilder in einer Notiz | beide Overlays korrekt, keine ID-Kollision |

**Verifikation** in der CI-Reihenfolge aus `AGENTS.md`: `composer audit`,
`composer cs`, `composer stan`, `composer test`; danach
`npm audit --audit-level=high`, `npm test`, `npm run build`.

## 9. Umsetzungsreihenfolge

Jeder Schritt ist für sich lauffähig und hat ein prüfbares Ergebnis.

| # | Schritt | Dateien | Abnahme |
|---|---|---|---|
| 1 | Datenmodell | `annotations/schema.js` | `npm test` (8.1, Teil Schema) grün |
| 2 | Renderer | `annotations/render.js` | `npm test` (8.1, Teil Markup) grün |
| 3 | Serverprüfung | `ImageAnnotationValidator.php`, `ProseMirrorValidator.php` | 8.2 und 8.4 grün; Bestandsnotiz weiterhin speicherbar |
| 4 | Anzeige im Editor | `annotatedImage.js`, `editor/index.js`, `app.css` | Ein von Hand ins JSON geschriebenes Overlay erscheint korrekt und skaliert mit |
| 5 | Bereinigung | `sanitize.js` | Kaputte Annotationen aus der Zwischenablage blockieren das Speichern nicht |
| 6 | Annotationseditor | `annotations/annotator.js`, `image_annotation_dialog.php`, `icons.js`, `app.css` | Zeichnen, Auswählen, Verschieben, Undo/Redo, Übernehmen |
| 7 | Einstiege | `notePage.js`, `page_note.php` | Werkzeugknopf und Knopf im Vollbild-Betrachter öffnen den Editor am richtigen Knoten |
| 8 | Öffentliche Freigabe | `ImageAnnotationSvgRenderer.php`, `ProseMirrorHtmlRenderer.php`, `app.css` | 8.3 und 8.5 grün; anonymer Browser zeigt das Overlay |
| 9 | Versionsvergleich | `noteHistoryDiff.js` | Reine Annotationsänderung erscheint im Diff |
| 10 | Export | `MarkdownRenderer.php`, `NotebookExportService.php` | 8.6 grün |
| 11 | Dokumentation | `docs/URS.md`, `AGENTS.md` | FR-ANNO-01–12 aufgenommen, Konzeptverweis ergänzt |

Nach Schritt 4 ist das Merkmal schon sichtbar, ohne dass es bedienbar wäre —
ein guter Punkt, um Positionierung und Skalierung auf echten Geräten zu prüfen,
bevor die aufwendige Bedienoberfläche entsteht.

## 10. Sicherheit und Datenschutz

1. **„Abdecken" ist kein Schwärzen.** Eine deckende Fläche über einer
   Kontonummer verbirgt sie nur in der Darstellung; die Pixel liegen unverändert
   im Attachment und sind über `/api/attachments/<token>` bzw. in der
   öffentlichen Freigabe über `/s/<token>/images/<hash>` abrufbar. Der
   Annotationseditor weist beim ersten Einsatz des Werkzeugs je Sitzung darauf
   hin (`annoSelectTool`). Ein echtes Unkenntlichmachen setzt voraus, die
   Bilddatei zu ersetzen — das widerspricht der Nicht-Destruktivität dieses
   Konzepts (offene Frage 12.2).
2. **Kein rohes HTML, kein SVG vom Client.** Der Client schickt nie SVG-Markup,
   sondern ausschließlich das geprüfte Zahlen- und Textmodell. Das Markup
   entsteht auf beiden Seiten aus diesem Modell. Die Zusicherung aus FR-NOTE-03
   bleibt vollständig erhalten.
3. **Farben nur in Hex-Notation.** Funktionsschreibweisen und Farbnamen sind
   verboten, damit in einem SVG-Attribut nichts anderes als eine Farbe landen
   kann. Der Renderer fällt zusätzlich auf `#000000` zurück.
4. **Kein CSP-Risiko.** Inline-SVG-Elemente sind kein `img-src`-Fall und
   brauchen weder eine neue Direktive noch `unsafe-inline` für Skripte. Es
   entstehen keine `<script>`, `<foreignObject>`, `<image>` oder `<use>`.
5. **DoS-Grenzen** aus 3.5 werden serverseitig durchgesetzt; die 1-MB-Grenze des
   Dokuments bleibt die letzte Schranke. `rules` ist zusätzlich auf 200 Linien
   gedeckelt, damit ein winziger `gap` keine Renderlast erzeugt.
6. **Rechte** ändern sich nicht: Annotieren darf, wer die Notiz bearbeiten darf.
   Im Lesemodus und in geteilten Notizen ohne Schreibrecht ist der Editor nicht
   erreichbar; das Overlay wird trotzdem angezeigt.

## 11. Vorgeschlagene Anforderungen für `docs/URS.md`

| ID | Anforderung | Prio |
|---|---|---|
| FR-ANNO-01 | Bilder in Notizen können mit Annotationen versehen werden. Die Bilddatei wird dabei nicht verändert; die Annotationen liegen als Overlay über dem Bild. | M |
| FR-ANNO-02 | Annotationen sind jederzeit erneut bearbeitbar: verschieben, skalieren, Eigenschaften ändern, löschen. | M |
| FR-ANNO-03 | Werkzeuge mindestens: Freihand, Marker, Linie, Pfeil, Rechteck, Ellipse, Textkasten, Zeilenlinien, nummerierter Marker, Abdeckung. | M |
| FR-ANNO-04 | Annotationen werden als Attribut des Bildknotens im ProseMirror-JSON gespeichert; keine eigene Tabelle, keine eigene Datei. | M |
| FR-ANNO-05 | Serverseitige Allowlist-Validierung mit Grenzen für Elementzahl, Punktzahl, Textlänge und JSON-Größe je Bild und je Dokument. | M |
| FR-ANNO-06 | Annotationen skalieren verlustfrei mit der Anzeigegröße und bleiben nach serverseitiger Bildkompression positionsgenau. | M |
| FR-ANNO-07 | Annotationen erscheinen im Editor, im Lesemodus, im Bildbetrachter, im Druck/PDF und in der öffentlichen Freigabe. | M |
| FR-ANNO-08 | Annotationen sind Teil des Notizinhalts und damit von Autosave, Versionsverlauf, Wiederherstellung, Konfliktbehandlung, Seitenkopie und Offlinebetrieb erfasst. | M |
| FR-ANNO-09 | Der Annotationseditor ist mit Maus, Touch und Stift bedienbar und bietet Undo/Redo. | S |
| FR-ANNO-10 | Texte aus Annotationen sind über die Volltextsuche auffindbar. | S |
| FR-ANNO-11 | Beim Export enthält das Archiv das unveränderte Bild, die Annotationstexte im Markdown und das Overlay als SVG-Sidecar. | S |
| FR-ANNO-12 | Das Werkzeug „Abdecken" weist darauf hin, dass es Bildinhalte nicht dauerhaft entfernt. | M |
| FR-ANNO-13 | Das Werkzeug „Maßband" zeichnet eine Maßlinie zwischen zwei Punkten; die Länge wird frei eingetragen und mit der Linie zusammen dargestellt. | S |

## 12. Offene Fragen

1. **„Zeilenlinien"** ist als Block waagerechter Hilfslinien ausgelegt
   (Schreiblinien auf einem fotografierten Blatt, Abschnitt 3.3). War
   stattdessen das Unterstreichen einzelner Textzeilen in einem Screenshot
   gemeint, genügt das vorhandene `line`-Element und der Typ `rules` entfällt
   ersatzlos — samt seiner Zeilen in Schema, Renderer und Validator.
2. **Dauerhaftes Unkenntlichmachen.** Soll es zusätzlich zum
   nicht-destruktiven „Abdecken" eine bewusst destruktive Funktion geben, die
   den Bereich in der Bilddatei überschreibt? Dieses Konzept sieht sie nicht vor.
3. **Export-Rundlauf.** Sollen Annotationen beim ZIP-Import zurückgelesen
   werden? Das setzt ein maschinenlesbares Sidecar (JSON statt SVG) und eine
   Erweiterung von `ZipImportService` voraus.
4. **Zoom im Annotationseditor.** Ausbaustufe 1 zeigt das Bild in voller Breite
   der Bühne; Zoomen und Verschieben sind nicht enthalten. Auf kleinen Displays
   kann das für feine Striche knapp werden. Die Umrechnung in
   `annoPoint()` läuft über `getBoundingClientRect()` und trägt einen späteren
   Zoom ohne Änderung mit.
5. **Skalieren weiterer Typen.** *Erledigt durch Abschnitt 13.* Ausbaustufe 1
   skalierte nur, was ein Rechteck aufspannt (`rect`, `ellipse`, `mask`,
   `rules`); Freihand, Linien und Textkästen ließen sich verschieben und
   löschen, aber nicht ziehen. Ausbaustufe 2 (Abschnitt 13.5) ergänzt Griffe
   für jeden Typ.

## 13. Ausbaustufe 2: Auswahl und Verschieben

| Feld | Wert |
|---|---|
| Planungsstand | ausformuliert und implementiert |
| Datum | 2026-08-31 |
| Bezug | FR-ANNO-02 („skalieren" jetzt vollständig), Abschnitt 12.5 |
| Neue Abhängigkeiten | keine |
| Neue Migration / Endpunkte | keine |
| Vertragsänderungen | keine |

Die Ausbaustufe 1 (Abschnitt 5.6) konnte bereits auswählen und verschieben -
aber nur über das explizit zu wählende Werkzeug „Auswählen", mit History-Eintrag
und Dirty-Flag schon beim bloßen Antippen, ohne Rückmeldung beim Überfahren
und mit Ziehgriffen nur für die Rechteck-Typen. Dieser Abschnitt beseitigt die
vier Schwächen; alles spielt sich in `annotator.js`, dem Dialog-Partial und
`app.css` ab. Datenmodell (Abschnitt 3), serverseitige Validierung
(`ImageAnnotationValidator`) und beide Renderer bleiben unberührt - es ändern
sich nur Attributwerte im bereits erlaubten Rahmen.

### 13.1 Standardwerkzeug „Auswählen"

Beim Öffnen des Annotationseditors gilt:

- Ein Bild **mit vorhandenen Elementen** startet im Werkzeug „Auswählen",
- ein frisches Bild wie bisher im Werkzeug „Freihand".

Begründung: Auf einem bereits beschrifteten Bild ist Verschieben der häufigste
Folgeschritt, auf einem leeren Zeichnen. Umgesetzt in `annoBegin()` als
einzeilige Zuweisung `this.annoTool = this.annoItems.length > 0 ? 'select' : 'pen'`.

### 13.2 Antippen ist noch keine Änderung

Vorher legte `annoBeginSelect()` schon beim Antippen einen History-Eintrag an
und setzte `annoDirty` - ein bloßer Klick zum Betrachten löste beim Schließen
die Rückfrage „Änderungen verwerfen?" aus.

Jetzt trägt jeder Zug (Verschieben wie Skalieren) ein `moved`-Kennzeichen;
erst der erste `pointermove` mit tatsächlicher Bewegung schreibt History und
Dirty-Flag (`annoBeginDragHistory()`). Ein Griff, der nur angetippt und
losgelassen wird, ist ebenso wenig eine Änderung wie ein Klick nebenbei.

### 13.3 Hover-Rahmen

Im Auswählen-Werkzeug zeigt ein dezenter gestrichelter Rahmen das Element
unter dem Zeiger - das, was der nächste Klick wählen würde:

- `annoUpdateHover()` läuft in `annoPointerMove()`, wenn gerade kein Zug
  aktiv ist, und nutzt denselben Hit-Test wie die Auswahl (`annoHitTest`).
- Der Rahmen ist rein visuell (`pointer-events: none`, Klasse `anno-hover`),
  Zeichnen und Klicken laufen darüber hinweg.
- Die Bühne bekommt bei aktivem Hover die Klasse `anno-stage-hover`
  (`annoStageClass()`) mit dem `move`-Cursor.
- Auf Touchgeräten ohne Zeigerfreiheit entsteht der Effekt von selbst nicht,
  weil `pointermove` dort nur während einer Berührung feuert.

### 13.4 Pfeiltasten

Die Auswahl lässt sich mit den Pfeiltasten um **1 Einheit** des
Annotationsraums verschieben, mit Shift um **10** (`ARROW_NUDGES` in
`annoKeydown()`). Zwei Festlegungen:

1. **Tastatur der Bedienelemente bleibt unangetastet.** Ist der Fokus auf
   einem Regler oder Textfeld (`tagName` `INPUT`/`TEXTAREA`), fängt der
   Annotationseditor die Taste nicht ab - sonst ließe sich der
   Strichstärke-Regler nicht mehr mit der Tastatur bedienen.
2. **Zusammenhängende Nudges bilden einen Undo-Schritt.** `annoPushHistory()`
   schließt eine offene Nudge-Folge (`annoNudgeOpen = false`), der
   Tastendruck öffnet sie nur, wenn keine offen ist. Zehnmal Pfeiltaste
   bleibt so ein einzelner Undo-Schritt; jede andere Aktion (Zeichnen,
   Löschen, Eigenschaftsänderung, nächster Zug) trennt die Folge.

### 13.5 Ziehgriffe für jeden Typ

| Typ | Griffe | Wirkung |
|---|---|---|
| `rect`, `ellipse`, `mask`, `rules` | NW + SE | SE ändert `rw`/`rh` (wie Ausbaustufe 1); NW zieht `x`/`y` mit, während die rechte untere Ecke stehen bleibt |
| `line` | zwei Endpunkt-Griffe `p1`/`p2` | jeder Griff bewegt genau seinen Endpunkt; die Rahmenecken entfallen |
| `pen` | SE | alle Punkte werden proportional in den neuen Kasten abgebildet (`annoScalePen`) |
| `text` | SE | die Schriftgröße folgt der Breitenänderung (6–500), `bw`/`bh` werden mit `measureTextBox` neu gemessen (`annoScaleText`) |
| `marker` | SE | der Radius ist der Abstand der Griffposition zum Mittelpunkt, 4–2000 (`annoScaleMarker`) |

Umsetzung: `annoResizeHandle(item, handle, point)` ersetzt das alte
`annoResize()`. Der Griff wird am Drag-Objekt mitgeführt (`drag.handle`), die
Position der Linien-Endpunkte liefert `annoHandleStyle()` in Prozent der
Bühne - sie liegen nicht an den Ecken des Auswahlrahmens. `annoHasHandle()`
entscheidet je Typ, welche Griffe das Markup zeigt, und ersetzt das alte
`annoCanResizeSelection()`.

Grenzen (alle Werte bleiben auf eine Nachkommastelle gerundet): `rw`/`rh`
mindestens 2 wie bisher, Schriftgröße 6–500, Radius 4–2000. Die Grenzen sind
reine Bedienungsgrenzen; die Server-Validierung kennt sie nicht und braucht
sie nicht zu kennen.

### 13.6 Dateiübersicht

| Datei | Änderung |
|---|---|
| `resources/js/editor/annotations/annotator.js` | Standardwerkzeug, `moved`-Kennzeichen, Hover, Pfeiltasten mit Nudge-History, `annoResizeHandle` + drei Typ-Skalierer, `annoHasHandle`/`annoHandleStyle`/`annoBoxStyle` |
| `resources/views/partials/image_annotation_dialog.php` | Hover-Rahmen-Span, vier Griff-Spans, `:class="annoStageClass()"` an der Bühne |
| `resources/css/app.css` | `.anno-hover`, `.anno-stage-hover`, Griff-Varianten `.anno-handle-se/-nw/-point` und `.anno-handles` |
| `tests/Frontend/annotations.test.js` | fünf neue Tests: Standardwerkzeug, Antippen-ohne-Zug, Pfeiltasten samt Undo, Hover, Griffe je Typ |

Keine PHP-Datei, keine Migration, kein Endpunkt, kein Renderer.

### 13.7 Umsetzungsreihenfolge und Abnahme

| # | Schritt | Abnahme |
|---|---|---|
| 1 | `moved`-Kennzeichen (13.2) | Klick auf ein Element, sofort loslassen, Dialog schließen: keine Rückfrage „verwerfen?", `npm test` grün |
| 2 | Standardwerkzeug (13.1) | Bild mit Annotationen öffnen startet mit „Auswählen" aktiv |
| 3 | Hover (13.3) | Im Auswählen-Werkzeug folgt der Rahmen dem Zeiger, Cursor wird `move`; in Zeichenwerkzeugen nicht |
| 4 | Pfeiltasten (13.4) | Auswahl wandert, Shift ×10, zehn Tastendrücke = ein Undo-Schritt; Fokus auf dem Strichstärke-Regler bleibt bedienbar |
| 5 | Griffe (13.5) | je Typ ein Griff ziehen und prüfen, dass nur die erwarteten Felder wandern; Text behält einen passenden Hintergrundkasten |

Manuelle Prüfmatrix:

| # | Fall | Erwartung |
|---|---|---|
| 1 | Bild mit zwei Pfeilen öffnen | Werkzeug „Auswählen" ist aktiv, Pfeil antippen wählt aus |
| 2 | Auswahl mit Pfeiltasten verschieben | springt in Einheiten, Shift in Zehnern |
| 3 | Auswahl ziehen statt klicken | genau ein Undo-Schritt, Undo stellt die alte Position her |
| 4 | Linie an beiden Endpunkten ziehen | nur der jeweilige Endpunkt wandert |
| 5 | Freihandstrich am SE-Griff ziehen | Strich behält seine Form im neuen Kasten |
| 6 | Textkasten vergrößern | Schrift wächst mit, Hintergrundkasten umschließt weiterhin den Text |
| 7 | Nummerierten Marker skalieren | Kreis wächst gleichmäßig um den Mittelpunkt |

---

## 14. Ausbaustufe 3: Maßband

| Feld | Wert |
|---|---|
| Planungsstand | umgesetzt |
| Datum | 2026-08-31 |
| Bezug | neu: FR-ANNO-13 |
| Neue Abhängigkeiten | keine |
| Neue Migration | keine |
| Neue Endpunkte | keine |
| Vertragsänderungen | keine |

### 14.1 Was das Werkzeug tut

Ein Maßband wird wie eine Linie gezogen: Zeiger nieder am Anfang, Zeiger hoch
am Ende. Danach fragt ein kleiner Dialog nach der **Länge**. Der Eintrag ist
freier Text — „3,20 m", „45 cm", „ca. 2 Ziegel" — und wird über der Maßlinie
angezeigt.

**Es wird nichts gerechnet.** Das Werkzeug kennt keinen Maßstab und rechnet
keine Bildpunkte in Meter um. Ein Foto trägt die dafür nötige Information
nicht: Ohne Brennweite, Aufnahmeabstand und Lage der Ebene wäre jede
umgerechnete Zahl geraten, und eine geratene Zahl in Metern ist schlimmer als
gar keine. Der Nutzer weiß, was er gemessen hat, und trägt es ein.

### 14.2 Darstellung

Gezeichnet werden drei Striche und eine Beschriftung:

1. die Maßlinie von `(x1,y1)` nach `(x2,y2)`,
2. je ein Endstrich quer dazu, `max(w × 3, 10)` lang, mittig auf dem Endpunkt,
3. die Länge über der Mitte der Linie, in einer eigenen `<g>`-Gruppe, die um
   die Linienmitte gedreht wird.

Die Gruppe ist der Grund, warum die Beschriftung keine eigenen Koordinaten
braucht: Zieht der Nutzer einen Endpunkt, wandert und dreht sie von selbst
mit; im Modell ändern sich nur `x2`/`y2`.

Der Drehwinkel wird auf ±90° zurückgeklappt (`dimLabelAngle()` in `render.js`,
`ImageAnnotationSvgRenderer::labelAngle()`), damit die Zahl nie auf dem Kopf
steht. Ob das Band von links nach rechts oder von rechts nach links gezogen
wurde, ist damit unerheblich.

Die Textmaße `bw`/`bh` stammen wie beim Textkasten aus der Messung beim
Eintragen (`measureTextBox`); der Server kennt weiterhin keine Schriftmetrik.

**Rundung.** Die gedrehte Beschriftung rechnet laufend in negativen
Koordinaten, und dort liefen die beiden Renderer auseinander:
`Math.round(-70.25 × 10)` ergibt in JavaScript `-702` (halbe Werte nach oben),
`round(-70.25, 1)` in PHP `-70.3` (halbe Werte von der Null weg).
`ImageAnnotationSvgRenderer::num()` rundet deshalb mit
`floor($wert × 10 + 0.5) / 10` und macht aus `-0.0` eine `0.0`. Damit stimmen
die Zwillinge auch für negative Zahlen überein.

### 14.3 Die Länge ist freiwillig

`text` darf am `dim` fehlen. Das ist keine Nachlässigkeit, sondern der
Bedienablauf: Zwischen „Linie gezogen" und „Länge eingetragen" liegt ein
Dialog, und in dieser Zeit wird das Band bereits gezeichnet. Ohne die
Freiwilligkeit müsste die Vorschau mit einem Platzhaltertext arbeiten, den
niemand sehen will.

Daraus folgen zwei Regeln:

- **Neu gezogen, Dialog abgebrochen** → das Band wird verworfen. Ein Zug ohne
  Länge war fast immer ein Verrutscher.
- **Vorhandenes Band, Länge geleert** → nur die Beschriftung fällt weg, die
  Maßlinie bleibt stehen. Das ist die einzige Möglichkeit, eine Länge wieder
  loszuwerden, ohne das Band neu zu ziehen.

Bis zur Bestätigung liegt das frisch gezogene Band in der Closure-Variablen
`pendingMeasure`, nicht in `annoItems`: So entsteht kein Undo-Schritt für
etwas, das der Nutzer noch abbrechen kann, und `annoDirty` bleibt sauber.

### 14.4 Auswahl, Verschieben, Griffe

Das Maßband verhält sich wie die Linie: zwei Endpunkt-Griffe `p1`/`p2`, kein
Rahmengriff. In `annotator.js` steht dafür jetzt `ENDPOINT_TYPES`
(`line`, `dim`) und `BOX_TYPES` (`rect`, `ellipse`, `mask`, `rules`) statt der
zuvor an fünf Stellen ausgeschriebenen Typlisten.

Der Auswahlrahmen umfasst zusätzlich die Beschriftung: `annoBounds()` polstert
das Band um `w + s × 1.6` — knapp mehr als Zeilenhöhe plus Abstand. Ohne diese
Polsterung ließe sich ein Band, dessen Länge weit über der Linie steht, nur an
der Linie selbst greifen.

Der Strichstärke-Regler wirkt auf `w`, also auf Linie und Endstriche. Die
Schriftgröße der Länge steht fest bei `MEASURE_LABEL_SIZE = 40` — dieselbe
Festlegung wie beim Marker-Radius und beim Zeilenabstand, die ebenfalls
unabhängig vom Regler sind.

### 14.5 Suche und Export

`annotationTexts()` und `ImageAnnotationValidator::texts()` liefern die Länge
mit aus. Eine Notiz mit einem Maßband „3,20 m" ist damit über die
Volltextsuche auffindbar (FR-ANNO-10), und der Markdown-Export listet die
Länge unter dem Bild (FR-ANNO-11) — beides ohne weitere Änderung an den
aufrufenden Stellen.

### 14.6 Dateiübersicht

| Datei | Änderung |
|---|---|
| `resources/js/editor/annotations/schema.js` | Typ `dim`, `MAX_LABEL_LENGTH`, `normalizeLabel()`, `spec.label`, `annotationTexts()` nimmt `dim` mit |
| `resources/js/editor/annotations/render.js` | `dimMarkup()`, `dimLabelAngle()` |
| `resources/js/editor/annotations/annotator.js` | Werkzeug `measure`, `pendingMeasure`, Längen-Dialog (`annoOpenLengthDialog`/`annoConfirmLength`/`annoCancelLength`), `ENDPOINT_TYPES`/`BOX_TYPES`, `annoBounds`/`annoIsMeaningful`/`annoExtendItem` um `dim` erweitert |
| `resources/js/icons.js` | Symbol `ruler` |
| `resources/views/partials/image_annotation_dialog.php` | Knopf „Maßband", Dialog „Länge des Maßbands" |
| `app/Domain/Notes/ImageAnnotationValidator.php` | Typ `dim`, `MAX_LABEL_LENGTH`, `validateLabel()`, `texts()` nimmt `dim` mit |
| `app/Domain/Notes/ImageAnnotationSvgRenderer.php` | `dim()`, `labelAngle()`, `num()` rundet wie JavaScript |
| `tests/Frontend/annotations.test.js` | drei neue Tests: Modell und Beschriftung, Markup und Drehwinkel, Bedienablauf vom Zug bis zur Länge |
| `tests/Unit/Domain/Notes/ImageAnnotationValidatorTest.php` | Band ohne Länge, leere/mehrzeilige/zu lange Länge, fehlende Schriftgröße, `texts()` |
| `tests/Unit/Domain/Notes/ImageAnnotationSvgRendererTest.php` | Markup mit und ohne Länge, Drehwinkel, Rundung negativer Halbwerte |

Keine Migration, kein Endpunkt, keine CSS-Änderung — der Längen-Dialog benutzt
dieselben Klassen wie der Text-Dialog.

### 14.7 Manuelle Prüfmatrix

| # | Fall | Erwartung |
|---|---|---|
| 1 | Band von links nach rechts ziehen, „3,20 m" eintragen | Maßlinie mit zwei Endstrichen, Länge waagerecht darüber |
| 2 | Band von rechts nach links ziehen | Länge steht aufrecht, nicht auf dem Kopf |
| 3 | Band senkrecht ziehen | Länge steht mit der Linie gedreht, lesbar von unten nach oben |
| 4 | Dialog mit „Abbrechen" schließen | kein Band im Bild, keine Rückfrage beim Schließen des Editors |
| 5 | Endpunkt eines fertigen Bands ziehen | Länge wandert und dreht sich mit |
| 6 | Band doppelt antippen, Länge ändern | neue Länge, Hintergrundkasten passt sich an, ein Undo-Schritt |
| 7 | Band doppelt antippen, Länge leeren | Maßlinie bleibt, Beschriftung weg |
| 8 | Notiz speichern, neu laden, öffentlich freigeben | Overlay im Editor, im Lesemodus und in der Freigabe identisch |
| 9 | Notiz mit Maßband exportieren | Länge steht unter dem Bild im Markdown |
| 10 | Volltextsuche nach „3,20" | Notiz wird gefunden |

---

## 15. Mobile Ansicht

| Feld | Wert |
|---|---|
| Planungsstand | teils umgesetzt, teils offen |
| Datum | 2026-08-31 |
| Bezug | FR-ANNO-07/09 |

### 15.1 Behoben: Die Bühne konnte auf dem Handy verschwinden

Seit Abschnitt 13 bekommt `.anno-stage` ihre Maße ausschließlich aus
`annoFitStage()`; die früheren CSS-Angaben (`width: min(100%, 1400px)`,
`max-height: 100%`) sind entfallen, weil sie hochkantige Bilder verzerrt haben.
Damit hängt aber alles an einer Rechnung, die einen Grenzfall hatte:

```
availableHeight = viewport.clientHeight - Innenabstand
```

`.anno-viewport` ist ein `flex-1`-Element mit `overflow-auto`. Beides zusammen
heißt: `flex-basis: 0` und - weil die automatische Mindesthöhe für
Scroll-Container zu `0` auflöst - es darf **bis auf null** zusammengedrückt
werden. Auf Handybreite bricht die Werkzeugleiste (13 Werkzeuge, 8 Farbfelder,
Farbwähler, zwei Regler, vier Aktionsknöpfe) auf vier bis fünf Zeilen um und
nimmt zusammen mit der Kopfzeile im ungünstigen Fall die ganze Höhe.
`availableHeight` wird dann negativ, `Math.max(1, …)` macht daraus **eine Bühne
von 1 × 1 Pixel** - Werkzeugleiste sichtbar, Bild weg. Erholen konnte sich das
nicht: Nachgemessen wurde nur beim `resize`-Ereignis des Fensters.

Fünf Änderungen, die zusammen dafür sorgen, dass es keinen Weg mehr zu einer
unsichtbaren Bühne gibt:

1. **Untergrenze `MIN_STAGE = 160`** in `annoFitStage()` für beide verfügbaren
   Maße. Der Sichtbereich scrollt ohnehin; ein Überstand ist das kleinere Übel
   als ein unsichtbares Bild.
2. **CSS-Notgröße an `.anno-stage`**: `aspect-ratio: var(--anno-ratio, 3 / 2)`
   und `width: min(100%, 1400px)`. Die Inline-Maße aus `annoFitStage()`
   schlagen beides, sobald gemessen werden konnte. Kann nicht gemessen werden,
   hat die Bühne wenigstens eine Größe - ohne diese Regel hätte sie gar keine,
   denn alle ihre Kinder sind absolut positioniert. `aspect-ratio` **ohne**
   `max-height` verzerrt nichts (das war der Fehler der alten Regel); ein zu
   hohes Bild scrollt stattdessen.
3. **`annoFitStage()` ist jetzt total**: Es schreibt das Seitenverhältnis als
   `--anno-ratio` an die Bühne, *bevor* es misst, und räumt bei fehlendem
   Sichtbereich die Inline-Maße weg, statt wortlos auszusteigen und eine Bühne
   ohne Größe zurückzulassen.
4. **`ResizeObserver` auf dem Sichtbereich** zusätzlich zum `resize`-Ereignis.
   Der Sichtbereich ändert seine Höhe auch ohne Fenstergrößenänderung: Die
   Werkzeugleiste bricht je nach Breite anders um, und die Hinweiszeile
   (Werkzeug „Abdecken") sowie die Fehlerzeile kommen und gehen. Eine
   Rückkopplung entsteht nicht, weil `annoFitStage()` die Bühne in den
   Sichtbereich einpasst, statt ihn zu vergrößern.
5. **Deckel auf der Werkzeugleiste**: `.anno-toolbar { max-height: 40vh;
   overflow-y: auto }` unter 768 px. Damit bleiben dem Bild immer rund 60 % der
   Höhe, und die überzähligen Werkzeugzeilen scrollen innerhalb der Leiste.

Dazu kommt `align-items: safe center` / `justify-content: safe center` am
Sichtbereich: Ein Element, das größer ist als sein zentrierender
Flex-Container, ragt sonst oben und links heraus, und dieser Überstand lässt
sich nicht scrollen. Genau das wäre der Fall, sobald die Bühne auf ihrer
Notgröße steht.

Abgesichert durch den Test „die Bühne fällt nie unter eine sichtbare Größe
zusammen" in `tests/Frontend/annotations.test.js`.

### 15.2 Offen: Der Vollbild-Betrachter zeigt keine Annotationen

Auf Handybreite öffnet jeder Tipp auf ein Bild den Vollbild-Betrachter
(`imageAtEvent()` in `notePage.js`, Markup in `page_note.php`). Der zeigt
ausschließlich das `<img>` - ohne Overlay. FR-ANNO-07 nennt den Bildbetrachter
aber ausdrücklich, und auf dem Handy ist er die Hauptansicht für ein Bild.

Der Weg dorthin ist kurz, aber nicht trivial: Das `<img>` im Betrachter trägt
`object-contain` und eine Zoom-/Verschiebe-Transformation. Ein Overlay muss auf
dem **gerenderten** Kasten des Bildes liegen, nicht auf dem Elementkasten, und
dieselbe Transformation mitmachen. Dafür braucht der Betrachter eine
schrumpfende Hülle (`inline-block`, `max-width`/`max-height: 100%`), die
Transformation wandert von `<img>` auf die Hülle, und das Overlay wird als
absolut positionierte Ebene daneben gehängt. Die Annotationen selbst stehen
bereits am `<img>` in der Notiz (`data-annotations`) und lassen sich von dort
lesen.

### 15.3 Offen: Text und Länge lassen sich mit dem Finger nur über Doppeltipp ändern

`annoEditSelectedText()` hängt an `@dblclick` der Bühne. Auf der Bühne ist
`touch-action: none` gesetzt, ein Doppeltipp erzeugt dort also tatsächlich ein
`dblclick` - verlässlich ist die Geste mit dem Finger trotzdem nicht. Ein
sichtbarer Knopf „Bearbeiten" in der Werkzeugleiste, aktiv sobald ein Text oder
ein Maßband ausgewählt ist, wäre die eindeutigere Bedienung.
