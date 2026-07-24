# Agent Notes

## Project Shape

- This is a Slim 4 PHP 8.4+ application with SQLite, Composer dependencies, and a Vite 7 frontend; `public/index.php` is the HTTP entrypoint and `bin/console.php` is the CLI entrypoint.
- HTTP routes are registered in `app/Config/routes.php`; controllers, domain services, repositories, middleware, and shared support code live under `app/`.
- Frontend entrypoints are `resources/js/app.js` and `resources/js/editor/index.js`; Vite writes the manifest and assets to `public/build/`, which is generated and gitignored.
- Database schema changes are numbered SQL files in `database/migrations/`; `App\Support\Migrator` applies pending files in sorted filename order and records them in SQLite.

## Setup And Commands

- Install backend and frontend dependencies with `composer install` and `npm ci`.
- Copy `.env.example` to `.env` and replace the placeholder `APP_KEY`; non-testing startup exits if `APP_ENV`, `APP_KEY`, `DB_PATH`, or `SESSION_LIFETIME_DAYS` is missing.
- Apply the local database migrations with `composer migrate` (`php bin/console.php migrate`); the CLI also provides `php bin/console.php user:list`.
- Build production assets with `npm run build`; use `npm run dev` for Vite on strict port `5173` when `APP_ENV=development` and `VITE_DEV_SERVER` is configured.

## Verification

- Match CI's backend order when validating changes: `composer audit`, `composer cs`, `composer stan`, then `composer test`.
- Frontend verification is `npm audit --audit-level=high` followed by `npm run build`.
- PHP-CS-Fixer and PHPStan cover `app/`, `tests/`, and `bin/console.php` as configured; PHPStan is level 8 with its cache under `var/phpstan`.
- PHPUnit is configured for `tests/Unit` and `tests/Integration` with `tests/bootstrap.php` and an in-memory SQLite database. The current checkout has no `tests/` directory, so do not treat `composer test` as runnable until tests/bootstrap are present.

## Runtime Constraints

- Keep database paths and other relative paths rooted at the repository; `DB_PATH=:memory:` is reserved for tests, while normal startup resolves relative paths beneath the project root.
- Add migrations rather than editing already-applied migration files; the migrator has no rollback command.
- Do not commit `.env`, `var/`, `vendor/`, `node_modules/`, or `public/build/`; these are ignored and include runtime data, dependencies, caches, or generated assets.
- For framework or requirements context, consult `docs/URS.md`, but prefer `composer.json`, `package.json`, PHP config, and CI when they disagree with the specification.
