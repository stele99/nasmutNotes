<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Gemeinsamer Start für HTTP (public/index.php) und CLI (bin/console.php).
 */
final class Bootstrap
{
    public const TIMEZONE = 'Europe/Berlin';

    public static function init(string $rootPath): void
    {
        // Zeitstempel in der Datenbank sind durchgängig UTC (gmdate, "...Z");
        // die Zeitzone betrifft nur Darstellung und date()-Aufrufe.
        date_default_timezone_set(self::TIMEZONE);

        // Fehler nie in die Antwort, immer ins Log. Ohne error_log-Ziel gibt
        // PHP sie auf stderr aus - im Container landet das im Container-Log.
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        ini_set('log_errors', '1');

        Paths::setRoot($rootPath);
        Env::load($rootPath);

        // Nur in der lokalen Entwicklung dürfen Fehler im Browser erscheinen.
        if (Env::get('APP_ENV') === 'development' && Env::bool('APP_DEBUG', false)) {
            ini_set('display_errors', '1');
        }

        Paths::ensureDirectories();
    }

    /** Fehlerdetails in Antworten nur in der Entwicklung, nie im Betrieb. */
    public static function displayErrorDetails(): bool
    {
        return Env::bool('APP_DEBUG', false) && Env::get('APP_ENV') !== 'production';
    }
}
