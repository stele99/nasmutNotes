# Notizen & Tasks

WYSIWYG-Notizen- und Task-Management-Anwendung nach [`docs/URS.md`](docs/URS.md) (URS-NOTES-001, v2.0).

PHP 8.4+/Slim 4, SQLite (WAL + FTS5-Schema vorbereitet), Vite 7 + Alpine.js (CSP-Build) + TipTap 3.

## Umsetzungsstand

Diese erste Ausbaustufe deckt den kompletten Kernablauf ab:

- Google-OAuth-2.0-Login (Authorization Code Flow + PKCE), Invite-Link-Registrierung, Admin-Bootstrap über `ADMIN_EMAILS`
- Workspace- und Seitenverwaltung (Notiz-/Task-Seiten, Papierkorb, Favoriten, Sortierung/Filter)
- Notizseiten mit TipTap-Editor, Autosave, serverseitiger Schema-Allowlist-Validierung und Versionskonflikt-Erkennung
- Task-Seiten mit Kategorien und Tasks (Anlegen, Bearbeiten, Verschieben, Duplizieren, Löschen mit Verschieben/Kaskade)
- Sicherheits-Querschnitt: CSP (Nonce, kein `unsafe-eval`), CSRF (Double-Submit-Cookie + Origin-Prüfung), Rate-Limiting (Login, Invite-Einlösung, Autosave), Audit-Log, IDOR-Schutz auf jedem Objektzugriff
- Automatisierte Tests (PHPUnit) und PHPStan Level 8 für `app/`, `bin/console.php`, `tests/`

Noch nicht umgesetzt (spätere Ausbaustufen, siehe `docs/URS.md`): Volltextsuche (Kap. 6 — Schema ist vorbereitet), Freigabe per Link (5.7), Bulk-Import (5.6), Export (5.8), vollständiges Admin-Panel (Nutzerliste/Audit-Ansicht), Papierkorb-Retention-Cron, Versionsverlauf-UI, Bild-Upload, Listen-Ansicht für Tasks.

## Voraussetzungen

- PHP ≥ 8.4 mit `pdo_sqlite`, `sqlite3`, `mbstring`, `json`, `curl`, `openssl`, `intl`, `fileinfo`
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

php -S localhost:8080 -t public
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

Der Hoster benötigt neben SSH auch `scp`, `tar` und einen passenden PHP-CLI-
Befehl. Der für GitHub Actions verwendete private Schlüssel darf in der
aktuellen Action keine Passphrase haben.

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

### Entwicklung mit Hot-Module-Reload

Für Frontend-Arbeit mit Live-Reload zwei Prozesse parallel starten:

```bash
npm run dev              # Vite-Dev-Server auf Port 5173
php -S localhost:8080 -t public   # PHP-Anwendung
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
| `UPLOAD_PATH` | Verzeichnis für zukünftige Datei-Uploads |
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` / `GOOGLE_REDIRECT_URI` | OAuth-Client-Zugangsdaten |
| `GOOGLE_HOSTED_DOMAIN` | Optional: Login auf eine Google-Workspace-Domain beschränken |
| `ADMIN_EMAILS` | Kommagetrennte Liste von Admin-E-Mail-Adressen (case-insensitiv) |
| `INVITE_TTL_DAYS` | Standard-Gültigkeit neu erzeugter Invites |
| `SESSION_LIFETIME_DAYS` | Session-Ablauf bei Inaktivität |
| `RATE_LIMIT_ENABLED` | Rate-Limiting global aktivieren/deaktivieren |
| `LOG_LEVEL` | Monolog-Level (`debug`, `info`, `warning`, `error`) |
| `VITE_DEV_SERVER` | Nur `APP_ENV=development`: URL des Vite-Dev-Servers |

Weitere Variablen (`TRASH_RETENTION_DAYS`, `IMPORT_MAX_LINES`, `SEARCH_RESULT_LIMIT`, `BACKUP_PATH`) sind für spätere Ausbaustufen vorgesehen und bereits in `.env.example` dokumentiert.

## CLI-Kommandos

```bash
php bin/console.php migrate     # ausstehende Migrationen anwenden (idempotent)
php bin/console.php user:list   # alle Nutzer auflisten
```

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
