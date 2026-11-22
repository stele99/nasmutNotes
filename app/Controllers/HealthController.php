<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Backup\BackupLayout;
use App\Repositories\SettingsRepository;
use App\Support\JsonResponse;
use App\Support\UploadStorage;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HealthController
{
    /** Tägliche Cronjobs: nach anderthalb Tagen ohne Lauf ist einer ausgefallen. */
    public const STALE_AFTER_HOURS = 36;

    /** Zeitpunkt des letzten `trash:purge`-Laufs in app_settings. */
    public const TRASH_PURGE_KEY = 'maintenance.trash_purge_at';

    public function __construct(
        private readonly PDO $pdo,
        private readonly UploadStorage $uploadStorage,
        private readonly ?BackupLayout $backups = null,
        private readonly ?string $migrationsPath = null,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $dbOk = false;
        try {
            $dbOk = $this->pdo->query('SELECT 1') !== false;
        } catch (\Throwable) {
            $dbOk = false;
        }

        // Migrationen laufen erst nach dem Deploy (per CLI). Fehlende oder
        // ausstehende Migrationen dürfen den Health-Check des Deploys deshalb
        // nicht scheitern lassen - sie erscheinen als Warnung.
        $migrationCount = null;
        if ($dbOk) {
            try {
                $stmt = $this->pdo->query('SELECT COUNT(*) AS c FROM migrations');
                $migrationCount = $stmt !== false ? (int) $stmt->fetch()['c'] : null;
            } catch (\Throwable) {
                $migrationCount = null;
            }
        }
        $migrationFiles = $this->migrationsPath !== null
            ? count(glob($this->migrationsPath . '/*.sql') ?: [])
            : null;

        $uploadWritable = $this->uploadStorage->isWritable();

        // Nur eine nicht erreichbare Datenbank ergibt 503. Nicht beschreibbare
        // Uploads oder ein leeres Schema melden "degraded", bleiben aber 200 -
        // ein Neustart oder Rollback des Containers würde daran nichts ändern.
        $status = match (true) {
            !$dbOk => 'error',
            !$uploadWritable || $migrationCount === null => 'degraded',
            default => 'ok',
        };

        // Ausgefallene Cronjobs machen die Anwendung nicht unbenutzbar - sie
        // erscheinen als Warnung, der Statuscode bleibt 200.
        $backupAge = $this->lastBackupAgeHours();
        $purgeAge = $dbOk ? $this->lastTrashPurgeAgeHours() : null;
        $warnings = [];
        if ($dbOk && $migrationCount === null) {
            $warnings[] = 'migrations_missing';
        } elseif ($migrationCount !== null && $migrationFiles !== null && $migrationFiles > $migrationCount) {
            $warnings[] = 'migrations_pending';
        }
        if ($backupAge === null || $backupAge > self::STALE_AFTER_HOURS) {
            $warnings[] = 'backup_stale';
        }
        if ($purgeAge === null || $purgeAge > self::STALE_AFTER_HOURS) {
            $warnings[] = 'trash_purge_stale';
        }

        return JsonResponse::json($response, [
            'status' => $status,
            'database' => $dbOk ? 'ok' : 'error',
            'migrations_applied' => $migrationCount ?? 0,
            'uploads_writable' => $uploadWritable,
            'last_backup_age_hours' => $backupAge,
            'last_trash_purge_age_hours' => $purgeAge,
            'warnings' => $warnings,
        ], $dbOk ? 200 : 503);
    }

    private function lastBackupAgeHours(): ?int
    {
        $latest = $this->backups?->ids()[0] ?? null;
        if ($latest === null) {
            return null;
        }
        $created = \DateTimeImmutable::createFromFormat('!Y-m-d-His', $latest, new \DateTimeZone('UTC'));

        return $created === false ? null : $this->hoursSince($created->getTimestamp());
    }

    private function lastTrashPurgeAgeHours(): ?int
    {
        try {
            $value = (new SettingsRepository($this->pdo))->get(self::TRASH_PURGE_KEY);
        } catch (\Throwable) {
            return null;
        }
        $timestamp = $value !== null ? strtotime($value) : false;

        return $timestamp === false ? null : $this->hoursSince($timestamp);
    }

    private function hoursSince(int $timestamp): int
    {
        return max(0, intdiv(time() - $timestamp, 3600));
    }
}
