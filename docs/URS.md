# User Requirements Specification (URS)
## WYSIWYG-Notizen- & Task-Management-Anwendung

| Feld | Wert |
|---|---|
| Dokument-ID | URS-NOTES-001 |
| Version | **3.5** |
| Status | Entwurf |
| Datum | 2026-07-30 |
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
- Vollständiger Offline-First-Betrieb ohne vorherigen Online-Prefetch; optionale Offline-Nutzung mit lokalem Cache ist in FR-OFFLINE-* beschrieben
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
| **Nutzer** | Volle CRUD-Rechte im eigenen Workspace, Share-Links erzeugen/widerrufen, Export, eigene Invite-Links erzeugen und widerrufen |
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

### 5.2 Invite-Verwaltung

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
| FR-INV-09 | **Jeder angemeldete Nutzer** kann Invite-Links erzeugen — mit denselben Feldern wie der Admin (optionale Ziel-E-Mail, Notiz, Nutzungsanzahl, Gültigkeitsdauer). Der Link wird ausschließlich unmittelbar nach dem Anlegen angezeigt; serverseitig liegt nur der Token-Hash. | M |
| FR-INV-10 | Nutzer sehen und widerrufen ausschließlich die von ihnen selbst erzeugten Invites; der Admin sieht und widerruft alle. Ein Widerruf-Versuch auf einen fremden Invite liefert HTTP 403. | M |
| FR-INV-11 | Nutzer-Invites sind begrenzt auf höchstens 50 Nutzungen und 365 Tage Gültigkeit sowie 10 neue Invites pro Stunde und Konto (Rate Limit). | M |
| FR-INV-12 | Der erzeugte Link wird sofort weitergabefertig angeboten: Unterstützt das Gerät die Web-Share-API, steht **„Link teilen“** (systemeigenes Teilen-Menü, z. B. Messenger oder Mail) als Hauptaktion vorn, Kopieren daneben. Andernfalls ist **Kopieren in die Zwischenablage** die Hauptaktion — zusätzlich landet der Link direkt nach dem Anlegen automatisch in der Zwischenablage. | M |

### 5.2a Admin-Dashboard *(neu in v2.5)*

| ID | Anforderung | Prio |
|---|---|---|
| FR-ADM-01 | `/admin` zeigt eine Übersicht aller Nutzer mit E-Mail, Anzahl Seiten (inkl. Papierkorb), Aufgaben und Bildern sowie dem belegten Speicher. Der Speicher setzt sich aus der Summe der Bildgrößen und der Größe der gespeicherten Notiz-Dokumente inklusive Versionsschnappschüsse zusammen. Kopfzahlen fassen Nutzer, Seiten, Bilder und Gesamtspeicher zusammen. | M |
| FR-ADM-02 | Der Admin kann einen Nutzer **mit allen Daten löschen**: Workspace, Seiten, Notizen, Versionen, Aufgaben, Freigaben, Sessions und Einladungen entfallen über `ON DELETE CASCADE`, die zugehörigen Bilddateien werden vom Datenträger entfernt. Das Audit-Log bleibt erhalten (`user_id` wird auf NULL gesetzt). | M |
| FR-ADM-03 | Das Löschen erfordert zwei Bestätigungen: eine Rückfrage mit Nennung der betroffenen Mengen und die Eingabe der E-Mail-Adresse des Nutzers. Das eigene Konto kann nicht gelöscht werden. | M |
| FR-ADM-04 | Der Admin kann **verwaiste Bilddateien** löschen — Anhänge, deren Token in keinem Notizinhalt und in keiner Notizversion mehr vorkommt. Die Übersicht nennt Anzahl und Umfang vor dem Aufräumen. | M |
| FR-ADM-05 | Der Admin legt ein **Standard-Speicherkontingent** (MB) fest, das für alle Nutzer ohne eigenen Wert gilt. Der Wert liegt in `app_settings` und ist zur Laufzeit änderbar; `DEFAULT_STORAGE_QUOTA_MB` dient nur als Anfangswert. 0 bedeutet unbegrenzt. | M |
| FR-ADM-06 | Der Admin kann je Nutzer ein **persönliches Kontingent** setzen oder auf den Standardwert zurückstellen. Beim Upload wird das wirksame Kontingent des **Seiteneigentümers** geprüft — eine Schreibfreigabe verbraucht dessen Kontingent, nicht das des Hochladenden. Bilder **und** Dateianhänge zählen dabei gemeinsam; beide Upload-Wege müssen dieselbe Summe bilden, sonst ließe sich die Grenze über den jeweils anderen Weg umgehen. Ist das Kontingent erschöpft, wird der Upload mit HTTP 422 und Nennung von Kontingent und Belegung abgelehnt. | M |
| FR-ADM-08 | **Betriebshinweis:** Der ausgelieferte Standardwert `DEFAULT_STORAGE_QUOTA_MB=0` bedeutet *unbegrenzt*. Da jeder Nutzer Einladungen erzeugen darf (FR-INV-09), ist vor dem Produktivbetrieb ein Kontingent zu setzen — andernfalls kann jedes eingeladene Konto den Datenträger unbegrenzt füllen. | M |
| FR-ADM-07 | Änderungen an Kontingenten sowie das Löschen von Nutzern und verwaisten Dateien werden im Audit-Log vermerkt. | S |

### 5.3 Workspace & Seitenverwaltung

| ID | Anforderung | Prio |
|---|---|---|
| FR-WS-01 | Jeder Nutzer besitzt genau einen Workspace, automatisch bei Kontoerstellung angelegt. | M |
| FR-WS-02 | Beliebig viele Seiten pro Workspace. **Keine Hierarchie** — flache Liste. | M |
| FR-WS-03 | Beim Anlegen wird der Typ gewählt: **Notizseite** oder **Task-Seite**. Der Typ ist danach nicht änderbar. | M |
| FR-WS-04 | Seiten haben Titel (Pflicht, max. 200 Zeichen), optionales Emoji-Icon, Erstell- und Änderungsdatum. | M |
| FR-WS-05 | Seiten können umbenannt, **dupliziert** (inkl. aller Kategorien und Tasks bzw. des Notizinhalts, ohne Share-Links) und gelöscht werden. | M |
| FR-WS-06 | Löschen erfolgt als Soft-Delete in einen **Papierkorb**. Wiederherstellung innerhalb von `TRASH_RETENTION_DAYS` (**Default 90**). Der Papierkorb ist über ein Symbol am Fuß der Seitenleiste erreichbar und zeigt je Seite Typ und Restfrist; einzelne Seiten lassen sich wiederherstellen oder endgültig löschen, zusätzlich kann der Papierkorb komplett geleert werden. Beim endgültigen Löschen verschwinden auch die zugehörigen Bilder und Dateianhänge vom Datenträger. Abgelaufene Einträge räumt `php bin/console.php trash:purge` (für den Cron-Betrieb). | M |
| FR-WS-07 | Die Seitenliste ist sortierbar (zuletzt geändert / Titel A-Z / Erstellungsdatum) und filterbar nach Typ. | S |
| FR-WS-08 | Seiten können als Favorit markiert werden; Favoriten erscheinen oben in der Seitenleiste. | S |
| FR-WS-09 | Manuelle Sortierung der Seiten per Drag & Drop. | K |
| FR-WS-10 | Seiten können mit farbigen **Tags/Labels** versehen und danach gefiltert werden. | K |
| FR-WS-11 | Der Startbildschirm (`/app`) listet **alle** Seiten des Nutzers, die zuletzt bearbeiteten zuerst (rein nach Änderungsdatum, ohne Favoriten-Vorrang). Die Liste wird in Blöcken von 25 Einträgen gerendert und beim Scrollen ans Listenende automatisch nachgeladen; ein Schalter „Weitere Seiten laden“ dient als Rückfallweg. | M |
| FR-WS-12 | Auf schmalen Displays tritt die Liste unmittelbar in den sichtbaren Bereich: Der einleitende Kopfbereich wird dort auf Titel und die beiden Anlegen-Schaltflächen reduziert. | S |
| FR-WS-13 | Seitenleiste und Übersicht stellen jede Seite als **Karte** dar: Titel, darunter eine Kurzinfo (Notiz: erste nicht leere Textzeile, max. 140 Zeichen; Task-Seite: „N Aufgaben · M offen“), darunter kleiner der letzte Bearbeiter und das Änderungsdatum. In der Seitenleiste erscheinen Kurzinfo und Metazeile erst ab 768 px; mobil bleibt es beim reinen Titel. | S |
| FR-WS-14 | Die Kurzinfos werden serverseitig in **zwei Sammelabfragen** je Seitenliste ermittelt (Notizanriss samt letztem Bearbeiter, Aufgabenzahlen), nicht mit einer Abfrage je Seite. Die Parameterlisten werden gestückelt, damit auch große Workspaces die SQLite-Parametergrenze nicht überschreiten. | M |
| FR-WS-15 | Als Favorit markierte Seiten zeigen den Stern dauerhaft und hellrot gefüllt; ein erneuter Klick entfernt die Markierung. Nicht markierte Seiten blenden den Stern auf dem Desktop erst beim Überfahren ein. | S |

### 5.4 Notizseiten (WYSIWYG)

| ID | Anforderung | Prio |
|---|---|---|
| FR-NOTE-01 | Editor-Funktionsumfang mindestens: Überschriften H1–H3, Fett, Kursiv, Durchgestrichen, Inline-Code, Codeblock (mit Syntax-Highlighting), ungeordnete/geordnete Liste, Checkliste, Zitat, Trennlinie, Link, Tabelle. | M |
| FR-NOTE-02 | Der Inhalt wird als **ProseMirror-JSON** in `note_contents.content` gespeichert. Zusätzlich wird eine Reintext-Repräsentation für die Volltextsuche abgelegt. | M |
| FR-NOTE-03 | Serverseitige Validierung des JSON gegen das erlaubte Schema (Allowlist von Node- und Mark-Typen). Bei HTML-Rendering erfolgt zusätzlich Sanitisierung. Rohes HTML aus dem Client wird niemals ungeprüft gespeichert oder ausgeliefert. | M |
| FR-NOTE-04 | Autosave mit Debounce (1–2 s nach letzter Eingabe) sowie bei Verlassen der Seite (`visibilitychange` / `beforeunload`). Sichtbarer Statusindikator: „Speichern…" / „Gespeichert" / „Nicht gespeichert". | M |
| FR-NOTE-05 | Bei Netzwerkfehler wird der Inhalt lokal zwischengespeichert und der Speichervorgang mit exponentiellem Backoff wiederholt. Der Nutzer wird sichtbar gewarnt. | M |
| FR-OFFLINE-01 | Nutzer kann wählen, wie viele zuletzt geänderte Seiten lokal vorgehalten werden: 100, 1.000, 5.000, 10.000 oder alle. | S |
| FR-OFFLINE-02 | Offline-Cache umfasst Seitenmetadaten, Notizinhalt bzw. Task-Board sowie referenzierte Notiz-Bilder und Dateianhänge, soweit sie das Größenlimit aus FR-OFFLINE-06 einhalten. | S |
| FR-OFFLINE-03 | Offline bearbeitete Notizen werden pro Seite zusammengefasst in einer Outbox gehalten und bei Verbindung automatisch oder manuell synchronisiert. Versionskonflikte (409) bleiben sichtbar und können durch Übernahme der lokalen oder der Serverfassung aufgelöst werden. | S |
| FR-OFFLINE-04 | Service Worker liefert App-Shell, Build-Assets und gecachte Attachments auch ohne Netz. Logout löscht den lokalen Inhalt-Cache. | S |
| FR-OFFLINE-05 | Die Anwendung ist als PWA auf iOS und Android zum Home-Bildschirm hinzufügbar und stellt Apple-Touch-, Standard- und Maskable-Icons bereit. | S |
| FR-OFFLINE-06 | Anhänge und eingebettete Bilder werden bis zu einer **vom Admin gesetzten Größe** (Default 250 KB, Grenzen 0–102.400 KB; `OFFLINE_ATTACHMENT_MAX_KB` als Anfangswert) automatisch zur Notiz mit heruntergeladen. Größere Dateien bleiben sichtbar, sind aber als „nur mit Internetverbindung" gekennzeichnet: Dateianhänge zeigen beim Öffnen einen Hinweis, eingebettete Bilder einen Platzhalter mit derselben Aussage. Ein gesenktes Limit entfernt bereits gespeicherte Dateien beim nächsten Abgleich. | S |
| FR-NOTE-06 | Tastaturkürzel: Strg/Cmd+B, +I, +U, +K (Link), +S (manuell speichern), +Z/+Shift+Z (Undo/Redo). | S |
| FR-NOTE-07 | Slash-Befehl (`/`) öffnet ein Blockauswahl-Menü an der Cursorposition. | S |
| FR-NOTE-08 | Markdown-Eingabekürzel beim Tippen (`# `, `- `, `> `, `` ``` ``). | S |
| FR-NOTE-09 | Versionsverlauf mit bis zu 20 Snapshots pro Seite, Wiederherstellung möglich. Snapshot nur am Sitzungsende: wenn der letzte Speichervorgang mindestens **30 Minuten** zurückliegt **oder** von einem anderen Nutzer stammt (kein Snapshot pro Autosave). Zusätzlich Snapshot bei Konflikt-Überschreiben und vor Wiederherstellen. Wiederherstellen nur durch den Seiteneigentümer (siehe FR-SHR-08). | S |
| FR-NOTE-10 | Bild-Upload per Drag & Drop / Paste, lokale Ablage außerhalb des Web-Roots, Auslieferung über authentifizierten Endpunkt. Grenze über `MAX_UPLOAD_MB`. | K |
| FR-NOTE-11 | Import einer Markdown-Datei als neue Notizseite. | K |
| FR-NOTE-12 | Die Werkzeugleiste enthält eine **Checkliste** als eigene Aufzählungsart. Deren Einträge tragen eine Checkbox, die direkt im Editor an- und abgewählt wird; der Zustand steht als `taskItem.checked` im ProseMirror-JSON und ist Teil der serverseitigen Allowlist. Abgehakte Einträge werden durchgestrichen und gedämpft dargestellt. In nur lesenden Freigaben sind die Checkboxen deaktiviert. | M |
| FR-NOTE-13 | Ein mit Enter erzeugter neuer Absatz steht eng am vorhergehenden (Abstand ≈ 0,35 em). Der größere Blockabstand bleibt Überschriften, Listen, Tabellen, Zitaten und Codeblöcken vorbehalten. | M |
| FR-NOTE-14 | Die Werkzeugleiste bietet **Bild einfügen** (Dateiauswahl) und mobil zusätzlich **Foto aufnehmen** (`capture="environment"`, öffnet direkt die Kamera). Beide Wege nutzen denselben Upload-Pfad wie Drag & Drop und Einfügen aus der Zwischenablage inklusive Platzhalter während des Uploads. | M |
| FR-NOTE-15 | Ausgewählte Bilder werden **vor dem Upload im Browser verkleinert**: längste Kante maximal 2560 px, Neukodierung als JPEG (Qualität 0,85), sofern das Original größer als 2560 px oder 2 MB ist. Kamerabilder überschritten sonst regelmäßig `MAX_UPLOAD_MB` bzw. die 40-Megapixel-Grenze. Kann der Browser das Format nicht dekodieren (z. B. HEIC), geht das Original an den Server, der es mit verständlicher Meldung ablehnt. | M |
| FR-NOTE-16 | Ein Tipp auf ein Bild öffnet es auf Handy-Breite **formatfüllend** über der Seite; Schließen per Tipp auf die Fläche, über den Schließen-Schalter oder mit `Escape`. Auf dem Desktop bleibt der Klick beim Auswählen des Bildknotens, damit die Größe weiterhin gezogen werden kann. Der Betrachter greift auch in nur lesenden Freigaben. | M |
| FR-NOTE-17 | Bilder behalten in jeder Viewport-Breite ihr **Seitenverhältnis**. Ist das gespeicherte Bild breiter als der verfügbare Platz, wird die Breite gedeckelt und die Höhe folgt proportional. Hinweis für die Umsetzung: Die TipTap-Resize-NodeView schreibt die gespeicherte Größe als Inline-Style auf das `<img>`; die Höhe muss deshalb mit `height: auto !important` überschrieben werden, und die Resize-Container brauchen `min-width: 0`, damit die Breitendeckelung im Flex-Layout greift. | M |
| FR-NOTE-18 | Eine Notizseite kann **Dateianhänge** tragen. Sie hängen an der Seite (nicht am Dokumentinhalt) und werden über einen Büroklammer-Schalter in der Werkzeugleiste hochgeladen; Mehrfachauswahl ist möglich. **Jeder Dateityp ist erlaubt**; der tatsächliche MIME-Typ des Inhalts wird ermittelt und gespeichert, dient aber nur der Anzeige und der Entscheidung über den PDF-Betrachter. Dateien liegen außerhalb des Web-Roots unter `files/{page_id}/{hex}.bin` — ohne sprechende Endung, damit der Webserver sie nicht interpretieren kann. Der Schutz liegt vollständig in der Auslieferung (FR-NOTE-20). | M |
| FR-NOTE-19 | Anhänge erscheinen als **Badges unter der Überschrift**, jeweils mit Symbol und Dateinamen. Jedes Badge trägt ein kleines ×, das den Anhang samt Datei entfernt (nur mit Schreibrecht). | M |
| FR-NOTE-20 | Ein Klick auf ein Badge **lädt die Datei herunter**; handelt es sich um ein **PDF**, öffnet es stattdessen in einem **überlagerten Betrachter** mit Titelzeile, „in neuem Tab öffnen“ und Schließen (auch via `Escape`). Der Auslieferungsendpunkt setzt entsprechend `Content-Disposition: inline` bzw. `attachment`, immer zusammen mit `X-Content-Type-Options: nosniff`. **Nur für PDF wird der gespeicherte Content-Type gesendet; jeder andere Anhang geht mit `application/octet-stream` heraus.** Da beliebige Dateitypen anhängbar sind (FR-NOTE-18), ist das die tragende Absicherung: Ein HTML- oder SVG-Anhang kann so nicht im Ursprung der Anwendung gerendert werden, auch wenn ein Browser die Disposition ignoriert. Zwei Umsetzungshinweise: (1) `frame-ancestors` gilt für die **eingebettete** Antwort — bei `'none'` verweigert der Browser die Anzeige selbst im gleichnamigen Rahmen, für PDF-Antworten ist deshalb `'self'` zu setzen (siehe NFR-SEC-15). (2) Der Rahmen darf **keine Alpine-Direktive** tragen: Der CSP-Build lehnt das Auswerten von Ausdrücken auf iframes ab und bricht dabei die Initialisierung der gesamten Seite ab; Quelle und Titel werden im JavaScript direkt am Element gesetzt. | M |
| FR-NOTE-21 | Die **maximale Größe je Anhang** legt der Admin fest (Default 10 MB, Grenzen 1–2048 MB); `MAX_ATTACHMENT_MB` dient nur als Anfangswert. Anhänge und eingebettete Bilder zählen gemeinsam auf das Speicherkontingent des Seiteneigentümers (FR-ADM-06). | M |
| FR-NOTE-22 | Der Zugriff auf einen Anhang wird immer über die Seite geprüft: Wer die Seite nicht sehen darf, erhält HTTP 404; Löschen setzt Schreibrecht voraus. Dateinamen werden von Pfadanteilen und Steuerzeichen befreit und auf 150 Zeichen gekürzt. | M |
| FR-NOTE-23 | Der Bild-Betrachter (FR-NOTE-16) unterstützt auf Touchgeräten Pinch-to-Zoom (bis 4×), Verschieben im gezoomten Zustand und Doppeltipp zum Ein-/Auszoomen. | S |
| FR-NOTE-25 | **Jede Seite** — Notiz, Aufgabenliste und Logbuch — kann **optional festhalten, wo sie entstanden ist**. Wann das geschieht, legt der Nutzer je Gerät im Einstellungen-Dialog fest: **„Nur auf Klick"** (Vorgabe) — die Seite entsteht ohne Ort, unter der Überschrift steht „Standort hinzufügen" — oder **„Immer automatisch"**, dann wird der Ort schon beim Anlegen mitgeschickt, auch bei der Sprachnotiz (FR-VOICE-01). Ein gesetzter Ort lässt sich jederzeit **verschieben oder entfernen**: Der Dialog bietet den aktuellen Standort des Geräts sowie die Eingabe von Koordinaten oder eines kopierten Kartenlinks (OpenStreetMap, Google Maps, `geo:`). Gespeichert werden Breite, Länge, gemeldete Genauigkeit und Zeitpunkt an der Seite (Migration 0025). Jeder Fehlschlag der Ortung — abgelehnt, kein Signal, Zeitüberschreitung nach 8 Sekunden — bleibt beim Anlegen folgenlos; beim ausdrücklichen Setzen wird er gemeldet. Beim Anlegen verwirft der Server unbrauchbare Koordinaten stillschweigend, bei einer Eingabe lehnt er sie ab. Die Seite verlinkt den Ort auf OpenStreetMap; eine Karte wird **nicht** eingebunden. Der Ort gehört zur Seite: Freigaben ändern ihn nicht. Anzeige und Dialog sind für alle Seitentypen dieselben (`pageLocationMixin`, `partials/page_location*.php`). | S |
| FR-NOTE-26 | Zu den Koordinaten wird die **Anschrift ermittelt** und an der Seite gespeichert (Migration 0026), damit das Anzeigen einer Notiz keine weitere Anfrage auslöst. Die Suche läuft **serverseitig** über Nominatim (`GEOCODER_URL`, abschaltbar durch einen leeren Wert): So bleibt die IP-Adresse des Nutzers dem Kartendienst unbekannt und die CSP muss nicht gelockert werden. Angezeigt wird die Anschrift, dahinter die Koordinaten auf **zwei Nachkommastellen** gekürzt — gespeichert bleiben sie vollständig. Findet die Suche nichts oder ist sie nicht erreichbar, bleibt es bei den Koordinaten; der Ort selbst geht dadurch nie verloren. | S |
| FR-NOTE-27 | Eine Notiz lässt sich über einen **Drucken**-Schalter im Kopf der Seite drucken. Auf dem Papier stehen ausschließlich Titel und Notizinhalt; Navigation, Werkzeugleiste, Speicherstatus, Aufnahmeort, Anhang-Marken und die Zeile zur letzten Bearbeitung entfallen. Gedruckt wird die Seite selbst über ein Druck-Stylesheet (`@media print`), nicht eine erzeugte Kopie — damit stimmt das Papier stets mit dem Bildschirm überein und Bilder brauchen keine zweite Auslieferung. Im dunklen Modus wird hell gedruckt. Bei verschlüsselten Notizen erscheint der Schalter erst nach dem Entsperren. | S |

### 5.4a Verschlüsselte Notizseiten *(neu in v3.5)*

| ID | Anforderung | Prio |
|---|---|---|
| FR-CRYPT-01 | Der Eigentümer kann eine normale Notizseite ohne Bilder, Dateianhänge oder aktive Kopierfreigabe mit einem eigenen Kennwort verschlüsseln. Schlüsselableitung, Ver- und Entschlüsselung erfolgen ausschließlich im Browser über WebCrypto; Kennwort, KEK, DEK und entschlüsselter Inhalt werden nie an den Server gesendet. | M |
| FR-CRYPT-02 | Der Server speichert bei einer verschlüsselten Seite ausschließlich einen versionierten, authentifizierten Krypto-Umschlag. Formatversion 1 verwendet PBKDF2-HMAC-SHA256 mit 600.000 Iterationen und 16-Byte-Salt sowie AES-256-GCM mit frischem 12-Byte-IV und 128-Bit-Tag. Additional Authenticated Data bindet DEK-Hülle und Payload an die Seiten-ID. | M |
| FR-CRYPT-03 | Das Kennwort wird im Browser nach NFC normalisiert, nicht getrimmt und muss mindestens 12 Unicode-Zeichen umfassen. Ohne Kennwort gibt es keine Wiederherstellung. Kennwort und Schlüssel werden nicht in `localStorage`, IndexedDB, Cache Storage, Cookies oder der Offline-Outbox abgelegt. | M |
| FR-CRYPT-04 | Der Server erzwingt atomar die Zustände `plain` und `encrypted`. Normale Saves dürfen den Zustand nicht ändern; `encrypt`, `rewrap` und `decrypt` prüfen Ausgangszustand und Inhaltsversion. Diese Übergänge sind ausschließlich dem Eigentümer und nur online erlaubt. | M |
| FR-CRYPT-05 | Bei verschlüsselten Seiten ist `content_text` leer, es entstehen keine Versionsschnappschüsse und vorhandene Versionen werden beim Verschlüsseln gelöscht. Inhalts-Volltextsuche, serverseitige Vorschau, Kopierfreigaben, serverseitiges Duplizieren, Bilder, Dateianhänge, KI-Überarbeitung, Diktat in die Notiz und Versionswiederherstellung sind serverseitig gesperrt. FR-NOTE-02/09, FR-SRCH, FR-VOICE und FR-EXP gelten insoweit nur für unverschlüsselte Notizen. | M |
| FR-CRYPT-11 | Verschlüsselte Notizen dürfen mit `read` oder `write` geteilt werden. Der Share-Link enthält nur den Ciphertext; das Notizkennwort wird separat übermittelt und nie serverseitig gespeichert. Öffentliche Leser und angemeldete Schreiber entsperren den Umschlag ausschließlich im Browser. Schreiber dürfen verschlüsselte Saves ausführen, aber weder Kennworthülle noch Verschlüsselungszustand ändern. `read_copy` bleibt für verschlüsselte Notizen gesperrt. | M |
| FR-CRYPT-06 | Verschlüsselte Notizen bleiben nach vorherigem Laden offline les- und bearbeitbar. Offline-Cache, lokaler Notfallcache, Konfliktfassungen und Outbox enthalten ausschließlich Krypto-Umschläge. Ein Klartext-Outboxeintrag darf niemals über eine Verschlüsselungszustandsgrenze synchronisiert werden. | M |
| FR-CRYPT-07 | Beim Öffnen erscheint zunächst eine Sperrmaske. Nach lokaler Kennworteingabe wird der Editor im Browser initialisiert. Sperren oder ein Zustands-/Kennwortwechsel in einem anderen Tab zerstört Editor und erreichbare Schlüsselreferenzen; Tabs koordinieren sich zusätzlich über `BroadcastChannel`, ohne die serverseitige Versionsprüfung zu ersetzen. | M |
| FR-CRYPT-08 | Kennwortwechsel umhüllt denselben DEK mit neuem Salt und IV; der verschlüsselte Payload bleibt bytegleich. Beim bewussten Aufheben der Verschlüsselung weist die UI darauf hin, dass der Klartext anschließend wieder an den Server gesendet sowie durch Suche, Export und Backups verarbeitet wird. | M |
| FR-CRYPT-09 | Workspace-Exporte schreiben verschlüsselte Notizen als gekennzeichnete `.encrypted-note.json`-Dateien mit Umschlag und unverschlüsselten Metadaten, niemals als leere Markdown-Datei. Titel, Notizbuch, Standort, Zeitstempel, Ciphertextgröße und Zugriffsmuster bleiben grundsätzlich unverschlüsselte Metadaten. | M |
| FR-CRYPT-10 | Die Funktion verspricht keine rückwirkende physische Löschung historischer Klartexte aus alten Browser-Speichern, Exporten, Backups, WAL-Dateien, Storage-Snapshots oder Datenträgerblöcken. UI und Betriebsdokumentation benennen diese Grenze ausdrücklich. | M |

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
| FR-TASK-16 | Auf schmalen Displays (< 768 px) zeigt die Task-Seite **ein** Kapitel; die Auswahl erfolgt über ein Dropdown am Seitenkopf. Dessen Beschriftung ist aus Platzgründen nur für Screenreader hinterlegt (`sr-only`). Unter dem Dropdown folgen unmittelbar die Aufgaben — Kapitelname und Aufklapp-Schalter entfallen dort, da das Dropdown sie ersetzt. | M |
| FR-TASK-17 | Die zuletzt gewählte Kategorie wird **pro Task-Seite** im Browser gespeichert (`localStorage`) und beim erneuten Öffnen wiederhergestellt. Existiert sie nicht mehr, greift das erste Kapitel; ein neu angelegtes Kapitel wird direkt angesteuert. | M |
| FR-TASK-18 | Rechts neben dem Kapitel-Dropdown steht ein kompakter Menü-Schalter. Er öffnet ein Menü mit: **Kapitel anlegen**, **Kapitel umbenennen**, **Aufgaben einfügen**, **Kapitel löschen**. Die Einträge wirken auf das im Dropdown gewählte Kapitel; „Kapitel anlegen“ ist immer verfügbar, die übrigen nur bei vorhandener Auswahl. Ohne Schreibrecht entfällt das Menü. Auf dem Desktop bleiben diese Aktionen als einzelne Schalter in der Kapitelleiste. | M |
| FR-TASK-19 | Auf schmalen Displays entfällt der Breadcrumb im Seitenkopf — auf Task-Seiten („Aufgabenliste › Titel“) ebenso wie auf Notizseiten („Notiz › Titel“); der Titel steht dort unmittelbar darunter als Überschrift. Freigabe-Hinweis, Kollaboratoren-Avatare und die Aktionen bleiben im Kopfbereich sichtbar. | S |
| FR-TASK-20 | Trägt eine neue Aufgabe denselben Titel wie eine bestehende **im selben Kapitel** (Vergleich ohne Rücksicht auf Groß-/Kleinschreibung und Randleerzeichen), meldet der Server HTTP 409 mit `DUPLICATE_TITLE` und dem vorhandenen Datensatz. Der Client fragt zurück, ob trotzdem angelegt werden soll, und wiederholt die Anfrage bei Bestätigung mit `allow_duplicate`. Gleiche Titel in verschiedenen Kapiteln sind zulässig. | M |
| FR-TASK-21 | Ist die Seite geteilt, wird der **Verantwortliche aus einer Auswahlliste** gewählt: Eigentümer plus alle Nutzer mit angenommener, gültiger Freigabe — lesend wie schreibend. Freitext bleibt über „Andere Person…“ möglich; ein bestehender Wert außerhalb der Liste schaltet automatisch auf Freitext. | S |
| FR-TASK-22 | Jedes Kapitel trägt neben dem Ein-/Ausblenden erledigter Aufgaben einen Filter **„nur meine Aufgaben"** (Symbol mit Tooltip, aktiver Zustand farblich hervorgehoben). Verglichen wird der Verantwortliche mit dem eigenen Anzeigenamen; der Zustand wird je Seite im Browser gemerkt. Ohne bekannten eigenen Namen entfällt der Schalter. | S |

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
| FR-SHR-03 | Berechtigungsstufen: **Nur Lesen** (öffentlich), **Lesen und Kopieren** (öffentlich lesen, Kopie nur mit Account) oder **Lesen und Schreiben** (Account erforderlich). | M |
| FR-SHR-04 | Share-Links können widerrufen und neu erzeugt werden (Rotation). Nach Widerruf liefert der Link HTTP 404. | M |
| FR-SHR-05 | Optionale Einschränkungen: Ablaufdatum, Passwortschutz, „nur angemeldete Nutzer". | S |
| FR-SHR-06 | Nur-Lesen- und Lesen/Kopieren-Seiten sind ohne Anmeldung in einer eigenständigen Ansicht ohne Workspace-Sidebars aufrufbar. Schreibfreigaben verlangen eine Anmeldung. | M |
| FR-SHR-07 | Geteilte Seiten werden mit `X-Robots-Tag: noindex, nofollow` und `<meta name="robots">` ausgeliefert. | M |
| FR-SHR-08 | Mit Schreibfreigabe kann ein angemeldeter Nutzer Inhalte ändern und Tasks importieren, aber **nicht**: Seite löschen, Freigaben verwalten, Seitentyp ändern, Seite duplizieren, Versionsverlauf wiederherstellen. Eine Kopierfreigabe erzeugt eine unabhängige Seite samt Bildern und Dateianhängen im Workspace des Empfängers. | M |
| FR-SHR-09 | Der Besitzer sieht pro Seite alle aktiven Links mit Berechtigungsstufe, Erstellungsdatum, Ablauf und letztem Zugriff. | S |
| FR-SHR-10 | „Link kopieren"-Button mit visueller Bestätigung. | M |
| FR-SHR-11 | Beim ersten Zugriff über einen Write-Link wird ein Anzeigename abgefragt (Session-gebunden), der bei Änderungen im Audit-Log vermerkt wird. | K |
| FR-SHR-12 | Eine Übersichtsseite listet alle vom Nutzer erzeugten Share-Links workspace-weit auf. | S |
| FR-SHR-13 | In den Headern von Notiz- und Task-Seiten werden Owner und Nutzer mit angenommener, aktiver Schreibfreigabe als Initialen-Avatare angezeigt — **in beiden Seitentypen identisch**. Ein Klick auf die Avatare öffnet eine Liste der Namen („Geteilt mit …“); derselbe Text steht als `title`/`aria-label` an der Avatargruppe. Der Kopfbereich bricht auf schmalen Displays um, damit der Freigabe-Hinweis sichtbar bleibt. | S |
| FR-SHR-15 | Dem Eigentümer wird der Hinweis „Geteilt“ bereits angezeigt, sobald ein aktiver Share-Link besteht — also auch, bevor ihn jemand angenommen hat. | S |
| FR-SHR-14 | Der Owner kann im Teilen-Dialog alle aktiven Freigaben einer Seite gemeinsam widerrufen („Teilen beenden“). | M |
| FR-SHR-16 | Bei „Lesen und Kopieren" bietet die öffentliche Ansicht angemeldeten Nutzern eine Schaltfläche **„Kopie erstellen"** mit optionaler Auswahl eines Zielnotizbuchs. Die Kopie entsteht serverseitig (Bilder und Dateianhänge werden dupliziert), zählt auf das Speicherkontingent des kopierenden Nutzers (FR-ADM-06) und ist auf 10 Kopien pro Stunde und Konto und Freigabe-Token begrenzt. Die Aktion wird im Audit-Log vermerkt. | M |

### 5.8 Export *(neu in v2.0)*

| ID | Anforderung | Prio |
|---|---|---|
| FR-EXP-01 | Eine einzelne Notizseite kann als **Markdown** exportiert werden. | S |
| FR-EXP-02 | Eine einzelne Task-Seite kann als **CSV** exportiert werden (Spalten: Kategorie, Titel, Beschreibung, Responsible, Link, Erledigt, Position). | S |
| FR-EXP-03 | Der gesamte Workspace kann als ZIP (Markdown + CSV + JSON-Rohdaten) exportiert werden. | K |
| FR-EXP-04 | Druckoptimiertes Stylesheet für Notiz- und Task-Seiten (Print-CSS). | K |

---

### 5.9 Import aus anderen Notizprogrammen *(neu in v3.0)*

| ID | Anforderung | Prio |
|---|---|---|
| FR-IMP-19 | Über den Einstellungen-Dialog in der Seitenleiste kann ein **ZIP-Archiv** mit Markdown-Notizen hochgeladen werden, wie es UpNote, Obsidian oder Joplin exportieren. Jede `.md`-Datei wird zu einer eigenen Notizseite; der Dateiname ist der Titel, bei unbrauchbarem Dateinamen die erste Überschrift, sonst „Ohne Titel". Wiederholt die erste Überschrift den Titel, entfällt sie im Inhalt. | S |
| FR-IMP-20 | Das Markdown wird serverseitig in das ProseMirror-JSON des Editors übersetzt: Überschriften (H4+ werden zu H3), Fett, Kursiv, Durchgestrichen, Inline-Code, Codeblöcke mit Sprachangabe, Aufzählungen (auch verschachtelt), nummerierte Listen, Checklisten, Zitate, Trennlinien, Tabellen, Links und Bilder. `<br>` wird zum Zeilenumbruch, sonstiges HTML entfällt; rohes HTML wird nie gespeichert (FR-NOTE-03). | S |
| FR-IMP-21 | Verweise auf mitgelieferte Dateien werden aufgelöst: PNG/JPEG/WebP werden zu eingebetteten **Bildern** der Seite, alle übrigen zu **Dateianhängen** (FR-NOTE-18). Mehrfach verwendete Dateien werden nur einmal gespeichert. Verweise auf andere Notizen des Archivs bleiben Text. Für Größe und Speicherkontingent gelten dieselben Regeln wie beim Upload über die Oberfläche. | S |
| FR-IMP-22 | Erstell- und Änderungsdatum aus dem YAML-Frontmatter (`created`, `date`) werden übernommen, damit die Sortierung nach „zuletzt bearbeitet" erhalten bleibt. | S |
| FR-IMP-23 | Nach dem Import zeigt die Oberfläche einen **Bericht**: Zahl der Notizen, Bilder und Dateianhänge sowie jede übersprungene oder gescheiterte Datei mit Begründung, entfernte Verweise auf nicht mitgelieferte Bilder und Dateien ohne Bezug zu einer Notiz. Eine Notiz, die nicht vollständig angelegt werden kann, wird zurückgerollt statt leer stehen zu lassen; der Import der übrigen läuft weiter. | S |
| FR-IMP-24 | Das Archiv ist auf `IMPORT_MAX_ARCHIVE_MB` begrenzt (Vorgabe 500 MB), zusätzlich auf 20.000 Einträge, 2 GB entpackten Inhalt und 2 MB je Notiz. Der Import wird im Audit-Log vermerkt. | M |
| FR-IMP-25 | Die Oberfläche überträgt das Archiv **in Teilen**: Der Server nennt die Teilgröße (höchstens 4 MB, abgeleitet aus `upload_max_filesize`/`post_max_size`), der Client schneidet die Datei und sendet die Teile der Reihe nach; erst danach wird importiert. Damit spielt die PHP-Konfiguration für den Import keine Rolle mehr. Eine Upload-Sitzung gehört genau einem Nutzer, nimmt Teile nur in der geschnittenen Reihenfolge und nicht mehr Daten als angekündigt an, liegt außerhalb des Web-Roots unter `IMPORT_TMP_PATH` und verfällt nach 6 Stunden; abgebrochene Uploads werden sofort verworfen. Der Upload in **einer** Anfrage bleibt als `POST /api/import/archive` bestehen. | S |

### 5.9a Logbuch-Seiten *(neu in v3.4)*

| ID | Anforderung | Prio |
|---|---|---|
| FR-LOG-01 | Neben Notiz- und Aufgabenseiten gibt es den Seitentyp **Logbuch** (`pages.type = 'log'`, Migration 0027). Er wird wie die anderen angelegt — über die Übersicht, die Seitenleiste und in jedem Notizbuch — und zeigt seine Einträge als Tabelle. Die Seitenliste nennt Zahl der Einträge und den jüngsten. Logbücher sind **online-only**; ohne Verbindung erscheint statt einer leeren Tabelle ein Hinweis. | M |
| FR-LOG-02 | Jeder Eintrag trägt **Datum und Uhrzeit** (`occurred_at`). Sie sind Pflicht und stehen immer in der ersten Spalte. Ohne Angabe gilt der Zeitpunkt der Erfassung. Gespeichert wird in UTC, angezeigt und eingegeben in Ortszeit. Ein neues Logbuch bekommt zusätzlich eine erste Textspalte, damit es sofort benutzbar ist. | M |
| FR-LOG-03 | Weitere Spalten sind **frei definierbar** (höchstens 12 je Seite) und tragen eine Art: **Text**, **Standort**, **Uhrzeit**, **Stunden**, **Zahl** oder **Betrag** (Euro). Spalten lassen sich anlegen, umbenennen, in der Reihenfolge verschieben und löschen; das Löschen entfernt die darin erfassten Werte, nicht die Einträge. | M |
| FR-LOG-04 | **Neueste Einträge stehen oben.** Ein Klick auf eine Spaltenüberschrift sortiert nach dieser Spalte, ein zweiter dreht die Richtung um. Zahlenspalten sortieren numerisch, leere Zellen stehen unabhängig von der Richtung am Ende. Geladen werden höchstens 1000 Einträge. | M |
| FR-LOG-05 | Einträge werden **einzeln hinzugefügt** und in einem Dialog bearbeitet; der **Seitentitel** ist wie bei Notizen durch Klick änderbar. Der Dialog zeigt Zeitpunkt und je Spalte ein passendes Feld (Uhrzeit-, Zahl- bzw. Textfeld, bei Standortspalten zusätzlich ein Knopf für den aktuellen Standort). Ein geleertes Feld löscht den Wert. Löschen ist aus demselben Dialog möglich. | M |
| FR-LOG-06 | Werte werden je Spaltenart geprüft und normalisiert: Uhrzeiten als `HH:MM` (zusätzlich als Minuten abgelegt, damit die Spalte sortiert), Stunden nicht negativ, Beträge und Stunden auf zwei Nachkommastellen gerundet. Deutsch getippte Zahlen (`1.234,50`) werden ebenso verstanden wie `3.5`. Standortspalten erkennen Koordinaten und Kartenlinks, ermitteln dazu die **Anschrift** (FR-NOTE-26) und zeigen sie als Zellinhalt mit Link auf die Karte; die Koordinaten stehen im Tooltip. Ein selbst vergebener Ortsname („Baustelle Nord") bleibt stehen und behält seine Koordinaten. | M |
| FR-LOG-07 | Zahlenspalten (Stunden, Zahl, Betrag) zeigen unter der Tabelle eine **Summe** über die geladenen Einträge. | S |
| FR-LOG-08 | Einträge lassen sich auf der Seite **diktieren**: Die Aufnahme wird transkribiert (FR-VOICE-02) und von einem Modell auf die Spalten des Logbuchs verteilt; auch der Zeitpunkt wird daraus abgeleitet („gestern um halb drei"). Dafür geht die Ortszeit des Geräts mit. Spaltennamen werden ohne Rücksicht auf Groß-/Kleinschreibung zugeordnet, unbekannte Namen verworfen. Die Anweisung an das Modell ist im Admin-Dashboard änderbar (FR-VOICE-05). | S |
| FR-LOG-11 | Bei einem **diktierten** Eintrag kommt der Wert jeder **Ortsspalte immer vom Ortungsdienst des Geräts**, nie aus dem Transkript: Ortsspalten werden dem Modell gar nicht erst vorgelegt, und eine trotzdem gelieferte Ortsangabe wird verworfen. Ohne Ortung bleibt die Spalte leer — geraten wird nichts. Die Anschrift dazu ermittelt der Server wie sonst auch (FR-NOTE-26). | M |
| FR-LOG-09 | **Datum und Uhrzeit eines Eintrags sind jederzeit änderbar** — unabhängig davon, wann er erfasst wurde. `created_at` bleibt davon unberührt. | M |
| FR-LOG-10 | Der Export (FR-EXP-03) schreibt ein Logbuch als **Markdown-Tabelle** mit Zeitpunkt und allen Spalten; senkrechte Striche in Werten werden maskiert. Der Import kennt den Typ nicht und legt aus solchen Dateien wieder Notizen an. | S |

---

### 5.10 Sprachnotizen (Diktat) *(neu in v3.3)*

| ID | Anforderung | Prio |
|---|---|---|
| FR-VOICE-01 | Übersicht und Seitenleiste bieten einen **Aufnahmeknopf** („Sprachnotiz"), die Werkzeugleiste einer Notizseite ein **Mikrofon** für das Diktat in die geöffnete Notiz. Die Aufnahme läuft im Browser über `MediaRecorder`; ein Feld zeigt die laufende Dauer und bietet „Fertig" und „Verwerfen". Am konfigurierten Zeitlimit endet die Aufnahme selbsttätig. Ohne Mikrofonerlaubnis, ohne HTTPS oder ohne `MediaRecorder` bleibt der Knopf ohne Funktion und meldet den Grund. | S |
| FR-VOICE-02 | Die Aufnahme wird an den Server geschickt und dort über die **OpenAI-Transkription** (Vorgabe `gpt-4o-mini-transcribe`) in Text gewandelt. Der Client spricht **nie** direkt mit dem Anbieter; der Schlüssel bleibt auf dem Server. Die Audiodatei liegt nur für die Dauer der Anfrage unter `VOICE_TMP_PATH` und wird danach gelöscht. Beim Diktat in eine offene Notiz wird der Text an der Einfügemarke eingesetzt und über den Autosave gespeichert. | S |
| FR-VOICE-03 | Ein **zweites Modell** (Vorgabe `gpt-4o-mini`) bereitet das Roh-Transkript auf: Erkennungsfehler und Füllwörter raus, Satzzeichen und Absätze rein, Aufzählungen als Markdown. Es liefert zusätzlich eine **kurze Überschrift**. Das Markdown wird serverseitig in das ProseMirror-JSON des Editors übersetzt (derselbe Weg wie beim Import, FR-IMP-20). Die Nachbearbeitung ist abschaltbar; dann entsteht die Notiz aus dem Rohtext, die Überschrift aus dessen erstem Satz. | S |
| FR-VOICE-04 | Aus dem Inhalt wird das **passende Notizbuch** abgeleitet: Das Modell wählt aus den vorhandenen Notizbüchern des Workspaces. Zugeordnet wird nur bei Namensgleichheit (ohne Rücksicht auf Groß-/Kleinschreibung); ein erfundener Name legt **kein** neues Notizbuch an, die Notiz bleibt dann unzugeordnet. | S |
| FR-VOICE-05 | Alle Parameter sind im **Admin-Dashboard** änderbar: Freischaltung, beide Modelle, Sprache, Ein/Aus der Nachbearbeitung, deren Anweisungstext, maximale Aufnahmedauer und -größe sowie die Adresse des Dienstes. Sie liegen in `app_settings`; die Umgebungsvariablen sind nur Anfangswerte. Der **API-Schlüssel** steht ausschließlich in der Umgebung (`OPENAI_KEY`) — er gehört zum Deployment und liegt damit auch nicht in Sicherungen des Workspaces. Das Dashboard zeigt nur, ob er vorhanden ist, und seine letzten vier Zeichen. Änderungen werden im Audit-Log vermerkt. | M |
| FR-VOICE-06 | Ohne Schlüssel oder ohne Freischaltung rendert der Server die Aufnahmeknöpfe gar nicht erst. Aufnahmen sind auf `VOICE_MAX_MB` (höchstens 25 MB, Grenze des Dienstes) und auf die zulässigen Audioformate begrenzt; je Nutzer sind 20 Transkriptionen in 5 Minuten erlaubt. Eine leere Aufnahme legt keine Notiz an. | M |
| FR-VOICE-07 | Störungen des Anbieters (nicht erreichbar, Schlüssel abgelehnt, unlesbare Antwort) liefern HTTP 502 mit dem Code `VOICE_SERVICE_FAILED` statt eines Serverfehlers; die Meldung enthält nie den Schlüssel. Das Anlegen der Notiz erfolgt erst nach erfolgreicher Transkription — eine gescheiterte Aufnahme hinterlässt keine leere Seite. | M |

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
| NFR-UI-04 | Layout: fixierte Seitenleiste (Suche, Seitenliste, Favoriten, Papierkorb, Nutzermenü), Hauptbereich rechts. Seitenleiste einklappbar. Breite auf dem Desktop 20 rem, ab 1024 px 22 rem — die Karten der Seitenliste brauchen mehr Raum als eine reine Titelliste. | M |
| NFR-UI-05 | Responsives Verhalten ab 360 px Viewport-Breite. Task-Kapitel und ihre Aufgaben bleiben vertikal lesbar. | M |
| NFR-UI-06 | Schrift: moderne Sans-Serif (Inter oder Geist), selbst gehostet als Variable Font. Monospace für Codeblöcke. | S |
| NFR-UI-07 | Mikro-Interaktionen: Hover-States, Übergänge 150–250 ms, Skeleton-Loader statt Spinner, Toast-Benachrichtigungen. | S |
| NFR-UI-08 | Die direkte Task-Eingabe bleibt nach Enter fokussiert, damit mehrere Aufgaben ohne Unterbrechung angelegt werden können. | M |
| NFR-UI-09 | Leere Zustände (keine Seiten, keine Tasks, keine Suchtreffer) mit Ikone und klarer Handlungsaufforderung. | S |
| NFR-UI-10 | Optimistische UI-Updates mit Rollback und Fehlermeldung bei Serverfehler. | S |
| NFR-UI-11 | **Command-Palette** (Strg/Cmd+K) für Suche, Seitenwechsel und Schnellaktionen („Neue Notiz", „Neue Task-Seite", „Tasks importieren"). | S |
| NFR-UI-12 | Globale Tastaturkürzel-Übersicht über `?`. | K |
| NFR-UI-13 | Kurzes Onboarding beim ersten Login: automatisch angelegte Beispiel-Notiz und Beispiel-Task-Seite. | K |
| NFR-UI-14 | Auf Touchgeräten ist das Zoomen der Oberfläche unterbunden: `user-scalable=no` und `maximum-scale=1.0` im Viewport-Meta, `touch-action: manipulation` gegen den Doppeltipp-Zoom sowie `text-size-adjust: 100%`. Eingabefelder erhalten mobil mindestens 16 px Schriftgröße, weil iOS Safari sonst beim Fokussieren selbsttätig zoomt. | M |
| NFR-UI-15 | Mobil (< 768 px) wird größer gesetzt als bisher: Notizinhalt 19 px (statt 16 px), Aufgabentitel 18 px, Aufgabenliste 17 px, H1 1,9 rem, H2 1,5 rem. | M |
| NFR-UI-16 | Da NFR-UI-14 den Nutzer-Zoom sperrt, sind Schriftgrößen und Trefferflächen so zu wählen, dass die Oberfläche ohne Zoom bedienbar bleibt (Trefferfläche mind. 44 × 44 px bei primären Aktionen). | M |
| NFR-UI-17 | Mobil nimmt die Seitenleiste die **volle Bildschirmbreite** ein und setzt ihre Einträge größer (Seitenliste 18 px, übrige Einträge 16 px) bei mindestens 44 px hohen Zeilen. Die Typ-Symbole der Seitenliste entfallen dort zugunsten des Titels; das Freigabe-Symbol bleibt. Da die Überlagerung dann verdeckt ist, trägt der Kopf der Leiste einen Schließen-Schalter; zusätzlich schließt sie nach jeder Navigation. Zeilenaktionen (Favorit, Umbenennen, Löschen, Freigabe verlassen) sind mobil dauerhaft sichtbar, weil es dort kein Hover gibt. | M |
| NFR-UI-20 | Das Suchfeld der Seitenleiste trägt links das Lupensymbol; gesucht wird mit Enter. Rechts erscheint erst nach einer Eingabe ein Zurücksetzen-Schalter (×), der Eingabe und Trefferliste leert. Das native Löschsymbol von `type="search"` ist unterdrückt, damit es nur eine Zurücksetzen-Möglichkeit gibt. | S |
| NFR-UI-21 | „Abmelden“ steht als Tür-Symbol rechts neben dem Einstellungs-Zahnrad in der Statuszeile der Seitenleiste, nicht in einer eigenen Zeile. | S |
| NFR-UI-18 | Der Menü-Schalter zum Öffnen der Seitenleiste misst 2,4 rem bei 1,4 rem Icon-Größe. Er liegt fixiert links oben und ist **ausschließlich unter 768 px** sichtbar — ebenso der Schließen-Schalter im Kopf der Seitenleiste. Der Kopf der Seitenleiste rückt mobil so weit ein, dass der Schalter keinen Text überlagert. Hinweis für die Umsetzung: Ungeschichtetes CSS gewinnt in Tailwind 4 gegen jede Utility-Klasse — Hilfsklassen dürfen deshalb kein `display` setzen, sonst bleibt `md:hidden` wirkungslos. | M |
| NFR-UI-19 | Notiz- und Task-Seiten beginnen mobil unmittelbar am oberen Bildschirmrand: Der Kopfbereich trägt dort nur noch geringen Innenabstand, sodass Freigabe-Hinweis und Aktionen ohne vorgelagerten Leerraum sichtbar sind. | S |
| NFR-UI-22 | Auf einer geöffneten Seite (< 768 px) navigiert eine Wisch-Geste nach links von der Seite zur Notizbuch-/Seitenleiste bzw., wenn diese die Seitenliste zeigt, zurück zur Notizbuchübersicht. Wischgesten auf interaktiven Elementen (Links, Editor, Bilder) lösen nicht aus. | K |
| NFR-UI-23 | Bei eingeblendeter Bildschirmtastatur zieht sich die Anwendung auf den sichtbaren Ausschnitt zusammen (`visualViewport`), statt dahinter zu reichen. Die Verschiebung des Ausschnitts durch Safari gleicht **allein die klebende Werkzeugleiste** aus, nie der Inhalt: Rückt der Inhalt nach, gerät die Einfügemarke wieder tiefer, Safari verschiebt erneut und der Ausgleich schaukelt sich auf. `?vp=1` blendet die Maße zur Fehlersuche am Gerät ein. Notwendig, weil iOS die Tastatur weder in `dvh` noch in `innerHeight` einrechnet, sondern nur den sichtbaren Ausschnitt nach oben schiebt: Der klebende Notizkopf samt Werkzeugleiste hinge sonst an einer Kante oberhalb des Bildschirms und verschwände beim Tippen in langen Notizen. Der Editor hält die Einfügemarke zusätzlich unterhalb der klebenden Leisten, deren Höhe gemessen wird (sie brechen mobil um). Da vom Bildschirm dann nur ein schmaler Streifen bleibt, gibt der Seitenkopf (Zurück, Freigabe, Aktionen) beim Tippen seine klebende Position auf und scrollt mit dem Text weg; klebend bleibt allein die Werkzeugleiste. | M |
| NFR-UI-24 | Mobil (< 768 px) zeigt die Werkzeugleiste der Notizseite nur die beim Schreiben am Telefon gebräuchlichen Werkzeuge in **einer** Zeile: Fett, Kursiv, Überschrift 2, Aufzählung, Checkliste, Foto aufnehmen, Diktat, Rückgängig. Die übrigen (Durchgestrichen, Code, Codeblock, Überschrift 1, Zitat, Link, Tabelle, Bild, Anhang, KI, Wiederholen) klappt ein Mehr-Schalter am Ende der Zeile auf; der Zustand bleibt bis zum erneuten Umlegen bestehen. Die Tabellenknöpfe erscheinen unabhängig davon, sobald die Einfügemarke in einer Tabelle steht. Ab 768 px steht die Leiste vollständig. | M |
| NFR-UI-25 | Mobil (< 768 px) bleibt der Kopf der Notizseite **einzeilig**: Zurück-Schalter und Aktionen stehen in derselben Zeile, der Hinweis „Geteilt“ samt Berechtigung und die Personenkreise der Mitbearbeiter entfallen dort. Dass eine Seite geteilt ist, zeigt stattdessen der eingefärbte Teilen-Schalter; sein Titel nennt den Zustand. Ab 768 px stehen Hinweis und Personenkreise wie bisher daneben. | M |

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
| NFR-SEC-14 | Kein `eval`-basiertes JS; Alpine.js wird im CSP-kompatiblen Build eingesetzt oder per Nonce freigegeben. Dieser Build wertet **keine** Ausdrücke auf `<iframe>`-Elementen aus und wirft dabei einen Fehler, der die Initialisierung der gesamten Seite abbricht — Rahmen dürfen deshalb keine Direktiven tragen. | S |
| NFR-SEC-15 | Einzige Ausnahme von `frame-ancestors 'none'` sind Antworten mit `Content-Type: application/pdf`: Sie erhalten `frame-ancestors 'self'`, damit der Anhang-Betrachter sie einbetten kann. Fremde Ursprünge bleiben ausgesperrt, und die ausgelieferten Bytes enthalten keine Anwendungsoberfläche. Ein Test in `tests/Unit/Middleware` sichert beide Fälle ab. | M |
| NFR-SEC-16 | Der Service Worker cacht keine Antworten unter `/s/*`: Freigabe-Tokens sind Bearer-Credentials und dürfen nicht im geräteweiten Shell- oder Attachment-Cache landen, der auch anderen Nutzern desselben Geräts zugänglich ist. | M |

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
| NFR-COMP-02 | Server: PHP 8.5 (min. 8.4) mit `pdo_sqlite`, `mbstring`, `json`, `curl`, `openssl`, `intl`, `fileinfo`, `gd`. SQLite-Build mit FTS5-Unterstützung. | M |
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

**pages** — `id`, `workspace_id` (FK), `type` (`note`\|`task`\|`log`, *`log` neu, Migration 0027*), `title`, `icon`, `is_favorite`, `sort_order`, `default_view` (`board`\|`list`), `deleted_at`, `created_at`, `updated_at`, `location_lat`, `location_lon`, `location_accuracy`, `location_at` (*neu, Migration 0025, FR-NOTE-25*), `location_label` (*neu, Migration 0026, ermittelte Anschrift, FR-NOTE-26*)

**log_columns** *(neu, Migration 0028)* — `id`, `page_id` (FK), `name`, `type` (`text`\|`location`\|`time`\|`hours`\|`number`\|`money`), `position`, `created_at`

**log_entries** *(neu, Migration 0028)* — `id`, `page_id` (FK), `occurred_at` (UTC, vom Nutzer änderbar), `created_at`, `updated_at`, `created_by` (FK, nullable)

**log_values** *(neu, Migration 0028)* — `entry_id` (FK), `column_id` (FK), `value_text`, `value_number`, `value_lat`, `value_lon`, PK (`entry_id`, `column_id`)
*Text und Zahl liegen getrennt, damit Zahlenspalten richtig sortieren und summieren.*

**note_contents** — `page_id` (PK, FK), `content` (ProseMirror-JSON), `content_text` (Reintext für FTS), `updated_at`

**note_versions** — `id`, `page_id` (FK), `content`, `created_at`, `created_by`

**categories** — `id`, `page_id` (FK), `name`, `color`, `position`, `wip_limit`, `created_at`

**tasks** — `id`, `category_id` (FK), `title`, `description`, `responsible`, `link`, `position`, `is_done`, `due_date`, `priority`, `import_batch_id`, `created_at`, `updated_at`

**import_batches** *(neu)* — `id`, `page_id` (FK), `category_id` (FK), `created_by` (nullable bei Share-Zugriff), `line_count`, `created_count`, `skipped_count`, `created_at`
*Ermöglicht die „Rückgängig"-Funktion (FR-IMP-14) über `tasks.import_batch_id`.*

**share_links** — `id`, `page_id` (FK), `token_hash` (UNIQUE), `permission` (`read`\|`write`), `mode` (`read`\|`write`\|`read_copy`, *neu, Migration 0024*), `password_hash`, `requires_login`, `expires_at`, `revoked_at`, `last_accessed_at`, `access_count`, `created_at`
*`mode` steuert die Auslieferung (öffentliche Ansicht vs. authentifizierter Workspace-Zugriff); `permission` bleibt für Anzeige-/Kompatibilitätszwecke erhalten.*

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
| `TRASH_RETENTION_DAYS` | `90` | Aufbewahrung im Papierkorb |
| `DEFAULT_STORAGE_QUOTA_MB` | `0` | Anfangswert des Standard-Speicherkontingents (0 = unbegrenzt); im Admin-Dashboard änderbar |
| `MAX_ATTACHMENT_MB` | `10` | Anfangswert der Obergrenze je Dateianhang; im Admin-Dashboard änderbar |
| `OFFLINE_ATTACHMENT_MAX_KB` | `250` | Anfangswert der Größe, bis zu der Anhänge offline vorgehalten werden (0 = nichts vorladen); im Admin-Dashboard änderbar |
| `IMPORT_MAX_LINES` | `500` | Maximale Zeilenanzahl pro Bulk-Import |
| `IMPORT_MAX_ARCHIVE_MB` | `500` | Obergrenze für das ZIP beim Notiz-Import. Die Oberfläche überträgt in Teilen (FR-IMP-25); hohe PHP-Limits sind nur für den Upload in einer Anfrage nötig |
| `IMPORT_TMP_PATH` | `var/tmp/import` | Ablage der Teile eines laufenden Uploads, außerhalb des Web-Roots |
| `SEARCH_RESULT_LIMIT` | `20` | Ergebnisse pro Suchanfrage |
| `RATE_LIMIT_ENABLED` | `true` | Rate Limiting aktivieren |
| `OPENAI_KEY` | `sk-…` | API-Schlüssel für die Sprachnotizen. **Einzige Quelle** — nicht im Admin-Dashboard änderbar (FR-VOICE-05). Fehlt er, bleibt die Funktion aus |
| `OPENAI_BASE_URL` | `https://api.openai.com/v1` | Adresse des Dienstes; im Admin-Dashboard änderbar |
| `VOICE_TRANSCRIBE_MODEL` | `gpt-4o-mini-transcribe` | Anfangswert des Transkriptionsmodells; im Admin-Dashboard änderbar |
| `VOICE_POSTPROCESS_MODEL` | `gpt-4o-mini` | Anfangswert des Modells für die Nachbearbeitung; im Admin-Dashboard änderbar |
| `VOICE_LANGUAGE` | `de` | Zweistelliger Sprachcode der Diktate; leer überlässt die Erkennung dem Modell |
| `VOICE_MAX_SECONDS` | `300` | Anfangswert der maximalen Aufnahmedauer; im Admin-Dashboard änderbar |
| `VOICE_MAX_MB` | `25` | Anfangswert der maximalen Aufnahmegröße (Obergrenze des Dienstes: 25) |
| `VOICE_TMP_PATH` | `var/tmp/voice` | Ablage der Aufnahme während der Transkription, außerhalb des Web-Roots; wird sofort danach gelöscht |
| `GEOCODER_URL` | `https://nominatim.openstreetmap.org/reverse` | Adresssuche zum Aufnahmeort (FR-NOTE-26); ein leerer Wert schaltet sie ab, dann bleibt es bei den Koordinaten |
| `GEOCODER_LANGUAGE` | `de` | Sprache der ermittelten Anschrift |
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
| GET | `/s/{token}` | Öffentliche Freigabe-Ansicht (Nur-Lesen/Lesen-und-Kopieren) bzw. Login-Aufforderung/Weiterleitung in den Workspace (Lesen-und-Schreiben) |
| POST | `/s/{token}/unlock` | Passwortgeschützte Freigabe entsperren |
| GET | `/s/{token}/images/{imageToken}` | Bild einer öffentlichen Freigabe ausliefern |
| GET | `/s/{token}/files/{attachmentId}` | Dateianhang einer öffentlichen Freigabe ausliefern |
| POST | `/s/{token}/copy` | Seite aus einer Lesen-und-Kopieren-Freigabe in den eigenen Workspace kopieren (Anmeldung erforderlich, FR-SHR-16) |
| GET | `/admin` | Admin-Bereich |
| GET | `/health` | Health-Check |

### 10.2 API-Routen

| Methode | Pfad | Beschreibung |
|---|---|---|
| GET | `/api/pages` | Seiten des Workspaces |
| POST | `/api/pages` | Seite anlegen (`type`, `title`, optional `location` mit `lat`/`lon`/`accuracy`, FR-NOTE-25) |
| PATCH | `/api/pages/{id}` | Titel, Icon, Favorit, Sortierung, Ansicht, `location` (setzen/verschieben; `null` entfernt, FR-NOTE-25) |
| DELETE | `/api/pages/{id}` | Soft-Delete |
| POST | `/api/pages/{id}/restore` | Aus Papierkorb wiederherstellen |
| DELETE | `/api/pages/{id}/purge` | Endgültig löschen |
| POST | `/api/pages/{id}/duplicate` | Seite duplizieren |
| GET | `/api/pages/{id}/content` | Notizinhalt |
| PUT | `/api/pages/{id}/content` | Notizinhalt speichern (Autosave, mit `version` für Konflikterkennung) |
| GET | `/api/pages/{id}/versions` | Versionsliste (Metadaten + Textvorschau; `can_restore`) |
| GET | `/api/pages/{id}/versions/{vid}` | Einzelne Version inkl. Inhalt (Vorschau) |
| POST | `/api/pages/{id}/versions/{vid}/restore` | Version wiederherstellen (nur Eigentümer) |
| GET | `/api/pages/{id}/board` | Kapitel inkl. Tasks |
| **GET** | **`/api/pages/{id}/log?sort=&direction=`** | **Spalten und Einträge eines Logbuchs (FR-LOG-01/04)** |
| **POST** | **`/api/pages/{id}/log/columns`** | **Spalte anlegen (`name`, `type`)** |
| **POST** | **`/api/pages/{id}/log/entries`** | **Eintrag anlegen (`occurred_at`, `values`)** |
| **POST** | **`/api/pages/{id}/log/voice`** | **Eintrag diktieren (multipart, Felder `audio` und `now`, FR-LOG-08)** |
| **PATCH** | **`/api/log-columns/{id}`** | **Spalte umbenennen oder mit `move: up\|down` verschieben** |
| **DELETE** | **`/api/log-columns/{id}`** | **Spalte samt ihrer Werte löschen** |
| **PATCH** | **`/api/log-entries/{id}`** | **Zeitpunkt und Werte ändern (FR-LOG-09)** |
| **DELETE** | **`/api/log-entries/{id}`** | **Eintrag löschen** |
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
| **GET** | **`/api/invites`** | **Eigene Invites auflisten (jeder angemeldete Nutzer)** |
| **POST** | **`/api/invites`** | **Invite erzeugen (Body: `email`, `note`, `max_uses`, `ttl_days`); Antwort enthält den Klartext-Link genau einmal** |
| **DELETE** | **`/api/invites/{id}`** | **Eigenen Invite widerrufen; fremde liefern HTTP 403** |
| **GET** | **`/api/pages/{id}/collaborators`** | **Personen mit Zugriff auf die Seite plus eigenes Konto (Auswahlliste Verantwortliche)** |
| **GET** | **`/api/admin/overview`** | **Nutzer mit Speicherbedarf, Kontingente, verwaiste Dateien (Admin)** |
| **DELETE** | **`/api/admin/users/{id}`** | **Nutzer mit allen Daten löschen (Admin)** |
| **PATCH** | **`/api/admin/users/{id}/quota`** | **Persönliches Kontingent setzen; `null` = Standardwert (Admin)** |
| **PATCH** | **`/api/admin/settings/default-quota`** | **Standard-Speicherkontingent setzen (Admin)** |
| **POST** | **`/api/admin/attachments/purge-orphans`** | **Verwaiste Bilddateien löschen (Admin)** |
| GET | `/api/admin/invites` | Invites auflisten (Admin) |
| POST | `/api/admin/invites` | Invite erzeugen (Admin) |
| DELETE | `/api/admin/invites/{id}` | Invite widerrufen (Admin) |
| **GET** | **`/api/pages/{id}/files`** | **Dateianhänge der Seite + Obergrenze** |
| **POST** | **`/api/pages/{id}/files`** | **Anhang hochladen (multipart, Feld `file`)** |
| **GET** | **`/api/page-attachments/{id}`** | **Anhang ausliefern (PDF inline, sonst Download)** |
| **DELETE** | **`/api/page-attachments/{id}`** | **Anhang löschen** |
| **DELETE** | **`/api/pages/trash`** | **Papierkorb vollständig leeren** |
| **PATCH** | **`/api/admin/settings/max-attachment`** | **Obergrenze je Anhang setzen (Admin)** |
| **PATCH** | **`/api/admin/settings/offline-attachment`** | **Offline-Limit je Anhang in KB setzen (Admin, FR-OFFLINE-06)** |
| **PATCH** | **`/api/admin/settings/voice`** | **Sprachnotizen konfigurieren: Freischaltung, Modelle, Sprache, Anweisung, Grenzen (Admin, FR-VOICE-05)** |
| **GET** | **`/api/voice/config`** | **Zustand der Sprachnotizen für die Oberfläche (`enabled`, `max_seconds`, `max_bytes`)** |
| **POST** | **`/api/voice/transcribe`** | **Aufnahme transkribieren, ohne zu speichern (multipart, Feld `audio`); liefert Transkript, aufbereiteten Text als ProseMirror-Dokument, Überschriftsvorschlag und Notizbuch (FR-VOICE-02/03)** |
| **POST** | **`/api/voice/notes`** | **Aus einer Aufnahme direkt eine Notiz anlegen (multipart, Feld `audio`, optional `lat`/`lon`/`accuracy`); liefert die neue Seite (FR-VOICE-01–04, FR-NOTE-25)** |
| **GET** | **`/api/session`** | **Frisches CSRF-Token und Offline-Einstellungen (u. a. `attachment_max_bytes`)** |
| **POST** | **`/api/import/archive`** | **ZIP mit Markdown-Notizen in einer Anfrage importieren (multipart, Feld `file`); liefert den Bericht aus FR-IMP-23** |
| **POST** | **`/api/import/archive/parts`** | **Geteilten Upload beginnen (`file_name`, `size`); liefert `upload_id` und `chunk_size`** |
| **POST** | **`/api/import/archive/parts/{id}`** | **Nächsten Teil anhängen (multipart, Felder `chunk` und `index`)** |
| **POST** | **`/api/import/archive/parts/{id}/complete`** | **Teile zusammensetzen und importieren; liefert den Bericht** |
| **DELETE** | **`/api/import/archive/parts/{id}`** | **Geteilten Upload abbrechen und Teile verwerfen** |
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
| **AK-45** | **Ein Nutzer ohne Adminrechte erzeugt über die Seitenleiste einen Invite-Link; der eingeladene Empfänger kann sich damit registrieren.** |
| **AK-46** | **Die eigene Invite-Liste enthält ausschließlich selbst erzeugte Einladungen; der Widerruf eines fremden Invites liefert HTTP 403.** |
| **AK-47** | **In der Datenbank steht nur der SHA-256-Hash des Invite-Tokens, nie der Klartext.** |

### 12.2 Notizen

| ID | Kriterium |
|---|---|
| AK-06 | Eine Notizseite behält nach Neuladen alle Formatierungen unverändert bei. |
| AK-07 | Der Autosave speichert innerhalb von 2 s nach der letzten Eingabe; der Statusindikator wechselt sichtbar auf „Gespeichert". |
| AK-08 | Bei unterbrochener Verbindung erscheint eine Warnung; nach Wiederherstellung wird der Inhalt automatisch gespeichert, ohne Datenverlust. |
| AK-09 | Ein `<script>`-Tag im Notizinhalt wird weder gespeichert noch ausgeführt. |
| **AK-48** | **Eine über die Werkzeugleiste eingefügte Checkliste lässt sich im Editor abhaken; der Haken bleibt nach Neuladen der Seite erhalten.** |
| **AK-49** | **Zwei mit Enter getrennte Absätze stehen sichtbar enger beieinander als ein Absatz und eine nachfolgende Überschrift oder Liste.** |
| **AK-54** | **Nach dem Verschlüsseln enthält `note_contents.content` nur einen gültigen Krypto-Umschlag, `content_text` ist leer und alle bisherigen Versionen der Seite sind gelöscht.** |
| **AK-55** | **Entsperren, Bearbeiten und Autosave einer verschlüsselten Notiz funktionieren, ohne dass Klartext in Requestbody, `localStorage`, IndexedDB oder Offline-Outbox persistiert wird.** |
| **AK-56** | **Ein falsches Kennwort entschlüsselt weder DEK noch Inhalt; Kennwort und Schlüssel verlassen den Browser nicht.** |
| **AK-57** | **Upload-, KI-, Diktat-, Copy- und Versionsendpunkte lehnen eine verschlüsselte Notiz auch bei direktem API-Aufruf ab; Read- und Write-Shares funktionieren nach lokaler Kennworteingabe, Zustandsübergänge bleiben dem Eigentümer vorbehalten.** |
| **AK-60** | **Ein öffentlicher Read-Share liefert nur den Krypto-Umschlag und zeigt den Inhalt erst nach lokaler Entschlüsselung im Browser; ein angemeldeter Write-Empfänger kann mit bekanntem Kennwort verschlüsselte Änderungen speichern.** |
| **AK-58** | **Ein Klartext-Outboxeintrag kann nach erfolgreichem Verschlüsseln nicht mehr synchronisiert oder über „Meine Fassung behalten“ auf die Seite geschrieben werden.** |
| **AK-59** | **Kennwortwechsel verändert Salt und DEK-Hülle, lässt den Payload bytegleich und sperrt andere geöffnete Tabs.** |

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
| AK-53 | Ein Lesen-und-Kopieren-Link zeigt die Seite ohne Anmeldung in einer eigenständigen Ansicht ohne Workspace-Oberfläche; ein angemeldeter Nutzer kann daraus eine unabhängige Kopie mit Bildern und Dateianhängen erstellen, die auf sein eigenes Speicherkontingent angerechnet wird. Nach dem Login über den Freigabe-Link führt der Rückkanal wieder zur Freigabe statt zu `/app`. |

### 12.6 Qualität und Betrieb

| ID | Kriterium |
|---|---|
| AK-39 | Die Anwendung ist auf einem Viewport von 375 px Breite vollständig bedienbar, inklusive Task-Erfassung und Import. |
| **AK-50** | **Auf einem Viewport von 375 px lässt sich die Oberfläche weder per Doppeltipp noch per Aufziehgeste zoomen; das Fokussieren eines Eingabefelds löst in iOS Safari keinen automatischen Zoom aus.** |
| **AK-51** | **Der Startbildschirm zeigt auf 375 px Breite ohne Scrollen bereits Einträge der Seitenliste; beim Scrollen ans Listenende werden weitere Seiten nachgeladen, bis alle Seiten gelistet sind.** |
| **AK-52** | **Auf 375 px Breite zeigt eine Task-Seite genau ein Kapitel; nach Auswahl im Dropdown, Neuladen der Seite und erneutem Öffnen ist dasselbe Kapitel wieder vorausgewählt.** |
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

## 15. Änderungshistorie

| Version | Datum | Änderung |
|---|---|---|
| 3.5 | 2026-07-30 | **Zero-Knowledge-Verschlüsselung einzelner Notizseiten** (FR-CRYPT-01–10, AK-54–59, Migration 0033): PBKDF2/AES-GCM ausschließlich im Browser, serverseitiger Zustandsautomat und Guards, verschlüsselter Offline-Autosave, Sperr- und Kennwortwechsel-Abläufe, Ciphertext-Export sowie dokumentierte Grenzen für Metadaten, historische Backups und WAL-Reste |
| 3.4 | 2026-07-29 | **Logbuch-Seiten** (Kap. 5.9a, FR-LOG-01–10, API 10.2, Migrationen 0027 und 0028): dritter Seitentyp mit Zeitspalte und frei definierbaren Spalten (Text, Standort, Uhrzeit, Stunden, Zahl, Betrag), neueste Einträge oben und Sortierung nach jeder Spalte, Summen der Zahlenspalten, diktierte Einträge über die Spaltenzuordnung eines Modells (`LogService`, `LogRepository`, `LogController`, `VoiceNoteService::transcribeForLog`). Der `Migrator` kann eine Migration jetzt ohne umgebende Transaktion ausführen (`-- migrator:no-transaction`) — nötig, um die CHECK-Bedingung von `pages.type` mit abgeschalteten Fremdschlüsseln umzubauen |
| 3.3 | 2026-07-29 | **Sprachnotizen** (Kap. 5.10, FR-VOICE-01–07, API 10.2): Aufnahme im Browser über `MediaRecorder`, serverseitige Transkription über OpenAI (`gpt-4o-mini-transcribe`), optionale Nachbearbeitung durch ein zweites Modell mit Überschrift und abgeleitetem Notizbuch (`VoiceNoteService`, `OpenAiClient`, `VoiceNoteController`); sämtliche Parameter im Admin-Dashboard, der Schlüssel ausschließlich in `OPENAI_KEY`. Zusätzlich der optionale **Aufnahmeort** von Notizen (FR-NOTE-25/26, Migrationen 0025 und 0026): je Gerät wahlweise auf Klick (Vorgabe) oder automatisch beim Anlegen, jederzeit verschiebbar über Koordinaten oder Kartenlink, mit serverseitig ermittelter Anschrift über Nominatim (`ReverseGeocoder`) |
| 3.2 | 2026-07-27 | Neue Freigabeart „Lesen und Kopieren" (`read_copy`, Migration 0024): eigenständige, nicht indexierbare öffentliche Ansicht ohne Workspace-Oberfläche für Nur-Lesen- und Lesen/Kopieren-Links (`PublicShareController`/`PublicShareService`, Notizinhalt als sanitisiertes HTML über `ProseMirrorHtmlRenderer`); angemeldete Nutzer erzeugen daraus eine unabhängige Kopie samt Bildern und Dateianhängen im eigenen Workspace/Notizbuch (`PageCopyService`, `POST /s/{token}/copy`, FR-SHR-16); Lesen/Schreiben-Links verlangen jetzt zwingend eine Anmeldung, der Login-Rückkanal führt danach über einen signierten `return`-Pfad zurück zur Freigabe (FR-SHR-03/06/08, `OAuthFlight`); Sicherheitskorrektur: Task-Board-Änderungen prüfen nun serverseitig das Schreibrecht (`assertCanWrite`); der Service Worker cacht `/s/`-Antworten nicht mehr, da Share-Tokens Bearer-Credentials sind (NFR-SEC-16); zusätzlich Pinch-Zoom/Verschieben/Doppeltipp im Bild-Betrachter (FR-NOTE-23) und eine mobile Wisch-Geste zwischen Notizbuch- und Seitenliste |
| 1.0 | 2026-07-24 | Erstfassung |
| 2.0 | 2026-07-24 | Technologie-Stack mit aktuellen Versionen (Kap. 3); Volltextsuche als eigenes Kapitel inkl. FTS5-Schema (Kap. 6); Task-Bulk-Import per Textfeld (Kap. 5.6, API 10.3, AK-14 bis AK-23); ergänzt: Papierkorb, Duplizieren, Export, Versionskonflikte, Sessions-Tabelle, Rate-Limiting, Backup/Restore, Lieferumfang |
| 2.1 | 2026-07-25 | FR-NOTE-09: Snapshot-Policy auf 30-Minuten-Idle oder Nutzerwechsel präzisiert; API um Versionsdetail ergänzt |
| 2.2 | 2026-07-25 | Optionale Offline-Nutzung (FR-OFFLINE-01–04): selektiver Cache, Outbox-Sync, Attachments, Service Worker |
| 2.3 | 2026-07-25 | PWA-Installation und Notizbuch-Icons; Kollaboratoren-Initialen; Owner kann alle Freigaben einer Seite beenden |
| 3.1 | 2026-07-27 | Geteilter Upload für den Notiz-Import (FR-IMP-25): Archive gehen in Teilen zum Server, `upload_max_filesize`/`post_max_size` begrenzen den Import nicht mehr |
| 3.0 | 2026-07-27 | Import von Markdown-Archiven aus anderen Notizprogrammen über den Einstellungen-Dialog: Notizen, eingebettete Bilder, Dateianhänge, Zeitstempel und Ergebnisbericht (FR-IMP-19–24, API 10.2, `ext-zip`); Dateianhänge ohne Typbeschränkung, dafür strikt neutrale Auslieferung außer bei PDF (FR-NOTE-18/20) |
| 2.9 | 2026-07-27 | Offline-Anhänge: automatischer Download bis zu einer im Admin einstellbaren Größe (Default 250 KB), Kennzeichnung und Hinweis für größere Dateien, Anhangliste offline verfügbar (FR-OFFLINE-02/06); Anhänge werden im Service Worker cache-first ausgeliefert |
| 2.8 | 2026-07-26 | Sicherheitsprüfung der offenen Änderungen: Kontingentumgehung geschlossen — der Bild-Upload zählte Dateianhänge nicht mit (FR-ADM-06); Betriebshinweis zum unbegrenzten Standardkontingent ergänzt (FR-ADM-08) |
| 2.7 | 2026-07-26 | PDF-Betrachter zeigte nichts an: `frame-ancestors 'none'` verbot das Einbetten der eigenen Antwort (NFR-SEC-15), und Alpines CSP-Build brach an den Bindungen des iframes ab (NFR-SEC-14); neue Seiten erscheinen ohne Neuladen in Seitenleiste und Übersicht |
| 2.6 | 2026-07-26 | Dateianhänge für Notizseiten als Badges mit Download und PDF-Betrachter, Obergrenze durch den Admin (FR-NOTE-18–22, Migration 0020); Papierkorb mit 90 Tagen Frist, Wiederherstellen, Leeren und CLI-Aufräumkommando (FR-WS-06); Admin-Symbol in der Seitenleiste |
| 2.5 | 2026-07-26 | Admin-Dashboard mit Nutzerübersicht, Speicherbedarf, Löschen eines Nutzers samt Daten, Aufräumen verwaister Dateien und Speicherkontingenten (FR-ADM-01–07, API 10.2, Migration 0019); Rückfrage bei doppeltem Task-Titel, Auswahlliste für Verantwortliche und Filter „nur meine Aufgaben" (FR-TASK-20–22); Bilder in Notizen seitenverhältnistreu (FR-NOTE-17) |
| 2.4 | 2026-07-26 | Checklisten im Notiz-Editor und engerer Absatzabstand (FR-NOTE-12/13); Freigabe-Anzeige in Notizen und Task-Seiten vereinheitlicht inkl. Namensliste (FR-SHR-13/15); Invites durch jeden Nutzer (FR-INV-09–12, API `/api/invites`, AK-45–47); Startbildschirm listet alle Seiten mit Nachladen beim Scrollen (FR-WS-11/12); Mobil: Kapitel-Dropdown mit Aktionsmenü und gemerkter Auswahl und ohne Breadcrumb (FR-TASK-16–19), Zoom-Sperre, größere Schrift, bildschirmbreite Seitenleiste und randnaher Seitenkopf (NFR-UI-14–21); Bilder in Notizen per Dateiauswahl und Kamera inkl. Verkleinerung vor dem Upload sowie Vollbild-Betrachter und seitenverhältnistreue Darstellung (FR-NOTE-14–17); Seitenliste als Karte mit Anriss, Aufgabenzahl und Bearbeiter/Datum, breitere Seitenleiste, hervorgehobene Favoriten (FR-WS-13–15, NFR-UI-04) |
