# Notizen & Tasks

WYSIWYG-Notizen- und Task-Management-Anwendung nach [`docs/URS.md`](docs/URS.md) (URS-NOTES-001, v2.0).

PHP 8.4+/Slim 4, SQLite (WAL + FTS5-Schema vorbereitet), Vite 7 + Alpine.js (CSP-Build) + TipTap 3.

## Umsetzungsstand

Diese erste Ausbaustufe deckt den kompletten Kernablauf ab:

- Google-OAuth-2.0-Login (Authorization Code Flow + PKCE), Invite-Link-Registrierung, Admin-Bootstrap über `ADMIN_EMAILS`
- Workspace- und Seitenverwaltung (Notiz-/Task-Seiten, Papierkorb, Favoriten, Sortierung/Filter)
- Freigabe von Notiz- und Task-Seiten per Link in drei Stufen: Nur lesen, Lesen und Kopieren (öffentliche Ansicht, angemeldete Nutzer erzeugen eine unabhängige Kopie samt Bildern/Anhängen) sowie Lesen und Schreiben (Anmeldung erforderlich, gemeinsame Bearbeitung im Workspace)
- Notizseiten mit TipTap-Editor, Autosave, Screenshot-Paste/Drag-and-drop, serverseitiger Schema-Allowlist-Validierung und Versionskonflikt-Erkennung
- Task-Seiten mit Kategorien und Tasks (Anlegen, Bearbeiten, Verschieben, Duplizieren, Löschen mit Verschieben/Kaskade)
- Sicherheits-Querschnitt: CSP (Nonce, kein `unsafe-eval`), CSRF (Double-Submit-Cookie + Origin-Prüfung), Rate-Limiting (Login, Invite-Einlösung, Autosave), Audit-Log, IDOR-Schutz auf jedem Objektzugriff
- Automatisierte Tests (PHPUnit) und PHPStan Level 8 für `app/`, `bin/console.php`, `tests/`

Noch nicht umgesetzt (spätere Ausbaustufen, siehe `docs/URS.md`): Volltextsuche (Kap. 6 — Schema ist vorbereitet), Export (5.8), vollständiges Admin-Panel (Nutzerliste/Audit-Ansicht), Papierkorb-Retention-Cron, Versionsverlauf-UI und Listen-Ansicht für Tasks.

## Voraussetzungen

- PHP ≥ 8.4 mit `pdo_sqlite`, `sqlite3`, `mbstring`, `json`, `curl`, `openssl`, `intl`, `fileinfo`, `gd`
- Composer 2.x
- Node.js ≥ 20 mit npm
- Ein Google-Cloud-Projekt mit OAuth-2.0-Client (siehe unten)

## Setup (lokal)

```bash
composer install
npm install

cp .env.example .env
# APP_KEY erzeugen:
php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"
# → Wert in .env bei APP_KEY eintragen

php bin/console.php migrate
npm run build      # oder: npm run dev (Vite-Dev-Server mit HMR, siehe unten)

php -S localhost:8080 -t public public/index.php
```

Anwendung erreichbar unter `http://localhost:8080`. `/health` liefert den Status von Datenbank, Migrationsstand und Upload-Verzeichnis.

## Deployment über GitHub Actions

Der Workflow `.github/workflows/ci.yml` deployt nach erfolgreicher Backend- und
Frontend-CI automatisch bei einem Push auf `main`. Ein manueller Start ist über
GitHub unter **Actions → CI → Run workflow** möglich. Pull Requests deployen
nicht.

Das Deployment-Paket enthält den PHP-Code, `vendor/`, Migrationen und die mit
Vite erzeugten Dateien unter `public/build/`. Die Produktions-Assets werden in
der Action gebaut und müssen nicht committed werden. Die serverseitige `.env`, die
SQLite-Datei und `var/` werden nicht überschrieben. Auf dem Hoster wird nach
dem Upload automatisch `php bin/console.php migrate` ausgeführt.

### Hoster vorbereiten

1. Einen SSH-Schlüssel nur für GitHub Actions erzeugen und den öffentlichen
   Schlüssel beim Hoster in `authorized_keys` hinterlegen.
2. Ein Zielverzeichnis außerhalb oder oberhalb des Web-Roots anlegen und dort
   eine produktive `.env` erstellen. `DB_PATH` sollte auf die persistente
   SQLite-Datei zeigen, zum Beispiel `var/data/app.sqlite`.
3. Den Web-Root des Hosters auf das Unterverzeichnis `public/` des Projekts
   zeigen lassen. `public/index.php` erwartet `app/`, `resources/` und `vendor/`
   relativ zum Projekt-Root.
4. Den PHP-CLI-Befehl des Hosters ermitteln. Das ist je nach Anbieter zum
   Beispiel `php`, `php8.4` oder ein absoluter Pfad.
5. Das persistente `UPLOAD_PATH` anlegen und für den PHP-Prozess beschreibbar
   machen. `upload_max_filesize` und `post_max_size` müssen mindestens so groß
   wie `MAX_UPLOAD_MB` konfiguriert sein.

Der Hoster benötigt neben SSH auch `scp`, `tar` und einen passenden PHP-CLI-
Befehl. Der für GitHub Actions verwendete private Schlüssel darf in der
aktuellen Action keine Passphrase haben.

Für Apache liegt die Rewrite-Konfiguration in `public/.htaccess` und wird mit
ausgeliefert. Falls `/auth/google` oder andere Slim-Routen weiterhin HTTP 404 liefern,
muss der Hoster `mod_rewrite` und `AllowOverride FileInfo` für den Webroot
aktivieren. Bei Nginx muss stattdessen die Domain-Konfiguration alle nicht
vorhandenen Pfade mit `try_files $uri $uri/ /index.php?$query_string;` an
`public/index.php` weiterleiten.

**Warum das Icon-Verzeichnis `public/icon/` heißt (nicht `icons/`):**
Apache definiert in `mods-available/alias.conf` standardmäßig
`Alias /icons/ "/usr/share/apache2/icons/"` für die Symbole von `mod_autoindex`.
Dieses Alias hat Vorrang vor dem DocumentRoot: Unter `/icons/…` liefert der Server
dann die Systemsymbole statt der Anwendungsdateien — vorhandene eigene Icons enden
in HTTP 404, ohne dass `.htaccess` oder Deployment etwas damit zu tun hätten. Der
Verzeichnisname `icon` umgeht das Alias. Beim Umbenennen sind
`resources/views/layout.php`, `resources/views/partials/sidebar.php`,
`public/manifest.webmanifest` und die Precache-Liste in `public/sw.js` mitzuziehen
(dort zusätzlich `SHELL_CACHE` hochzählen, damit Clients die alte Liste verwerfen).

**Statische Dateien liefern 404, obwohl sie auf dem Server liegen?** Erst die
antwortende Ebene bestimmen — die Fehlerseite verrät sie:

```bash
curl -sI https://<domain>/icon/icon-192.png | head -3
```

- Slim-Fehlerseite (`charset=utf-8`) → die Anfrage wurde an `index.php`
  weitergereicht; dann greift die Rewrite-Bedingung nicht.
- Apache-eigene 404 (`charset=iso-8859-1`) → Apache hat selbst gesucht und nichts
  gefunden. Ob Verzeichnis oder Datei fehlt, zeigt der Vergleich: bei
  `Options -Indexes` antwortet ein **vorhandenes** Verzeichnis mit 403:

  ```bash
  curl -s -o /dev/null -w '%{http_code}\n' https://<domain>/icon/     # 403 = Verzeichnis da
  curl -s -o /dev/null -w '%{http_code}\n' https://<domain>/build/    # Gegenprobe
  ```

  403 für das Verzeichnis und 404 für die Datei darin heißt: Verzeichnis vorhanden,
  Inhalt fehlt — oder ein `Alias` schiebt sich davor (siehe oben).
- Bei Nginx greift keine `.htaccess`. Dort muss `try_files` vorhandene Dateien
  zuerst ausliefern; routet die Konfiguration pauschal auf `index.php`, gibt Slim
  für jede statische Datei 404 zurück.

Die folgenden Secrets im GitHub-Environment `production` anlegen:

| Secret | Inhalt |
|---|---|
| `DEPLOY_HOST` | SSH-Hostname des Hosters |
| `DEPLOY_PORT` | SSH-Port, meistens `22` |
| `DEPLOY_USER` | SSH-Benutzer |
| `DEPLOY_PATH` | Absoluter Projektpfad auf dem Hoster |
| `DEPLOY_PHP_BIN` | PHP-CLI-Befehl, zum Beispiel `php8.4` |
| `DEPLOY_SSH_KEY` | Privater Inhalt des Deploy-Schlüssels |
| `DEPLOY_KNOWN_HOSTS` | Geprüfter Eintrag aus `known_hosts` |

`DEPLOY_KNOWN_HOSTS` sollte vor dem Hinterlegen mit dem Hoster-Fingerabdruck
abgeglichen werden, zum Beispiel über `ssh-keyscan -p 22 host.example`.
Private Schlüssel, `.env` und Produktionsdaten gehören nicht ins Repository.

### Cloudflare

Wenn die Domain über Cloudflare proxied wird, sollte **Rocket Loader** für diese
Anwendung deaktiviert oder für die HTML-Seiten ausgeschlossen werden. Rocket
Loader verändert `type="module"`- und Inline-Skripte und kann dadurch die CSP
und die Alpine-Initialisierung stören. Die Anwendung markiert ihre eigenen
Skripte zusätzlich mit `data-cfasync="false"`; die zuverlässigste Lösung ist
trotzdem eine Rocket-Loader-Ausnahme in Cloudflare.

### Entwicklung mit Hot-Module-Reload

Für Frontend-Arbeit mit Live-Reload zwei Prozesse parallel starten:

```bash
npm run dev              # Vite-Dev-Server auf Port 5173
php -S localhost:8080 -t public public/index.php   # PHP-Anwendung
```

`APP_ENV=development` muss in `.env` gesetzt sein, damit `public/index.php` Assets vom Vite-Dev-Server statt aus `public/build/` lädt.

## Google-OAuth-Client einrichten

1. In der [Google Cloud Console](https://console.cloud.google.com/) ein Projekt anlegen (oder ein bestehendes wählen).
2. **APIs & Dienste → OAuth-Zustimmungsbildschirm** konfigurieren (externe Nutzer, Scopes `openid`, `email`, `profile`).
3. **APIs & Dienste → Anmeldedaten → OAuth-Client-ID erstellen** (Typ „Webanwendung“).
4. Autorisierte Redirect-URI eintragen: `https://<deine-domain>/auth/callback` (lokal: `http://localhost:8080/auth/callback`).
5. Client-ID und Client-Secret in `.env` eintragen (`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`).
6. Die eigene E-Mail-Adresse in `ADMIN_EMAILS` eintragen, um beim ersten Login automatisch als Admin angelegt zu werden (kein Invite nötig, FR-AUTH-05).

Alle weiteren Nutzer benötigen einen Invite-Link, erzeugbar unter `/admin` (nur für Adressen aus `ADMIN_EMAILS` erreichbar).

## Umgebungsvariablen

Vollständige Referenz in [`.env.example`](.env.example); Details siehe `docs/URS.md`, Kapitel 9.

| Variable | Beschreibung |
|---|---|
| `APP_ENV` | `development` \| `production` \| `testing` |
| `APP_URL` | Basis-URL der Anwendung (u. a. für CSRF-Origin-Prüfung) |
| `APP_KEY` | Zufälliger Schlüssel für Signaturen (OAuth-Flight-Cookie); `base64:`-Prefix, ≥ 32 Byte |
| `APP_DEBUG` | Im Produktivbetrieb zwingend `false` (sonst Stacktraces im Response) |
| `DB_PATH` | Pfad zur SQLite-Datei, relativ zum Projekt-Root oder absolut (`:memory:` für Tests) |
| `UPLOAD_PATH` | Persistentes Upload-Verzeichnis außerhalb des Webroots; relativ zum Projekt-Root oder absolut |
| `MAX_UPLOAD_MB` | Maximale Größe eines eingefügten Bildes; PHP-Uploadlimits müssen mindestens genauso hoch sein |
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` / `GOOGLE_REDIRECT_URI` | OAuth-Client-Zugangsdaten |
| `GOOGLE_HOSTED_DOMAIN` | Optional: Login auf eine Google-Workspace-Domain beschränken |
| `ADMIN_EMAILS` | Kommagetrennte Liste von Admin-E-Mail-Adressen (case-insensitiv) |
| `INVITE_TTL_DAYS` | Standard-Gültigkeit neu erzeugter Invites |
| `SESSION_LIFETIME_DAYS` | Session-Ablauf bei Inaktivität |
| `RATE_LIMIT_ENABLED` | Rate-Limiting global aktivieren/deaktivieren |
| `LOG_LEVEL` | Monolog-Level (`debug`, `info`, `warning`, `error`) |
| `VITE_DEV_SERVER` | Nur `APP_ENV=development`: URL des Vite-Dev-Servers |

| `BACKUP_PATH` | Ablage der Sicherungen, außerhalb des Webroots; relativ zum Projekt-Root oder absolut |
| `BACKUP_KEEP` | Anzahl aufbewahrter Sicherungen (Standard 14) |
| `EXPORT_TMP_PATH` | Ablage der zusammengebauten Export-Archive; sie verfallen nach einer Stunde |

Weitere Variablen (`TRASH_RETENTION_DAYS`, `IMPORT_MAX_LINES`, `SEARCH_RESULT_LIMIT`) sind für spätere Ausbaustufen vorgesehen und bereits in `.env.example` dokumentiert.

## CLI-Kommandos

```bash
php bin/console.php migrate     # ausstehende Migrationen anwenden (idempotent)
php bin/console.php user:list   # alle Nutzer auflisten
php bin/console.php trash:purge # abgelaufene Papierkorb-Seiten entfernen

php bin/console.php backup:run             # Sicherung anlegen (für Cron)
php bin/console.php backup:list            # vorhandene Sicherungen anzeigen
php bin/console.php backup:verify <id>     # Prüfsummen und Vollständigkeit prüfen
php bin/console.php backup:restore <id>    # Sicherung einspielen
```

## Export

Über den Einstellungen-Dialog (Bereich **Import / Export**) lassen sich beliebige
Notizbücher auswählen und als ZIP herunterladen. Aufbau je Notizbuch ein Ordner:

```
Notizbuch/
  Meine Notiz.md        Markdown mit Frontmatter (title, type, notebook, created, updated)
  Meine Aufgaben.md     Task-Seite: je Kategorie eine Überschrift, Aufgaben als Checkliste
  files/
    screenshot.png      eingebettete Bilder und Dateianhänge
```

Bilder und Anhänge werden relativ nach `files/…` verlinkt — genau die Form, die
der eigene Import wieder auflöst. Ein Export lässt sich damit zurückspielen; das
ist als Round-Trip-Test abgesichert (`ExportImportRoundTripTest`).

Der Papierkorb und mit dir geteilte fremde Seiten bleiben außen vor: Exportiert
wird, was im eigenen Workspace liegt.

## Sicherung und Wiederherstellung

Jeder Lauf erzeugt einen **vollständigen** Stand: einen `VACUUM INTO`-Abzug der
Datenbank (ein einfaches Kopieren der Datei wäre im WAL-Modus unbrauchbar) und
ein Manifest, das jede Upload-Datei des Zeitpunkts mit Prüfsumme auflistet.

Gespeichert wird jede Datei aber nur **einmal**, in einem inhaltsadressierten
Pool unter `BACKUP_PATH/pool/`. Ein weiterer Lauf kostet deshalb nur die seither
hinzugekommenen Dateien — und trotzdem lässt sich jeder Snapshot für sich allein
wiederherstellen. Das Löschen eines alten Snapshots beschädigt keinen anderen,
anders als bei einer klassischen Inkrementalkette.

Der Admin-Bereich unter **`/admin/backups`** zeigt alle Sicherungen, legt neue
an und lädt eine beliebige davon als vollständiges, in sich geschlossenes ZIP
herunter (aus Pool und Manifest zusammengesetzt).

Täglicher Cron-Eintrag:

```cron
0 3 * * * cd /pfad/zum/projekt && php bin/console.php backup:run >> var/log/backup.log 2>&1
```

**Wiederherstellen** — bewusst nur über die CLI, denn der Vorgang ersetzt
Datenbank und Anhänge. Die Anwendung sollte dabei gestoppt sein:

```bash
php bin/console.php backup:list
php bin/console.php backup:verify 2026-07-28-030000
php bin/console.php backup:restore 2026-07-28-030000
php bin/console.php migrate
```

`backup:restore` prüft vorher die Prüfsummen, legt automatisch eine
Sicherheitskopie des aktuellen Standes an und entfernt die WAL-Dateien — bleiben
die liegen, legt SQLite ihren Inhalt über den eingespielten Abzug und der
Restore wäre still wirkungslos. Mit `--prune` werden zusätzlich Dateien
entfernt, die das Manifest nicht kennt (exakter Stand statt Zusammenführung).

Zwei bewusste Auslassungen:

- **Die `.env` ist nicht Teil der Sicherung.** Sie enthält `APP_KEY` und das
  Google-Client-Secret; ein herunterladbares Archiv mit Zugangsdaten wäre ein
  unnötiges Risiko. Die Datei ändert sich praktisch nie und gehört einmalig in
  einen Passwortmanager.
- **Der Pool liegt auf demselben Server.** Er schützt gegen versehentliches
  Löschen in der Anwendung, nicht gegen einen Plattenschaden. Für den Ernstfall
  regelmäßig ein Voll-ZIP herunterladen und außerhalb des Servers ablegen.

## Qualitätssicherung

```bash
composer test    # PHPUnit (tests/Unit, tests/Integration, In-Memory-SQLite)
composer stan     # PHPStan Level 8 (app/, bin/console.php, tests/)
composer cs       # PHP-CS-Fixer, PSR-12 (Dry-Run)
composer cs-fix   # PHP-CS-Fixer anwenden

npm run build     # Produktions-Assets bauen
```

CI (`.github/workflows/ci.yml`) führt `composer audit`, `php-cs-fixer`, PHPStan, PHPUnit sowie `npm audit` sowie den Frontend-Build bei jedem Push/PR aus.

## Projektstruktur

```
app/
  Config/         Container-Definitionen, Routing
  Controllers/     HTTP-Controller (dünn, delegieren an Domain/Repositories)
  Domain/          Geschäftslogik/Services (Auth, Notizen, Task-Board, Seiten)
  Middleware/       Request-ID, Security-Header, Session, CSRF, Auth-/Admin-Gates
  Repositories/     PDO-Datenzugriff je Tabelle
  Support/          Querschnittsfunktionen (Env, Validierung, Renderer, Vite …)
bin/console.php     CLI-Einstiegspunkt (migrate, user:list)
database/migrations Nummerierte SQL-Migrationen
public/index.php    HTTP-Einstiegspunkt (Slim-App, Middleware-Pipeline)
resources/
  css/, js/, views/  Tailwind-Quelle, Alpine-Komponenten, PHP-Views
tests/
  Unit/, Integration/  PHPUnit-Tests (in-memory SQLite für Integrationstests)
```

## Bekannte Einschränkungen dieser Ausbaustufe

- Task-Beschreibung ist Klartext (kein eigener Rich-Text-Editor je Task).
- Keine Team-Workspaces — jede Seite hat genau einen Besitzer (siehe `docs/URS.md`, Annahme 3).
- Alpine.js läuft im CSP-kompatiblen Build (`@alpinejs/csp`); Templates dürfen daher keine beliebigen JS-Ausdrücke (z. B. Arrow Functions, Template-Strings) in `x-*`-Attributen verwenden — Logik gehört in die Alpine-Komponenten (`resources/js/*.js`).
