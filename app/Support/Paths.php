<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Zentrale Auflösung aller beschreibbaren Pfade.
 *
 * Der Code darf schreibgeschützt liegen (Container: /app read-only). Alles,
 * was die Anwendung schreibt - Datenbank, Uploads, Sicherungen, temporäre
 * Ablagen, Caches, Logdatei -, liegt unter dem Datenverzeichnis:
 * `APP_DATA_DIR`, ohne die Variable wie bisher das Projektverzeichnis.
 *
 * Relative Werte (z. B. `DB_PATH=var/data/app.sqlite`) gelten relativ zum
 * Datenverzeichnis, absolute Werte bleiben unverändert. Mit APP_DATA_DIR=/data
 * landet die Datenbank also unter /data/var/data/app.sqlite - dieselbe
 * Struktur wie im Projektordner, ein altes var/ lässt sich 1:1 übernehmen.
 */
final class Paths
{
    /** Konfigurationsschlüssel => Standardwert (relativ zum Datenverzeichnis). */
    public const DEFAULTS = [
        'DB_PATH' => 'var/data/app.sqlite',
        'UPLOAD_PATH' => 'var/uploads',
        'BACKUP_PATH' => 'var/backups',
        'IMPORT_TMP_PATH' => 'var/tmp/import',
        'EXPORT_TMP_PATH' => 'var/tmp/export',
        'VOICE_TMP_PATH' => 'var/tmp/voice',
        'MAP_TILE_CACHE_PATH' => 'var/cache/tiles',
        'LOG_FILE' => 'var/log/app.log',
        'GOOGLE_JWKS_CACHE' => 'var/data/google_jwks_cache.json',
    ];

    /** Schlüssel, deren Wert eine Datei ist (angelegt wird der Elternordner). */
    private const FILE_KEYS = ['DB_PATH', 'LOG_FILE', 'GOOGLE_JWKS_CACHE'];

    private static ?string $root = null;

    public static function setRoot(string $rootPath): void
    {
        self::$root = rtrim($rootPath, '/');
    }

    /** Projektverzeichnis (Code, ggf. schreibgeschützt). */
    public static function root(): string
    {
        return self::$root ?? dirname(__DIR__, 2);
    }

    /** Wurzel aller beschreibbaren Daten. */
    public static function dataDir(): string
    {
        $configured = Env::get('APP_DATA_DIR');
        if ($configured === null || trim($configured) === '') {
            return self::root();
        }

        $configured = rtrim(trim($configured), '/');

        return str_starts_with($configured, '/') ? $configured : self::root() . '/' . $configured;
    }

    /** Löst einen konfigurierten Pfad relativ zum Datenverzeichnis auf. */
    public static function data(string $configured): string
    {
        $configured = trim($configured);
        if ($configured === ':memory:') {
            return $configured;
        }
        if (str_starts_with($configured, '/')) {
            return rtrim($configured, '/');
        }

        return self::dataDir() . '/' . trim($configured, '/');
    }

    /**
     * Pfad aus der Umgebung (Schlüssel aus DEFAULTS). Ein leerer Wert ergibt
     * null - so lässt sich z. B. die Logdatei mit LOG_FILE= abschalten.
     */
    public static function fromEnv(string $key): ?string
    {
        $default = self::DEFAULTS[$key] ?? throw new \InvalidArgumentException("Unbekannter Pfadschlüssel: {$key}");
        $value = Env::get($key, $default) ?? $default;

        return trim($value) === '' ? null : self::data($value);
    }

    /** Wie fromEnv(), aber nie leer: Ein leerer Wert fällt auf den Standard zurück. */
    public static function require(string $key): string
    {
        return self::fromEnv($key) ?? self::data(self::DEFAULTS[$key]);
    }

    /**
     * Legt fehlende Ordner beim Start an. Fehler werden nur protokolliert:
     * /health meldet ein nicht beschreibbares Upload-Verzeichnis, und die
     * betroffene Funktion scheitert später mit einer verständlichen Meldung.
     */
    public static function ensureDirectories(): void
    {
        foreach (array_keys(self::DEFAULTS) as $key) {
            $path = self::fromEnv($key);
            if ($path === null || $path === ':memory:') {
                continue;
            }
            $directory = in_array($key, self::FILE_KEYS, true) ? dirname($path) : $path;
            if (is_dir($directory)) {
                continue;
            }
            if (!@mkdir($directory, 0770, true) && !is_dir($directory)) {
                error_log("Datenverzeichnis konnte nicht angelegt werden: {$directory} ({$key})");
            }
        }
    }
}
