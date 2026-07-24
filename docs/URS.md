# User Requirements Specification (URS)
## WYSIWYG-Notizen- & Task-Management-Anwendung

| Feld | Wert |
|---|---|
| Dokument-ID | URS-NOTES-001 |
| Version | **2.0** |
| Status | Entwurf |
| Datum | 2026-07-24 |
| Technologie | PHP 8.5, SQLite 3 (WAL + FTS5), Vite 7 + Alpine.js 3 + TipTap 3 |

---

## 1. Zweck und Geltungsbereich

### 1.1 Zweck
Dieses Dokument beschreibt die Anforderungen an eine webbasierte Anwendung zur Erstellung, Verwaltung und Freigabe von WYSIWYG-Notizseiten und Task-Listen. Es dient als verbindliche Grundlage für Entwicklung, Test und Abnahme.

### 1.2 Geltungsbereich
Single-Server-Webanwendung auf PHP-Basis mit SQLite als Datenhaltung. Zielgruppe: kleine bis mittlere Teams (Richtwert: bis 200 aktive Nutzer, bis 50.000 Objekte).

### 1.3 Abgrenzung (Out of Scope, Version 1.0)
- Echtzeit-Kollaboration (gleichzeitiges Editieren, OT/CRDT)
- Native Mobile Apps
- Offline-Modus / PWA-Synchronisierung
- Volltextsuche über den Inhalt von Dateianhängen
- Team-Workspaces mit gemeinsamer Mitgliederverwaltung
- Kommentarfunktion auf Seiten
- Wiederkehrende Tasks, Automatisierungen, Zeiterfassung

---

## 2. Definitionen

| Begriff | Bedeutung |
|---|---|
| **Workspace** | Persönlicher Arbeitsbereich eines Nutzers, enthält alle eigenen Seiten. |
| **Seite** | Oberbegriff für ein Inhaltsobjekt. Zwei Typen: Notizseite und Task-Seite. |
| **Notizseite** | Seite mit WYSIWYG-Rich-Text-Inhalt. |
| **Task-Seite** | Seite mit Kapiteln (Kategorien) und den jeweils darunterstehenden Tasks. |
| **Kategorie** | Kapitel/Gruppierung innerhalb einer Task-Seite. |
| **Task** | Aufgabe mit Titel, Beschreibung, Verantwortlichem und Link. |
| **Share-Link** | Tokengeschützte URL zu einer Seite, auch ohne Anmeldung nutzbar. |
| **Invite-Link** | Registrierungslink für neue Nutzer. |
| **Admin** | Nutzer, dessen E-Mail in `ADMIN_EMAILS` hinterlegt ist. |
| **Bulk-Import** | Anlage mehrerer Tasks aus einem mehrzeiligen Textfeld. |
| **FTS5** | Volltextsuch-Modul von SQLite. |

---

## 3. Technologie-Stack

Verbindliche Vorgaben. Versionsstände Stand Juli 2026; bei Projektstart ist jeweils die aktuelle stabile Minor-Version zu verwenden.

### 3.1 Backend

| Komponente | Version | Begründung |
|---|---|---|
| **PHP** | **8.5.x** (min. 8.4) | <cite index="17-1">PHP 8.5 ist ein Major-Update mit URI-Extension, Pipe-Operator und Clone-with-Syntax.</cite> <cite index="13-1">PHP 8.3 ist seit November 2025 außerhalb des aktiven Supports, PHP 8.2 ist End-of-Life.</cite> Die native URI-Extension wird für die Validierung von Task-Links genutzt. |
| **SQLite** | 3.45+ | WAL-Modus, FTS5, `VACUUM INTO`, `RETURNING`, JSON-Funktionen. |
| **Slim Framework** | 4.x (aktuell) | Schlankes PSR-7/PSR-15-Routing ohne Overhead eines Full-Stack-Frameworks. Alternative: Symfony 7.x, falls mehr Struktur gewünscht. |
| **PHP-DI** | 7.x | Dependency Injection Container. |
| **league/oauth2-client** + `league/oauth2-google` | aktuell | Google OIDC-Flow inkl. PKCE. Alternativ `google/apiclient`. |
| **firebase/php-jwt** | 6.x | Verifikation des Google ID-Tokens. |
| **HTML Purifier** oder **`symfony/html-sanitizer`** | aktuell | Serverseitige HTML-Sanitisierung. `symfony/html-sanitizer` wird bevorzugt (moderner, W3C-Sanitizer-API-orientiert). |
| **Monolog** | 3.x | Strukturiertes Logging (JSON-Formatter). |
| **phinx** oder eigenes Migrationsskript | aktuell | Versionierte DB-Migrationen. |
| **vlucas/phpdotenv** | 5.x | `.env`-Handling im Development. |

*Bewusste Entscheidung gegen Laravel:* Für eine SQLite-Single-Server-App mit überschaubarem Funktionsumfang ist der Overhead nicht gerechtfertigt. Falls das Team Laravel-Erfahrung hat, ist **Laravel 12.x** eine zulässige Alternative — dann inklusive Eloquent, Laravel Socialite (Google-Login) und Laravel Scout.

### 3.2 Frontend

| Komponente | Version | Zweck |
|---|---|---|
| **TipTap** | **3.x** | WYSIWYG-Editor. <cite index="2-1">TipTap 3.0 ist seit Juli 2025 stabil</cite> und liegt aktuell bei Version 3.28. <cite index="1-1">Headless-Wrapper um ProseMirror, im Einsatz u. a. bei New York Times, The Guardian und Atlassian.</cite> Speicherformat: ProseMirror-JSON. <cite index="2-1">Die 3.x-Linie unterstützt Markdown-Import/-Export über `renderMarkdown()` / `parseMarkdown()`.</cite> |
| **Alpine.js** | **3.15.x** | Leichtgewichtige Reaktivität ohne Build-Komplexität eines SPA-Frameworks. |
| **Vite** | 7.x | Build-Tool, Dev-Server mit HMR, ES-Module-Output. |
| **Tailwind CSS** | **4.x** | Utility-First-Styling. Version 4 nutzt CSS-first-Konfiguration (`@theme`) und benötigt keine `tailwind.config.js` mehr. |
| **Lucide** | aktuell | Icon-Set (MIT, konsistent, SVG). |
| **Inter** oder **Geist** | aktuell | Variable Font, selbst gehostet (kein Google-Fonts-CDN → DSGVO). |

**Alternativen (zulässig, falls begründet):**
- Statt Alpine.js: **htmx 2.x** (noch serverzentrierter) oder **Svelte 5** (bei komplexerem Listen-State).
- Statt TipTap: **Lexical** (Meta) oder **Editor.js** — beide haben jedoch ein schwächeres Tabellen-/Markdown-Ökosystem.

### 3.3 Tooling & Qualitätssicherung

| Werkzeug | Version | Zweck |
|---|---|---|
| **Composer** | 2.8+ | PHP-Abhängigkeiten |
| **PHPUnit** oder **Pest** | 11.x / 3.x | Unit- und Integrationstests |
| **PHPStan** | 2.x, Level 8 | Statische Analyse |
| **PHP-CS-Fixer** oder **Laravel Pint** | aktuell | PSR-12-Formatierung |
| **Playwright** | aktuell | End-to-End-Tests |
| **Node.js** | 22 LTS oder 24 LTS | Build-Umgebung |
| **GitHub Actions** o. ä. | — | CI: Lint, PHPStan, Tests, Build |

### 3.4 Anforderungen an Abhängigkeiten

| ID | Anforderung | Prio |
|---|---|---|
| NFR-DEP-01 | Alle Abhängigkeiten werden über Composer bzw. npm mit Lockfile verwaltet. Keine manuell kopierten Bibliotheken im Repository. | M |
| NFR-DEP-02 | Keine CDN-Einbindung von Skripten oder Schriften im Produktivbetrieb — alle Assets werden selbst gehostet (CSP, Datenschutz, Verfügbarkeit). | M |
| NFR-DEP-03 | Alle verwendeten Bibliotheken müssen eine OSI-konforme, permissive Lizenz besitzen (MIT, BSD, Apache-2.0). Kein Einsatz kostenpflichtiger TipTap-Pro-Extensions in Version 1.0. | M |
| NFR-DEP-04 | Automatisierte Abhängigkeitsprüfung (Dependabot/Renovate) sowie `composer audit` und `npm audit` in der CI-Pipeline. | S |
| NFR-DEP-05 | Es werden ausschließlich Versionen im aktiven Support verwendet; End-of-Life-Versionen sind unzulässig. | M |

---

## 4. Nutzerrollen

| Rolle | Rechte |
|---|---|
| **Gast (anonym)** | Aufruf gültiger Share-Links (lesend, bzw. schreibend falls so konfiguriert) |
| **Nutzer** | Volle CRUD-Rechte im eigenen Workspace, Share-Links erzeugen/widerrufen, Export |
| **Admin** | Zusätzlich: Invites verwalten, Nutzerliste, Nutzer deaktivieren, Systemstatus, Audit-Log |

**Regel:** Die Admin-Rolle wird ausschließlich über `ADMIN_EMAILS` bestimmt und ist nicht in der Datenbank änderbar. Die Prüfung erfolgt bei jedem Request (Case-insensitiver Vergleich der getrimmten E-Mail).

---

## 5. Funktionale Anforderungen

Priorisierung: **M** = Muss, **S** = Soll, **K** = Kann.

### 5.1 Authentifizierung & Registrierung

| ID | Anforderung | Prio |
|---|---|---|
| FR-AUTH-01 | Anmeldung ausschließlich über Google (OAuth 2.0 / OIDC Authorization Code Flow mit PKCE). Keine lokale Passwortanmeldung. | M |
| FR-AUTH-02 | Aus dem ID-Token werden `sub`, `email`, `email_verified`, `name`, `picture` übernommen. Login wird abgelehnt, wenn `email_verified` false ist. Signatur, `iss`, `aud`, `exp` und `nonce` werden geprüft. | M |
| FR-AUTH-03 | Existiert ein Nutzer mit der Google-`sub`, wird dieser angemeldet. Ändert sich die E-Mail bei Google, wird sie aktualisiert (Schlüssel bleibt `sub`). | M |
| FR-AUTH-04 | Existiert kein Nutzer, wird die Anmeldung abgelehnt — außer die Session enthält ein gültiges, nicht verbrauchtes, nicht abgelaufenes Invite-Token. | M |
| FR-AUTH-05 | Ist die E-Mail in `ADMIN_EMAILS` enthalten, wird der Nutzer auch ohne Invite angelegt (Bootstrap des ersten Admins). | M |
| FR-AUTH-06 | Ablauf: Invite-Link aufrufen → Token in Session → Google-Login → Kontoerstellung → Token als verbraucht markieren (transaktional). | M |
| FR-AUTH-07 | Sessions laufen nach `SESSION_LIFETIME_DAYS` Inaktivität ab; „Abmelden" invalidiert die Session serverseitig. | M |
| FR-AUTH-08 | Der `state`-Parameter wird kryptografisch zufällig erzeugt und serverseitig gegen CSRF geprüft. | M |
| FR-AUTH-09 | Deaktivierte Nutzer können sich nicht anmelden; bestehende Sessions werden ungültig. | S |
| FR-AUTH-10 | Optional: Beschränkung auf eine Google-Workspace-Domain über `GOOGLE_HOSTED_DOMAIN` (`hd`-Claim wird serverseitig geprüft). | S |
| FR-AUTH-11 | Der Nutzer sieht seine aktiven Sessions und kann sie einzeln oder alle beenden. | K |

### 5.2 Invite-Verwaltung (Admin)

| ID | Anforderung | Prio |
|---|---|---|
| FR-INV-01 | Der Admin erzeugt Invite-Links mit: Token (≥ 32 Byte Entropie), optionaler Ziel-E-Mail, Ablaufdatum, maximaler Nutzungsanzahl, optionaler Notiz. | M |
| FR-INV-02 | Liste aller Invites mit Status (offen / verbraucht / abgelaufen / widerrufen), Ersteller und Erstellungsdatum. | M |
| FR-INV-03 | Invites können jederzeit widerrufen werden. | M |
| FR-INV-04 | Ist eine Ziel-E-Mail hinterlegt, muss die Google-E-Mail exakt übereinstimmen. | S |
| FR-INV-05 | Standard-Gültigkeit: `INVITE_TTL_DAYS` (Default 7 Tage). | S |
| FR-INV-06 | Der Admin kann Nutzer auflisten, deaktivieren und reaktivieren. Der eigene Account kann nicht deaktiviert werden. | S |
| FR-INV-07 | Beim Löschen eines Nutzers wählt der Admin: Inhalte mitlöschen oder auf einen anderen Nutzer übertragen. | S |
| FR-INV-08 | Der Admin sieht das Audit-Log gefiltert nach Nutzer, Aktion und Zeitraum. | S |

### 5.3 Workspace & Seitenverwaltung

| ID | Anforderung | Prio |
|---|---|---|
| FR-WS-01 | Jeder Nutzer besitzt genau einen Workspace, automatisch bei Kontoerstellung angelegt. | M |
| FR-WS-02 | Beliebig viele Seiten pro Workspace. **Keine Hierarchie** — flache Liste. | M |
| FR-WS-03 | Beim Anlegen wird der Typ gewählt: **Notizseite** oder **Task-Seite**. Der Typ ist danach nicht änderbar. | M |
| FR-WS-04 | Seiten haben Titel (Pflicht, max. 200 Zeichen), optionales Emoji-Icon, Erstell- und Änderungsdatum. | M |
| FR-WS-05 | Seiten können umbenannt, **dupliziert** (inkl. aller Kategorien und Tasks bzw. des Notizinhalts, ohne Share-Links) und gelöscht werden. | M |
| FR-WS-06 | Löschen erfolgt als Soft-Delete in einen **Papierkorb**. Wiederherstellung innerhalb von `TRASH_RETENTION_DAYS` (Default 30). Endgültiges Löschen manuell oder automatisch nach Ablauf. | M |
| FR-WS-07 | Die Seitenliste ist sortierbar (zuletzt geändert / Titel A-Z / Erstellungsdatum) und filterbar nach Typ. | S |
| FR-WS-08 | Seiten können als Favorit markiert werden; Favoriten erscheinen oben in der Seitenleiste. | S |
| FR-WS-09 | Manuelle Sortierung der Seiten per Drag & Drop. | K |
| FR-WS-10 | Seiten können mit farbigen **Tags/Labels** versehen und danach gefiltert werden. | K |

### 5.4 Notizseiten (WYSIWYG)

| ID | Anforderung | Prio |
|---|---|---|
| FR-NOTE-01 | Editor-Funktionsumfang mindestens: Überschriften H1–H3, Fett, Kursiv, Durchgestrichen, Inline-Code, Codeblock (mit Syntax-Highlighting), ungeordnete/geordnete Liste, Checkliste, Zitat, Trennlinie, Link, Tabelle. | M |
| FR-NOTE-02 | Der Inhalt wird als **ProseMirror-JSON** in `note_contents.content` gespeichert. Zusätzlich wird eine Reintext-Repräsentation für die Volltextsuche abgelegt. | M |
| FR-NOTE-03 | Serverseitige Validierung des JSON gegen das erlaubte Schema (Allowlist von Node- und Mark-Typen). Bei HTML-Rendering erfolgt zusätzlich Sanitisierung. Rohes HTML aus dem Client wird niemals ungeprüft gespeichert oder ausgeliefert. | M |
| FR-NOTE-04 | Autosave mit Debounce (1–2 s nach letzter Eingabe) sowie bei Verlassen der Seite (`visibilitychange` / `beforeunload`). Sichtbarer Statusindikator: „Speichern…" / „Gespeichert" / „Nicht gespeichert". | M |
| FR-NOTE-05 | Bei Netzwerkfehler wird der Inhalt lokal zwischengespeichert und der Speichervorgang mit exponentiellem Backoff wiederholt. Der Nutzer wird sichtbar gewarnt. | M |
| FR-NOTE-06 | Tastaturkürzel: Strg/Cmd+B, +I, +U, +K (Link), +S (manuell speichern), +Z/+Shift+Z (Undo/Redo). | S |
| FR-NOTE-07 | Slash-Befehl (`/`) öffnet ein Blockauswahl-Menü an der Cursorposition. | S |
| FR-NOTE-08 | Markdown-Eingabekürzel beim Tippen (`# `, `- `, `> `, `` ``` ``). | S |
| FR-NOTE-09 | Versionsverlauf mit bis zu 20 Snapshots pro Seite, Wiederherstellung möglich. Snapshot bei jeder Sitzung bzw. maximal alle 10 Minuten. | S |
| FR-NOTE-10 | Bild-Upload per Drag & Drop / Paste, lokale Ablage außerhalb des Web-Roots, Auslieferung über authentifizierten Endpunkt. Grenze über `MAX_UPLOAD_MB`. | K |
| FR-NOTE-11 | Import einer Markdown-Datei als neue Notizseite. | K |

### 5.5 Task-Seiten

| ID | Anforderung | Prio |
|---|---|---|
| FR-TASK-01 | Eine Task-Seite besteht aus geordneten **Kapiteln** (Kategorien). Tasks werden untereinander innerhalb ihres Kapitels angezeigt. | M |
| FR-TASK-02 | Kapitel können angelegt, umbenannt, umsortiert und gelöscht werden. Beim Löschen eines Kapitels mit Tasks bestätigt der Nutzer, dass alle enthaltenen Tasks mitgelöscht werden. | M |
| FR-TASK-03 | Kapitel-Feld: Name (Pflicht, max. 100 Zeichen). | M |
| FR-TASK-04 | Task-Felder: **Titel** (Pflicht, max. 200 Zeichen), **Beschreibung** (optional, Rich-Text), **Responsible** (Freitext, max. 100 Zeichen), **Link** (optional, URL, nur `http`/`https`). | M |
| FR-TASK-05 | Weitere Felder: Position, Erstell-/Änderungsdatum, Erledigt-Flag. | M |
| FR-TASK-06 | Ein leeres Eingabefeld steht unter jedem Kapitel. Nach Eingabe eines Namens und Drücken von Enter wird ein Task angelegt und das Feld bleibt für den nächsten Task fokussiert. | M |
| FR-TASK-07 | Tasks stehen als vertikale Liste unter ihrem Kapitel. | M |
| FR-TASK-08 | Tasks anlegen, bearbeiten (Detail-Panel/Modal) und löschen. | M |
| FR-TASK-09 | Reihenfolge wird persistiert und nach Neuladen identisch dargestellt. Positionen werden serverseitig normalisiert. | M |
| FR-TASK-10 | Die Task-Seite verwendet eine nach Kapiteln gruppierte Listenansicht. | S |
| FR-TASK-11 | Filter nach Verantwortlichem und Erledigt-Status sowie Textsuche innerhalb der Seite. | S |
| FR-TASK-12 | Jedes Kapitel zeigt die Anzahl enthaltener Tasks. | S |
| FR-TASK-13 | Mehrfachauswahl von Tasks für Sammelaktionen (verschieben, löschen, Responsible setzen). | S |
| FR-TASK-14 | Optionale Felder: Fälligkeitsdatum und Priorität (niedrig/mittel/hoch), mit visueller Hervorhebung überfälliger Tasks. | K |
| FR-TASK-15 | Task-Vorlagen: ein Kapitel kann als Vorlage markiert werden, dessen Tasks per Klick in eine andere Seite kopiert werden. | K |

### 5.6 Bulk-Import von Tasks *(neu in v2.0)*

| ID | Anforderung | Prio |
|---|---|---|
| FR-IMP-01 | Auf jeder Task-Seite existiert eine Funktion **„Tasks importieren"**, erreichbar über die Kategorie-Kopfzeile und das Seitenmenü. | M |
| FR-IMP-02 | Der Import erfolgt über ein mehrzeiliges Textfeld (Textarea). **Je Zeile wird genau ein Task angelegt**, wobei der Zeileninhalt als Task-Titel dient. | M |
| FR-IMP-03 | Der Nutzer wählt vor dem Import die **Zielkategorie** aus einer Auswahlliste. Vorbelegt ist die Kategorie, aus der der Dialog geöffnet wurde. | M |
| FR-IMP-04 | Leerzeilen werden übersprungen. Führende und nachfolgende Leerzeichen werden entfernt. Zeilenenden `\n`, `\r\n` und `\r` werden gleichermaßen akzeptiert. | M |
| FR-IMP-05 | Führende Listenmarkierungen werden automatisch entfernt: `- `, `* `, `• `, `+ `, `1. `, `1) `, sowie Checkbox-Präfixe `[ ] ` und `[x] `. Bei `[x] ` wird der Task direkt als erledigt markiert. | M |
| FR-IMP-06 | **Erweitertes Format (optional):** Zusätzliche Felder können in derselben Zeile per Trennzeichen angegeben werden. Standard-Trennzeichen ist das Pipe-Symbol `\|` in der Reihenfolge:<br>`Titel \| Responsible \| Link \| Beschreibung`<br>Nicht angegebene Felder bleiben leer. Enthält eine Zeile kein Trennzeichen, wird sie vollständig als Titel interpretiert. | M |
| FR-IMP-07 | Das Trennzeichen ist im Dialog umstellbar (Pipe `\|`, Semikolon `;`, Tabulator). Der Tabulator ermöglicht direktes Einfügen aus Excel/Google Sheets. | S |
| FR-IMP-08 | **Inline-Direktiven (optional):** Ein `@name` am Zeilenende setzt den Responsible, eine erkannte URL wird als Link übernommen und aus dem Titel entfernt. Diese Erkennung ist per Checkbox im Dialog aktivierbar (Default: aktiv). | S |
| FR-IMP-09 | **Vorschau vor dem Import:** Der Dialog zeigt eine Tabelle der zu erzeugenden Tasks mit erkannten Feldern sowie die Anzahl (z. B. „12 Tasks werden in ‚Offen' angelegt"). Der Import wird erst nach Bestätigung ausgeführt. | M |
| FR-IMP-10 | Fehlerhafte Zeilen (z. B. Titel > 200 Zeichen, ungültige URL) werden in der Vorschau markiert. Der Nutzer kann wählen: fehlerhafte Zeilen überspringen oder den Import abbrechen. Ungültige URLs führen nicht zum Abbruch, sondern werden verworfen und gemeldet. | M |
| FR-IMP-11 | Der Import erfolgt in **einer Datenbanktransaktion**. Bei einem Fehler wird vollständig zurückgerollt; es entstehen keine Teilimporte. | M |
| FR-IMP-12 | Importierte Tasks werden **am Ende** der Zielkategorie in der Reihenfolge der Eingabezeilen angehängt. Alternativ ist „am Anfang einfügen" wählbar. | M |
| FR-IMP-13 | Maximal `IMPORT_MAX_LINES` Zeilen pro Vorgang (Default 500). Bei Überschreitung erfolgt eine klare Meldung mit der tatsächlichen Zeilenanzahl. | M |
| FR-IMP-14 | Nach dem Import erscheint eine Bestätigungsmeldung mit Anzahl der angelegten und übersprungenen Tasks sowie einer **„Rückgängig"-Aktion**, die den gesamten Import innerhalb der Sitzung entfernt. | S |
| FR-IMP-15 | Duplikatserkennung: Existiert in der Zielkategorie bereits ein Task mit identischem Titel, wird dies in der Vorschau markiert. Der Nutzer wählt: trotzdem anlegen oder überspringen. | S |
| FR-IMP-16 | Der Import ist auch über einen **Write-Share-Link** möglich, sofern die Freigabe Bearbeitungsrechte gewährt. | S |
| FR-IMP-17 | Der Import-Dialog enthält einen kurzen Hilfetext mit Formatbeispiel. | M |
| FR-IMP-18 | Der Import ist auch beim **Anlegen einer neuen Kategorie** direkt verfügbar (Kategorie + Tasks in einem Schritt). | K |
| FR-IMP-19 | Import einer CSV-Datei mit Spaltenzuordnung. | K |

**Formatbeispiel für den Hilfetext:**

```
Angebot für Kunde Meier erstellen
Rechnung prüfen | Anna | https://crm.example.com/inv/42
- Website-Texte überarbeiten | Ben
[x] Kickoff-Termin vereinbart
Serverumzug planen | Chris | | Migration inkl. DNS-Umstellung
```

Ergebnis: 5 Tasks; der dritte ohne Listenmarkierung, der vierte direkt als erledigt, der fünfte mit leerem Link und gefüllter Beschreibung.

### 5.7 Freigabe per Link (Sharing)

| ID | Anforderung | Prio |
|---|---|---|
| FR-SHR-01 | Notiz- und Task-Seiten können per Link freigegeben werden. | M |
| FR-SHR-02 | Ein Share-Link enthält ein zufälliges, URL-sicheres Token (≥ 32 Byte Entropie), nicht die interne Seiten-ID. Format: `/s/{token}`. | M |
| FR-SHR-03 | Berechtigungsstufen: **Nur Lesen** oder **Bearbeiten**. | M |
| FR-SHR-04 | Share-Links können widerrufen und neu erzeugt werden (Rotation). Nach Widerruf liefert der Link HTTP 404. | M |
| FR-SHR-05 | Optionale Einschränkungen: Ablaufdatum, Passwortschutz, „nur angemeldete Nutzer". | S |
| FR-SHR-06 | Geteilte Seiten sind ohne Anmeldung aufrufbar, sofern nicht auf angemeldete Nutzer beschränkt. | M |
| FR-SHR-07 | Geteilte Seiten werden mit `X-Robots-Tag: noindex, nofollow` und `<meta name="robots">` ausgeliefert. | M |
| FR-SHR-08 | Mit Bearbeiten-Freigabe kann der Gast Inhalte ändern und Tasks importieren, aber **nicht**: Seite löschen, Freigaben verwalten, Seitentyp ändern, Seite duplizieren, Versionsverlauf wiederherstellen. | M |
| FR-SHR-09 | Der Besitzer sieht pro Seite alle aktiven Links mit Berechtigungsstufe, Erstellungsdatum, Ablauf und letztem Zugriff. | S |
| FR-SHR-10 | „Link kopieren"-Button mit visueller Bestätigung. | M |
| FR-SHR-11 | Beim ersten Zugriff über einen Write-Link wird ein Anzeigename abgefragt (Session-gebunden), der bei Änderungen im Audit-Log vermerkt wird. | K |
| FR-SHR-12 | Eine Übersichtsseite listet alle vom Nutzer erzeugten Share-Links workspace-weit auf. | S |

### 5.8 Export *(neu in v2.0)*

| ID | Anforderung | Prio |
|---|---|---|
| FR-EXP-01 | Eine einzelne Notizseite kann als **Markdown** exportiert werden. | S |
| FR-EXP-02 | Eine einzelne Task-Seite kann als **CSV** exportiert werden (Spalten: Kategorie, Titel, Beschreibung, Responsible, Link, Erledigt, Position). | S |
| FR-EXP-03 | Der gesamte Workspace kann als ZIP (Markdown + CSV + JSON-Rohdaten) exportiert werden. | K |
| FR-EXP-04 | Druckoptimiertes Stylesheet für Notiz- und Task-Seiten (Print-CSS). | K |

---

## 6. Volltextsuche *(neu in v2.0)*

### 6.1 Funktionale Anforderungen

| ID | Anforderung | Prio |
|---|---|---|
| FR-SRCH-01 | Eine globale Volltextsuche durchsucht innerhalb des eigenen Workspaces: Seitentitel, Notizinhalte (Reintext), Kategorienamen, Task-Titel und Task-Beschreibungen. | M |
| FR-SRCH-02 | Die Suche wird über ein Suchfeld in der Seitenleiste sowie per Tastaturkürzel **Strg/Cmd+K** geöffnet. | M |
| FR-SRCH-03 | Ergebnisse erscheinen **während der Eingabe** (Debounce 150–250 ms) ab dem zweiten Zeichen. | M |
| FR-SRCH-04 | Jedes Ergebnis zeigt: Objekttyp-Icon (Notiz/Task), Seitentitel, bei Tasks zusätzlich die Kategorie, sowie einen **Textausschnitt mit hervorgehobenen Treffern** (Snippet). | M |
| FR-SRCH-05 | Klick auf ein Ergebnis öffnet die Seite; bei einem Task wird zur Kategorie gescrollt und der Task kurz visuell hervorgehoben. | M |
| FR-SRCH-06 | Die Ergebnisliste ist per Pfeiltasten navigierbar, Enter öffnet, Escape schließt. | M |
| FR-SRCH-07 | Ergebnisse sind nach Relevanz sortiert (BM25). Treffer im Titel werden höher gewichtet als Treffer im Fließtext. | M |
| FR-SRCH-08 | Ergebnisse sind nach Typ gruppiert (Seiten / Tasks) und auf 10 pro Gruppe begrenzt; eine Detailansicht „Alle Ergebnisse" zeigt mehr mit Paginierung. | S |
| FR-SRCH-09 | **Präfixsuche** wird unterstützt: die Eingabe „proj" findet „Projekt". | M |
| FR-SRCH-10 | Suche mit mehreren Begriffen verknüpft diese per UND. Phrasensuche mit `"…"` wird unterstützt. | S |
| FR-SRCH-11 | Filter in der erweiterten Suche: Typ (Notiz/Task), Kategorie, Responsible, Zeitraum (geändert seit), Erledigt-Status. | S |
| FR-SRCH-12 | Die Suche ist **diakritik- und groß-/kleinschreibungsunabhängig**: „Grosse", „große" und „GROSSE" liefern dieselben Treffer. Deutsche Umlaute und `ß` werden korrekt normalisiert. | M |
| FR-SRCH-13 | Gelöschte Seiten (Papierkorb) erscheinen nicht in den Standardergebnissen; ein Filter „auch Papierkorb durchsuchen" ist verfügbar. | S |
| FR-SRCH-14 | Innerhalb einer geöffneten Seite gibt es eine lokale Suche (Strg/Cmd+F-Ersatz für Task-Seiten). | S |
| FR-SRCH-15 | Die Suche gibt bei fehlenden Treffern einen hilfreichen Leerzustand mit Suchtipps aus. | S |
| FR-SRCH-16 | Die zuletzt geöffneten Seiten werden bei leerem Suchfeld als Schnellzugriff angezeigt. | K |

### 6.2 Technische Umsetzung

| ID | Anforderung | Prio |
|---|---|---|
| NFR-SRCH-01 | Die Suche wird mit **SQLite FTS5** umgesetzt. Externe Suchdienste (Elasticsearch, Meilisearch) sind für Version 1.0 nicht zulässig. | M |
| NFR-SRCH-02 | Es wird eine FTS5-Virtual-Table als **External-Content-Tabelle** angelegt, um Daten nicht doppelt zu speichern. | M |
| NFR-SRCH-03 | Der Index wird über **SQLite-Trigger** (INSERT/UPDATE/DELETE) synchron gehalten. Ein CLI-Kommando `search:reindex` erlaubt den vollständigen Neuaufbau. | M |
| NFR-SRCH-04 | Tokenizer: `unicode61` mit `remove_diacritics 2`. Zusätzlich wird beim Schreiben eine normalisierte Fassung erzeugt, die `ä→ae`, `ö→oe`, `ü→ue`, `ß→ss` abbildet, damit „Muller" auch „Müller" findet. | M |
| NFR-SRCH-05 | Nutzereingaben werden **nicht** direkt als FTS5-MATCH-Ausdruck übergeben. Sonderzeichen der FTS5-Syntax werden escaped bzw. die Begriffe in Anführungszeichen gesetzt und mit `*` für die Präfixsuche versehen. | M |
| NFR-SRCH-06 | Snippets werden mit der FTS5-Funktion `snippet()` erzeugt, Ranking mit `bm25()` inkl. spaltenspezifischer Gewichtung (Titel höher als Inhalt). | M |
| NFR-SRCH-07 | Jede Suchanfrage wird **serverseitig** auf den Workspace des angemeldeten Nutzers eingeschränkt. Über Share-Links ist die globale Suche nicht verfügbar. | M |
| NFR-SRCH-08 | Antwortzeit der Suche < 150 ms im 95. Perzentil bei 50.000 indizierten Objekten. | S |
| NFR-SRCH-09 | Rate Limiting auf dem Suchendpunkt (z. B. 30 Anfragen/Minute/Nutzer). | S |
| NFR-SRCH-10 | Notizinhalte werden beim Speichern aus dem ProseMirror-JSON in Reintext überführt und in `content_text` abgelegt; nur dieses Feld wird indiziert. | M |

**Schema-Skizze:**

```sql
-- Reintext-Spiegel aller durchsuchbaren Objekte
CREATE TABLE search_documents (
  id            INTEGER PRIMARY KEY,
  workspace_id  INTEGER NOT NULL,
  object_type   TEXT NOT NULL,          -- 'page' | 'task'
  object_id     INTEGER NOT NULL,
  page_id       INTEGER NOT NULL,
  title         TEXT NOT NULL DEFAULT '',
  body          TEXT NOT NULL DEFAULT '',
  meta          TEXT NOT NULL DEFAULT '', -- Kategorie, Responsible
  is_deleted    INTEGER NOT NULL DEFAULT 0,
  updated_at    TEXT NOT NULL,
  UNIQUE (object_type, object_id)
);

CREATE VIRTUAL TABLE search_fts USING fts5(
  title, body, meta,
  content       = 'search_documents',
  content_rowid = 'id',
  tokenize      = "unicode61 remove_diacritics 2"
);

-- Trigger halten den Index synchron (INSERT / UPDATE / DELETE)
CREATE TRIGGER search_documents_ai AFTER INSERT ON search_documents BEGIN
  INSERT INTO search_fts(rowid, title, body, meta)
  VALUES (new.id, new.title, new.body, new.meta);
END;
-- analog: _ad (DELETE) und _au (UPDATE) mit 'delete'-Kommando

-- Abfrage
SELECT d.object_type, d.object_id, d.page_id,
       snippet(search_fts, 1, '<mark>', '</mark>', '…', 24) AS excerpt,
       bm25(search_fts, 10.0, 1.0, 3.0) AS rank
FROM search_fts
JOIN search_documents d ON d.id = search_fts.rowid
WHERE search_fts MATCH :query
  AND d.workspace_id = :workspace_id
  AND d.is_deleted = 0
ORDER BY rank
LIMIT 20;
```

---

## 7. Nicht-funktionale Anforderungen

### 7.1 Design und Benutzeroberfläche

| ID | Anforderung | Prio |
|---|---|---|
| NFR-UI-01 | Modernes, reduziertes Design (Notion-/Linear-Ästhetik): großzügiger Weißraum, klare Typografie-Hierarchie, dezente Schatten, abgerundete Ecken (8–12 px). | M |
| NFR-UI-02 | Konsistentes Design-Token-System über CSS Custom Properties bzw. Tailwind-`@theme`: Farben, Abstände (4-px-Raster), Radien, Schatten, Typografie. | M |
| NFR-UI-03 | **Dark Mode** und Light Mode; manuelle Umschaltung mit Fallback auf `prefers-color-scheme`, Persistenz pro Nutzer. | M |
| NFR-UI-04 | Layout: fixierte Seitenleiste (Suche, Seitenliste, Favoriten, Papierkorb, Nutzermenü), Hauptbereich rechts. Seitenleiste einklappbar. | M |
| NFR-UI-05 | Responsives Verhalten ab 360 px Viewport-Breite. Task-Kapitel und ihre Aufgaben bleiben vertikal lesbar. | M |
| NFR-UI-06 | Schrift: moderne Sans-Serif (Inter oder Geist), selbst gehostet als Variable Font. Monospace für Codeblöcke. | S |
| NFR-UI-07 | Mikro-Interaktionen: Hover-States, Übergänge 150–250 ms, Skeleton-Loader statt Spinner, Toast-Benachrichtigungen. | S |
| NFR-UI-08 | Die direkte Task-Eingabe bleibt nach Enter fokussiert, damit mehrere Aufgaben ohne Unterbrechung angelegt werden können. | M |
| NFR-UI-09 | Leere Zustände (keine Seiten, keine Tasks, keine Suchtreffer) mit Ikone und klarer Handlungsaufforderung. | S |
| NFR-UI-10 | Optimistische UI-Updates mit Rollback und Fehlermeldung bei Serverfehler. | S |
| NFR-UI-11 | **Command-Palette** (Strg/Cmd+K) für Suche, Seitenwechsel und Schnellaktionen („Neue Notiz", „Neue Task-Seite", „Tasks importieren"). | S |
| NFR-UI-12 | Globale Tastaturkürzel-Übersicht über `?`. | K |
| NFR-UI-13 | Kurzes Onboarding beim ersten Login: automatisch angelegte Beispiel-Notiz und Beispiel-Task-Seite. | K |

### 7.2 Barrierefreiheit

| ID | Anforderung | Prio |
|---|---|---|
| NFR-A11Y-01 | WCAG 2.2 Level AA für Kontraste (mind. 4,5:1 für Text, 3:1 für UI-Komponenten). | M |
| NFR-A11Y-02 | Vollständige Tastaturbedienbarkeit, sichtbarer Fokus-Indikator, logische Tab-Reihenfolge. | M |
| NFR-A11Y-03 | Semantisches HTML und ARIA für Modals (Focus-Trap), Menüs und Live-Regions für Toasts. | S |
| NFR-A11Y-04 | Respektierung von `prefers-reduced-motion`. | S |
| NFR-A11Y-05 | Sprachauszeichnung `lang="de"`, aussagekräftige Seitentitel, Skip-Link zum Hauptinhalt. | S |

### 7.3 Performance

| ID | Anforderung | Prio |
|---|---|---|
| NFR-PERF-01 | First Contentful Paint < 1,5 s bei 5 Mbit/s. | S |
| NFR-PERF-02 | API-Antwortzeiten < 200 ms im 95. Perzentil bei 50.000 Objekten. | S |
| NFR-PERF-03 | Task-Seiten mit bis zu 500 Tasks bleiben flüssig bedienbar. | S |
| NFR-PERF-04 | SQLite im **WAL-Modus**, `busy_timeout` ≥ 5000 ms, `foreign_keys = ON`, `synchronous = NORMAL`. | M |
| NFR-PERF-05 | Autosave gedrosselt; keine parallelen Speicheranfragen für dieselbe Seite. | S |
| NFR-PERF-06 | JS-Bundle (gzip) < 200 kB für die Hauptansicht; TipTap wird nur auf Notizseiten geladen (Code-Splitting). | S |
| NFR-PERF-07 | Statische Assets mit Content-Hash und langlebigen Cache-Headern. | S |

### 7.4 Sicherheit

| ID | Anforderung | Prio |
|---|---|---|
| NFR-SEC-01 | Alle Datenbankzugriffe über Prepared Statements (PDO). | M |
| NFR-SEC-02 | CSRF-Schutz für jeden zustandsändernden Request (Token + `SameSite` + Origin-Prüfung). | M |
| NFR-SEC-03 | Session-Cookies: `HttpOnly`, `Secure`, `SameSite=Lax`, ID-Rotation nach Login. | M |
| NFR-SEC-04 | Serverseitige Validierung aller Eingaben; kontextabhängiges Escaping beim Rendern; Rich-Text gegen Allowlist sanitisiert. | M |
| NFR-SEC-05 | Content-Security-Policy ohne `unsafe-inline` für Skripte (Nonce-basiert), `frame-ancestors 'none'`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, HSTS. | M |
| NFR-SEC-06 | Autorisierungsprüfung bei **jedem** Objektzugriff (Besitz oder gültiger Share-Token) — IDOR-Schutz. Keine Verlassen auf clientseitige Prüfungen. | M |
| NFR-SEC-07 | Rate Limiting auf: Login, Invite-Einlösung, Share-Link-Aufruf (inkl. Passwortversuchen), Suche, Bulk-Import, Autosave. | S |
| NFR-SEC-08 | Tokens mit `random_bytes()`; Vergleiche zeitkonstant (`hash_equals`). Share- und Invite-Tokens werden gehasht gespeichert. | M |
| NFR-SEC-09 | Die SQLite-Datei und Uploads liegen außerhalb des Web-Roots. | M |
| NFR-SEC-10 | Geheimnisse ausschließlich in Umgebungsvariablen, nie im Repository. `.env` steht in `.gitignore`. | M |
| NFR-SEC-11 | Audit-Log für: Login, Invite-Erstellung/-Einlösung, Share-Erstellung/-Widerruf, Löschungen, Bulk-Imports, Admin-Aktionen. | S |
| NFR-SEC-12 | Uploads werden per MIME-Sniffing und Extension-Allowlist geprüft; Auslieferung mit `Content-Disposition` und ohne Ausführungsrechte. | M |
| NFR-SEC-13 | Externe Links in Notizen und Tasks erhalten `rel="noopener noreferrer"` und `target="_blank"`. | M |
| NFR-SEC-14 | Kein `eval`-basiertes JS; Alpine.js wird im CSP-kompatiblen Build eingesetzt oder per Nonce freigegeben. | S |

### 7.5 Datenschutz

| ID | Anforderung | Prio |
|---|---|---|
| NFR-DSG-01 | Es werden nur betriebsnotwendige Google-Profildaten gespeichert (E-Mail, Name, Avatar-URL, `sub`). | M |
| NFR-DSG-02 | Nutzer können ihr Konto löschen; alle Seiten, Tasks, Share-Links und Uploads werden endgültig entfernt. | S |
| NFR-DSG-03 | Nutzer können ihre Daten als JSON/ZIP exportieren (Auskunftsrecht). | S |
| NFR-DSG-04 | Zugriffs-Logs zu Share-Links werden nach 90 Tagen automatisch gelöscht; IP-Adressen werden nur gehasht gespeichert. | S |
| NFR-DSG-05 | Impressum, Datenschutzerklärung und ein Hinweis auf die Google-Anmeldung sind vor dem Login erreichbar. | M |
| NFR-DSG-06 | Keine Drittanbieter-Tracker, keine externen CDNs, kein Analytics ohne gesonderte Einwilligung. | M |

### 7.6 Wartbarkeit und Betrieb

| ID | Anforderung | Prio |
|---|---|---|
| NFR-OPS-01 | Trennung von Routing, Controllern, Services, Repositories und Views. | M |
| NFR-OPS-02 | Versionierte Migrationen mit fortlaufender Nummerierung; Migrationsstand in einer Tabelle. Migrationen laufen beim Deployment automatisch. | M |
| NFR-OPS-03 | Konfiguration ausschließlich über Umgebungsvariablen; `.env.example` ist Teil des Repositories. Beim Start werden Pflichtvariablen validiert (Fail-Fast). | M |
| NFR-OPS-04 | Health-Check `/health` mit Status von Datenbank, Migrationsstand, Schreibbarkeit des Upload-Verzeichnisses und FTS-Index. | S |
| NFR-OPS-05 | Strukturiertes Logging (JSON) mit Request-ID; im Produktivbetrieb keine Stacktraces an den Client. | M |
| NFR-OPS-06 | Backup-Skript mit konsistenter SQLite-Sicherung (`VACUUM INTO`), Rotation und dokumentiertem Restore-Verfahren. Ein Restore-Test ist Teil der Abnahme. | M |
| NFR-OPS-07 | Code-Standard PSR-12; PHPStan Level 8; CI bricht bei Lint-, Analyse- oder Testfehlern ab. | S |
| NFR-OPS-08 | CLI-Kommandos: `migrate`, `search:reindex`, `trash:purge`, `backup:run`, `invite:create`, `user:list`. | S |
| NFR-OPS-09 | Ein Cron/Scheduler-Eintrag führt täglich aus: Papierkorb-Bereinigung, Log-Rotation, Ablauf abgelaufener Invites und Share-Links. | M |
| NFR-OPS-10 | `README.md` mit Setup-Anleitung (lokal + Produktion) und Beschreibung aller ENV-Variablen. | M |

### 7.7 Kompatibilität

| ID | Anforderung | Prio |
|---|---|---|
| NFR-COMP-01 | Browser: jeweils aktuelle und vorherige Version von Chrome, Firefox, Safari, Edge (Desktop und Mobil). | M |
| NFR-COMP-02 | Server: PHP 8.5 (min. 8.4) mit `pdo_sqlite`, `mbstring`, `json`, `curl`, `openssl`, `intl`, `fileinfo`. SQLite-Build mit FTS5-Unterstützung. | M |
| NFR-COMP-03 | Betrieb hinter Apache oder Nginx mit HTTPS; HTTP wird auf HTTPS umgeleitet. | M |
| NFR-COMP-04 | Keine JavaScript-freie Nutzbarkeit erforderlich; ohne JS erscheint ein verständlicher Hinweis. | S |

---

## 8. Datenmodell (logisch)

### 8.1 Übersicht

```
users ──1:1── workspaces ──1:n── pages
                                   ├── note_contents      (type = 'note')
                                   ├── note_versions      (Snapshots)
                                   ├── categories ──1:n── tasks   (type = 'task')
                                   └── share_links

search_documents ──1:1── search_fts   (FTS5, External Content)

users ──1:n── invites (als Ersteller)
users ──1:n── sessions
users ──1:n── audit_log
```

### 8.2 Tabellen

**users** — `id`, `google_sub` (UNIQUE), `email` (UNIQUE), `name`, `avatar_url`, `is_active`, `created_at`, `last_login_at`
*Kein `is_admin`-Feld — die Admin-Eigenschaft wird zur Laufzeit aus `ADMIN_EMAILS` abgeleitet.*

**workspaces** — `id`, `user_id` (FK, UNIQUE), `name`, `created_at`

**sessions** — `id`, `user_id` (FK), `token_hash`, `user_agent`, `ip_hash`, `created_at`, `last_seen_at`, `expires_at`

**pages** — `id`, `workspace_id` (FK), `type` (`note`\|`task`), `title`, `icon`, `is_favorite`, `sort_order`, `default_view` (`board`\|`list`), `deleted_at`, `created_at`, `updated_at`

**note_contents** — `page_id` (PK, FK), `content` (ProseMirror-JSON), `content_text` (Reintext für FTS), `updated_at`

**note_versions** — `id`, `page_id` (FK), `content`, `created_at`, `created_by`

**categories** — `id`, `page_id` (FK), `name`, `color`, `position`, `wip_limit`, `created_at`

**tasks** — `id`, `category_id` (FK), `title`, `description`, `responsible`, `link`, `position`, `is_done`, `due_date`, `priority`, `import_batch_id`, `created_at`, `updated_at`

**import_batches** *(neu)* — `id`, `page_id` (FK), `category_id` (FK), `created_by` (nullable bei Share-Zugriff), `line_count`, `created_count`, `skipped_count`, `created_at`
*Ermöglicht die „Rückgängig"-Funktion (FR-IMP-14) über `tasks.import_batch_id`.*

**share_links** — `id`, `page_id` (FK), `token_hash` (UNIQUE), `permission` (`read`\|`write`), `password_hash`, `requires_login`, `expires_at`, `revoked_at`, `last_accessed_at`, `access_count`, `created_at`

**invites** — `id`, `token_hash` (UNIQUE), `email`, `note`, `created_by` (FK), `max_uses`, `used_count`, `expires_at`, `revoked_at`, `created_at`

**search_documents** / **search_fts** — siehe Kapitel 6.2

**audit_log** — `id`, `user_id` (nullable), `action`, `object_type`, `object_id`, `ip_hash`, `metadata` (JSON), `created_at`

### 8.3 Indizes

- `pages(workspace_id, deleted_at, updated_at DESC)`
- `categories(page_id, position)`
- `tasks(category_id, position)`
- `tasks(import_batch_id)`
- `share_links(token_hash)` UNIQUE, `share_links(page_id)`
- `invites(token_hash)` UNIQUE
- `sessions(token_hash)` UNIQUE, `sessions(user_id)`
- `search_documents(workspace_id, is_deleted)`, `search_documents(object_type, object_id)` UNIQUE
- `audit_log(created_at)`, `audit_log(user_id, created_at)`

---

## 9. Konfiguration (Umgebungsvariablen)

| Variable | Beispiel | Beschreibung |
|---|---|---|
| `APP_ENV` | `production` | `development` \| `production` |
| `APP_URL` | `https://notes.example.com` | Basis-URL, u. a. für OAuth-Redirect |
| `APP_KEY` | `base64:…` | Schlüssel für Signaturen |
| `APP_DEBUG` | `false` | Im Produktivbetrieb zwingend `false` |
| `DB_PATH` | `/var/data/app.sqlite` | Pfad außerhalb des Web-Roots |
| `UPLOAD_PATH` | `/var/data/uploads` | Ablage für Bild-Uploads |
| `GOOGLE_CLIENT_ID` | `…apps.googleusercontent.com` | OAuth-Client-ID |
| `GOOGLE_CLIENT_SECRET` | `…` | OAuth-Client-Secret |
| `GOOGLE_REDIRECT_URI` | `https://notes.example.com/auth/callback` | In der Google Cloud Console hinterlegt |
| `GOOGLE_HOSTED_DOMAIN` | *(leer)* | Optionale Domain-Beschränkung (`hd`-Claim) |
| `ADMIN_EMAILS` | `chef@example.com,ops@example.com` | Kommaseparierte Admin-Adressen |
| `INVITE_TTL_DAYS` | `7` | Standard-Gültigkeit von Invites |
| `SESSION_LIFETIME_DAYS` | `30` | Session-Ablauf bei Inaktivität |
| `MAX_UPLOAD_MB` | `10` | Obergrenze für Uploads |
| `TRASH_RETENTION_DAYS` | `30` | Aufbewahrung im Papierkorb |
| `IMPORT_MAX_LINES` | `500` | Maximale Zeilenanzahl pro Bulk-Import |
| `SEARCH_RESULT_LIMIT` | `20` | Ergebnisse pro Suchanfrage |
| `RATE_LIMIT_ENABLED` | `true` | Rate Limiting aktivieren |
| `BACKUP_PATH` | `/var/backups/notes` | Zielverzeichnis für Sicherungen |
| `LOG_LEVEL` | `info` | Monolog-Level |

---

## 10. Schnittstellen (API-Skizze)

### 10.1 HTML-Routen

| Methode | Pfad | Beschreibung |
|---|---|---|
| GET | `/` | Login oder Weiterleitung in den Workspace |
| GET | `/auth/google` | Start des OAuth-Flows |
| GET | `/auth/callback` | OAuth-Rückkanal |
| POST | `/auth/logout` | Abmelden |
| GET | `/invite/{token}` | Invite annehmen → Session setzen → OAuth |
| GET | `/app` | Workspace-Oberfläche |
| GET | `/app/page/{id}` | Seitenansicht |
| GET | `/app/trash` | Papierkorb |
| GET | `/s/{token}` | Öffentlich geteilte Seite |
| POST | `/s/{token}/unlock` | Passwortgeschützte Freigabe entsperren |
| GET | `/admin` | Admin-Bereich |
| GET | `/health` | Health-Check |

### 10.2 API-Routen

| Methode | Pfad | Beschreibung |
|---|---|---|
| GET | `/api/pages` | Seiten des Workspaces |
| POST | `/api/pages` | Seite anlegen (`type`, `title`) |
| PATCH | `/api/pages/{id}` | Titel, Icon, Favorit, Sortierung, Ansicht |
| DELETE | `/api/pages/{id}` | Soft-Delete |
| POST | `/api/pages/{id}/restore` | Aus Papierkorb wiederherstellen |
| DELETE | `/api/pages/{id}/purge` | Endgültig löschen |
| POST | `/api/pages/{id}/duplicate` | Seite duplizieren |
| GET | `/api/pages/{id}/content` | Notizinhalt |
| PUT | `/api/pages/{id}/content` | Notizinhalt speichern (Autosave, mit `version` für Konflikterkennung) |
| GET | `/api/pages/{id}/versions` | Versionsliste |
| POST | `/api/pages/{id}/versions/{vid}/restore` | Version wiederherstellen |
| GET | `/api/pages/{id}/board` | Kapitel inkl. Tasks |
| POST | `/api/pages/{id}/categories` | Kapitel anlegen |
| PATCH | `/api/categories/{id}` | Name, Farbe, Position, WIP-Limit |
| DELETE | `/api/categories/{id}` | Mit `?move_to={id}` oder `?cascade=1` |
| POST | `/api/categories/{id}/tasks` | Task anlegen |
| **POST** | **`/api/categories/{id}/tasks/import`** | **Bulk-Import (Body: `text`, `delimiter`, `position`, `detect_inline`, `skip_duplicates`)** |
| **POST** | **`/api/categories/{id}/tasks/import/preview`** | **Vorschau ohne Speicherung** |
| **DELETE** | **`/api/imports/{batch_id}`** | **Import rückgängig machen** |
| PATCH | `/api/tasks/{id}` | Felder ändern |
| POST | `/api/tasks/{id}/move` | `{ category_id, position }` |
| POST | `/api/tasks/bulk` | Sammelaktion (`ids`, `action`, `payload`) |
| DELETE | `/api/tasks/{id}` | Task löschen |
| GET | `/api/pages/{id}/shares` | Share-Links der Seite |
| POST | `/api/pages/{id}/shares` | Share-Link erzeugen |
| PATCH | `/api/shares/{id}` | Berechtigung, Ablauf, Passwort ändern |
| DELETE | `/api/shares/{id}` | Share-Link widerrufen |
| **GET** | **`/api/search?q=&type=&limit=&offset=`** | **Volltextsuche** |
| GET | `/api/export/pages/{id}?format=md\|csv` | Seiten-Export |
| GET | `/api/export/workspace` | Workspace-Export (ZIP) |
| GET | `/api/admin/invites` | Invites auflisten (Admin) |
| POST | `/api/admin/invites` | Invite erzeugen (Admin) |
| DELETE | `/api/admin/invites/{id}` | Invite widerrufen (Admin) |
| GET | `/api/admin/users` | Nutzer auflisten (Admin) |
| PATCH | `/api/admin/users/{id}` | Aktivieren/Deaktivieren (Admin) |
| GET | `/api/admin/audit` | Audit-Log (Admin) |

### 10.3 Beispiel: Bulk-Import

**Request** `POST /api/categories/12/tasks/import`
```json
{
  "text": "Angebot erstellen\nRechnung prüfen | Anna | https://crm.example.com/inv/42\n[x] Kickoff vereinbart",
  "delimiter": "|",
  "position": "append",
  "detect_inline": true,
  "skip_duplicates": false
}
```

**Response** `201 Created`
```json
{
  "batch_id": 87,
  "created": 3,
  "skipped": 0,
  "warnings": [],
  "tasks": [
    { "id": 501, "title": "Angebot erstellen", "responsible": null, "link": null, "is_done": false, "position": 4 },
    { "id": 502, "title": "Rechnung prüfen", "responsible": "Anna", "link": "https://crm.example.com/inv/42", "is_done": false, "position": 5 },
    { "id": 503, "title": "Kickoff vereinbart", "responsible": null, "link": null, "is_done": true, "position": 6 }
  ]
}
```

**Response bei Teilfehlern** `422 Unprocessable Entity`
```json
{
  "error": {
    "code": "IMPORT_VALIDATION_FAILED",
    "message": "2 Zeilen konnten nicht verarbeitet werden.",
    "details": [
      { "line": 4, "reason": "TITLE_TOO_LONG", "message": "Titel überschreitet 200 Zeichen." },
      { "line": 7, "reason": "INVALID_URL", "message": "Nur http- und https-Links sind erlaubt." }
    ]
  }
}
```

### 10.4 Fehlerformat und Statuscodes

```json
{ "error": { "code": "FORBIDDEN", "message": "Kein Zugriff auf diese Seite." } }
```

200 OK · 201 Created · 204 No Content · 400 Bad Request · 401 Unauthorized · 403 Forbidden · 404 Not Found · **409 Conflict** (Versionskonflikt beim Autosave) · 422 Unprocessable Entity · 429 Too Many Requests · 500 Internal Server Error.

**Konfliktbehandlung:** Beim Speichern von Notizinhalten wird eine Versionsnummer mitgesendet. Weicht sie vom Serverstand ab, antwortet der Server mit 409 und dem aktuellen Inhalt. Der Client zeigt einen Hinweis und bietet an, die eigene Fassung als neue Version zu speichern.

---

## 11. Validierungsregeln

| Feld | Regel |
|---|---|
| Seitentitel | 1–200 Zeichen, nach Trim nicht leer |
| Seiten-Icon | max. 8 Zeichen (Emoji) |
| Kategoriename | 1–100 Zeichen |
| Task-Titel | 1–200 Zeichen |
| Task-Beschreibung | max. 10.000 Zeichen |
| Responsible | max. 100 Zeichen |
| Task-Link | gültige URL (Prüfung über die PHP-8.5-URI-Extension), nur `http`/`https`, max. 2048 Zeichen |
| Notizinhalt | max. 1 MB pro Seite, gültiges ProseMirror-JSON gegen Schema-Allowlist |
| Import-Text | max. `IMPORT_MAX_LINES` Zeilen, max. 500 kB Gesamtgröße |
| Import-Trennzeichen | genau eines aus `\|`, `;`, `\t` |
| Share-Passwort | min. 8 Zeichen, `password_hash()` mit Argon2id |
| Suchanfrage | 2–100 Zeichen, FTS5-Sonderzeichen werden escaped |
| Positionen | ganzzahlig ≥ 0, serverseitig normalisiert |
| E-Mail (Invite) | RFC-konform, max. 254 Zeichen |

---

## 12. Akzeptanzkriterien

### 12.1 Authentifizierung und Rollen

| ID | Kriterium |
|---|---|
| AK-01 | Ein Nutzer ohne gültigen Invite wird mit verständlicher Meldung abgewiesen; es entsteht kein Datenbankeintrag. |
| AK-02 | Ein Nutzer mit gültigem Invite erhält automatisch einen Workspace; der Invite ist danach verbraucht. |
| AK-03 | Eine E-Mail aus `ADMIN_EMAILS` erhält Zugriff auf `/admin`; alle anderen erhalten HTTP 403. |
| AK-04 | Wird eine E-Mail aus `ADMIN_EMAILS` entfernt, verliert der Nutzer beim nächsten Request die Adminrechte. |
| AK-05 | Ein widerrufener oder abgelaufener Invite-Link führt zu einer verständlichen Fehlerseite, nicht zu einem Serverfehler. |

### 12.2 Notizen

| ID | Kriterium |
|---|---|
| AK-06 | Eine Notizseite behält nach Neuladen alle Formatierungen unverändert bei. |
| AK-07 | Der Autosave speichert innerhalb von 2 s nach der letzten Eingabe; der Statusindikator wechselt sichtbar auf „Gespeichert". |
| AK-08 | Bei unterbrochener Verbindung erscheint eine Warnung; nach Wiederherstellung wird der Inhalt automatisch gespeichert, ohne Datenverlust. |
| AK-09 | Ein `<script>`-Tag im Notizinhalt wird weder gespeichert noch ausgeführt. |

### 12.3 Tasks und Import

| ID | Kriterium |
|---|---|
| AK-10 | Nach Eingabe eines Task-Namens und Drücken von Enter erscheint der Task im gewählten Kapitel; das Eingabefeld bleibt fokussiert. |
| AK-11 | Mehrere Tasks können nacheinander durch Eingabe und Enter im selben Kapitel angelegt werden. |
| AK-12 | Beim Löschen eines Kapitels mit Tasks erscheint eine Rückfrage; nach Bestätigung werden Kapitel und enthaltene Tasks gelöscht. |
| AK-13 | Ein Task mit dem Link `javascript:alert(1)` wird mit HTTP 422 abgewiesen. |
| **AK-14** | **Ein Textfeld mit 10 Zeilen erzeugt genau 10 Tasks in der gewählten Kategorie, in der Reihenfolge der Eingabe.** |
| **AK-15** | **Leerzeilen und reine Leerzeichen-Zeilen erzeugen keine Tasks.** |
| **AK-16** | **Die Zeile `- Rechnung prüfen \| Anna \| https://example.com` erzeugt einen Task mit Titel „Rechnung prüfen", Responsible „Anna" und dem Link; die Listenmarkierung erscheint nicht im Titel.** |
| **AK-17** | **Die Zeile `[x] Kickoff` erzeugt einen Task, der direkt als erledigt markiert ist.** |
| **AK-18** | **Die Vorschau zeigt die korrekte Anzahl und die erkannten Felder, bevor gespeichert wird.** |
| **AK-19** | **Enthält eine Zeile einen ungültigen Link, wird dies in der Vorschau markiert; der Import der übrigen Zeilen ist weiterhin möglich.** |
| **AK-20** | **Bricht der Import serverseitig ab, existieren anschließend keine teilweise angelegten Tasks (Transaktionsintegrität).** |
| **AK-21** | **Ein Import mit mehr als `IMPORT_MAX_LINES` Zeilen wird mit einer klaren Meldung abgelehnt.** |
| **AK-22** | **„Rückgängig" entfernt genau die im letzten Import angelegten Tasks und keine anderen.** |
| **AK-23** | **Aus Excel kopierte Zeilen (Tabulator-getrennt) werden mit der Trennzeichen-Einstellung „Tabulator" korrekt in Titel und Zusatzfelder aufgeteilt.** |

### 12.4 Suche

| ID | Kriterium |
|---|---|
| **AK-24** | **Die Eingabe eines Begriffs liefert innerhalb von 300 ms Ergebnisse aus Notizen und Tasks.** |
| **AK-25** | **Die Eingabe „proj" findet eine Notiz mit dem Wort „Projektplan" (Präfixsuche).** |
| **AK-26** | **Die Eingabe „muller" findet einen Task mit dem Responsible „Müller".** |
| **AK-27** | **Suchergebnisse zeigen einen Textausschnitt mit hervorgehobenem Suchbegriff.** |
| **AK-28** | **Ein Klick auf ein Task-Ergebnis öffnet die richtige Seite und hebt den Task sichtbar hervor.** |
| **AK-29** | **Die Suche liefert ausschließlich Objekte des eigenen Workspaces; ein manipulierter `workspace_id`-Parameter ändert daran nichts.** |
| **AK-30** | **Eine Suche mit FTS5-Sonderzeichen (z. B. `"` oder `*` oder `NEAR`) führt nicht zu einem Serverfehler.** |
| **AK-31** | **Nach dem Umbenennen einer Seite ist sie unmittelbar unter dem neuen Titel auffindbar und nicht mehr unter dem alten.** |
| **AK-32** | **Nach Ausführen von `search:reindex` sind alle Objekte auffindbar und es entstehen keine Duplikate.** |
| **AK-33** | **Gelöschte (Papierkorb-)Seiten erscheinen standardmäßig nicht in den Suchergebnissen.** |

### 12.5 Freigabe

| ID | Kriterium |
|---|---|
| AK-34 | Ein Read-Share-Link zeigt die Seite ohne Anmeldung; Bearbeiten-Elemente fehlen und schreibende API-Requests liefern HTTP 403. |
| AK-35 | Nach Widerruf eines Share-Links liefert derselbe Link HTTP 404. |
| AK-36 | Ein Write-Share-Link erlaubt Inhaltsänderungen und Task-Import, verweigert aber das Löschen der Seite. |
| AK-37 | Der direkte Aufruf einer fremden Seiten-ID liefert HTTP 404 oder 403, niemals Inhalte. |
| AK-38 | Ein passwortgeschützter Share-Link ist erst nach korrekter Passworteingabe zugänglich; nach 5 Fehlversuchen greift ein Rate Limit. |

### 12.6 Qualität und Betrieb

| ID | Kriterium |
|---|---|
| AK-39 | Die Anwendung ist auf einem Viewport von 375 px Breite vollständig bedienbar, inklusive Task-Erfassung und Import. |
| AK-40 | Dark und Light Mode erfüllen in allen Ansichten die Kontrastanforderung 4,5:1. |
| AK-41 | Eine gelöschte Seite ist im Papierkorb sichtbar und innerhalb der Aufbewahrungsfrist wiederherstellbar. |
| AK-42 | Eine Task-Seite mit 500 Tasks über 5 Kategorien lädt in unter 2 Sekunden. |
| AK-43 | Ein Backup lässt sich einspielen und die Anwendung ist danach vollständig funktionsfähig (dokumentierter Restore-Test). |
| AK-44 | PHPStan Level 8 und alle automatisierten Tests laufen in der CI ohne Fehler durch. |
| AK-45 | Die CSP ist im Produktivbetrieb aktiv; die Browser-Konsole zeigt keine CSP-Verstöße. |
| AK-46 | Alle eingesetzten Abhängigkeiten befinden sich in einer Version mit aktivem Support (Nachweis über `composer outdated` und `npm outdated`). |

---

## 13. Lieferumfang

| Nr. | Artefakt |
|---|---|
| 1 | Quellcode im Git-Repository inkl. `README.md` und `.env.example` |
| 2 | Datenbank-Migrationen und Seed-Daten für die Entwicklungsumgebung |
| 3 | Automatisierte Tests (Unit, Integration, E2E) mit CI-Konfiguration |
| 4 | Deployment-Anleitung inkl. Nginx-/Apache-Beispielkonfiguration |
| 5 | Backup- und Restore-Anleitung |
| 6 | Anleitung zur Einrichtung des Google-OAuth-Clients in der Google Cloud Console |
| 7 | Kurze Nutzerdokumentation (Seiten anlegen, Tasks importieren, Teilen, Suchen) |

---

## 14. Annahmen und offene Punkte

**Annahmen**
1. „Responsible" ist ein Freitextfeld und referenziert keinen registrierten Nutzer.
2. Freigegebene Seiten erscheinen nicht in der Seitenliste des Empfängers; der Zugriff erfolgt ausschließlich über den Link.
3. Es gibt keine gemeinsamen Team-Workspaces; jede Seite hat genau einen Besitzer.
4. Der Betrieb erfolgt auf einem einzelnen Server; SQLite ist damit ausreichend.
5. Gleichzeitiges Bearbeiten derselben Seite ist selten; die Konfliktbehandlung per Versionsnummer genügt.
6. Beim Bulk-Import ist der Zeileninhalt vollständig maßgeblich; es findet keine semantische Interpretation über die beschriebenen Regeln hinaus statt.

**Offene Punkte**
| Nr. | Frage | Auswirkung |
|---|---|---|
| OP-1 | Sollen Bild-Uploads in Version 1.0 enthalten sein? | Speicherbedarf, Sicherheitsprüfungen, Backup-Größe |
| OP-2 | Werden Invites per E-Mail versendet oder genügt das Kopieren des Links? | SMTP-Konfiguration, zusätzliche Abhängigkeit |
| OP-3 | Ist eine Mehrsprachigkeit der Oberfläche (DE/EN) erforderlich? | i18n-Infrastruktur von Beginn an |
| OP-4 | Soll das erweiterte Import-Format (Pipe-Syntax) sichtbar dokumentiert oder nur als „Power-Feature" angeboten werden? | Umfang des Hilfetexts, Nutzerdokumentation |
| OP-5 | Ist mittelfristig eine Migration auf MySQL/PostgreSQL zu erwarten? | Abstraktionsgrad der Datenschicht; FTS5 wäre dann durch `tsvector`/Fulltext zu ersetzen |
| OP-6 | Sind Team-Workspaces für Version 2.0 geplant? | Datenmodell sollte `workspace_id` bereits mehrbenutzerfähig auslegen |
| OP-7 | Welche Aufbewahrungsfrist gilt für das Audit-Log? | DSGVO-Löschkonzept |

---
cl
## 15. Änderungshistorie

| Version | Datum | Änderung |
|---|---|---|
| 1.0 | 2026-07-24 | Erstfassung |
| 2.0 | 2026-07-24 | Technologie-Stack mit aktuellen Versionen (Kap. 3); Volltextsuche als eigenes Kapitel inkl. FTS5-Schema (Kap. 6); Task-Bulk-Import per Textfeld (Kap. 5.6, API 10.3, AK-14 bis AK-23); ergänzt: Papierkorb, Duplizieren, Export, Versionskonflikte, Sessions-Tabelle, Rate-Limiting, Backup/Restore, Lieferumfang |
