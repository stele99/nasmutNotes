# Agent Notes

## Project Shape

- This is a Slim 4 PHP 8.4+ application with SQLite, Composer dependencies, and a Vite 7 frontend; `public/index.php` is the HTTP entrypoint and `bin/console.php` is the CLI entrypoint.
- HTTP routes are registered in `app/Config/routes.php`; controllers, domain services, repositories, middleware, and shared support code live under `app/`.
- Vite entrypoints are `resources/js/app.js`, `resources/js/editor/index.js`, and `resources/js/public-share.js`; Vite writes the manifest and assets to `public/build/`, which is generated and gitignored.
- Database schema changes are numbered SQL files in `database/migrations/`; `App\Support\Migrator` applies pending files in sorted filename order and records them in SQLite.

## Setup And Commands

- Install backend and frontend dependencies with `composer install` and `npm ci`.
- Copy `.env.example` to `.env` and replace the placeholder `APP_KEY`; non-testing startup exits if `APP_ENV`, `APP_KEY`, `DB_PATH`, or `SESSION_LIFETIME_DAYS` is missing.
- Apply the local database migrations with `composer migrate` (`php bin/console.php migrate`); the CLI also provides `user:list`, `trash:purge` (removes pages whose trash retention has expired, meant for cron), and `backup:run|list|verify|restore` (full `VACUUM INTO` snapshot plus content-addressed upload pool; `backup:restore` is CLI-only by design).
- Build production assets with `npm run build`; use `npm run dev` for Vite on strict port `5173` when `APP_ENV=development` and `VITE_DEV_SERVER` is configured. Local PHP server: `php -S localhost:8080 -t public public/index.php`.

## Verification

- Match CI's backend order when validating changes: `composer audit`, `composer cs`, `composer stan`, then `composer test`.
- Frontend verification is `npm audit --audit-level=high` followed by `npm run build`. `npm test` runs the frontend tests (`node --test tests/Frontend/*.test.js`), but CI does not run them — run them manually when touching JS.
- PHP-CS-Fixer and PHPStan cover `app/`, `tests/`, and `bin/console.php` as configured; PHPStan is level 8 with its cache under `var/phpstan`.
- PHPUnit runs `tests/Unit` and `tests/Integration` with `tests/bootstrap.php` against in-memory SQLite (`phpunit.xml` sets `APP_ENV=testing` and `DB_PATH=:memory:`).

## Runtime Constraints

- Pushing to `main` auto-deploys to production via CI once backend and frontend jobs pass (manual trigger possible via workflow_dispatch; PRs do not deploy) — treat pushes to `main` accordingly.
- Keep database paths and other relative paths rooted at the repository; `DB_PATH=:memory:` is reserved for tests, while normal startup resolves relative paths beneath the project root.
- Add migrations rather than editing already-applied migration files; the migrator has no rollback command.
- Each migration runs inside a transaction unless its file contains the marker `-- migrator:no-transaction`; that marker is reserved for table rebuilds that need `PRAGMA foreign_keys = OFF` (changing a CHECK constraint, for example) and makes the file responsible for its own `BEGIN`/`COMMIT`.
- Alpine.js runs as the CSP build (`@alpinejs/csp`): templates must not use arbitrary JS expressions (arrow functions, template strings) in `x-*` attributes — logic belongs in Alpine components under `resources/js/`.
- `public/icon/` (not `icons/`) is deliberate: Apache's default `/icons/` alias would shadow an `icons/` directory and 404 the files. Renaming requires updating `resources/views/layout.php`, the sidebar partial, `public/manifest.webmanifest`, and the precache list in `public/sw.js` (bump `SHELL_CACHE`).
- Do not commit `.env`, `var/`, `vendor/`, `node_modules/`, or `public/build/`; these are ignored and include runtime data, dependencies, caches, or generated assets.
- For requirements context consult `docs/URS.md`; concept docs `docs/KONZEPT-NOTE-ENCRYPTION.md`, `docs/KONZEPT-NOTESVOICE.md`, and `docs/KONZEPT-DESKTOP-API.md` cover page encryption, voice notes, and the Desktop Assistant proxy; `docs/API-DESKTOP-ASSISTANT.md` is the frozen client contract for the Desktop Assistant endpoints. Prefer `composer.json`, `package.json`, PHP config, and CI when they disagree with the specification.
