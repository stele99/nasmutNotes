<?php

declare(strict_types=1);

namespace App\Support;

use Dotenv\Dotenv;

final class Env
{
    private const REQUIRED = [
        'APP_ENV',
        'APP_KEY',
        'DB_PATH',
        'SESSION_LIFETIME_DAYS',
    ];

    private static bool $loaded = false;

    public static function load(string $rootPath): void
    {
        if (self::$loaded) {
            return;
        }

        if (is_file($rootPath . '/.env')) {
            Dotenv::createImmutable($rootPath)->load();
        }

        self::$loaded = true;

        if (self::get('APP_ENV') === 'testing') {
            return;
        }

        $missing = array_filter(self::REQUIRED, static fn (string $key): bool => self::get($key) === null || self::get($key) === '');
        if ($missing !== []) {
            fwrite(STDERR, 'Fehlende Pflicht-Umgebungsvariablen: ' . implode(', ', $missing) . PHP_EOL);
            exit(1);
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        // getenv() zuerst: Dotenv (Immutable) kann $_ENV aus der .env-Datei befüllen,
        // selbst wenn eine echte Prozess-Umgebungsvariable (getenv) bereits gesetzt ist.
        // getenv() bleibt dabei unangetastet und ist die verlässlichere Quelle.
        $value = getenv($key);
        if ($value === false) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? false;
        }

        return $value === false ? $default : (string) $value;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);

        return $value === null || $value === '' ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default): bool
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
