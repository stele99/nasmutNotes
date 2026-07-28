#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Domain\Backup\BackupLayout;
use App\Domain\Backup\BackupRestorer;
use App\Domain\Backup\BackupService;
use App\Repositories\AuditLogRepository;
use App\Repositories\NoteAttachmentRepository;
use App\Repositories\PageAttachmentRepository;
use App\Repositories\PageRepository;
use App\Support\Database;
use App\Support\Env;
use App\Support\Migrator;
use App\Support\UploadStorage;

require __DIR__ . '/../vendor/autoload.php';

$rootPath = dirname(__DIR__);
Env::load($rootPath);

$command = $argv[1] ?? null;

function resolveDbPath(string $rootPath): string
{
    $dbPath = Env::get('DB_PATH', 'var/data/app.sqlite') ?? 'var/data/app.sqlite';

    return $dbPath === ':memory:' || str_starts_with($dbPath, '/')
        ? $dbPath
        : $rootPath . '/' . $dbPath;
}

function resolvePath(string $rootPath, string $key, string $default): string
{
    $path = Env::get($key, $default) ?? $default;

    return str_starts_with($path, '/') ? rtrim($path, '/') : $rootPath . '/' . trim($path, '/');
}

function backupLayout(string $rootPath): BackupLayout
{
    return new BackupLayout(resolvePath($rootPath, 'BACKUP_PATH', 'var/backups'));
}

function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $value = (float) $bytes;
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        ++$unit;
    }

    return sprintf('%.1f %s', $value, $units[$unit]);
}

switch ($command) {
    case 'migrate':
        $pdo = Database::connect(resolveDbPath($rootPath));
        $migrator = new Migrator($pdo, $rootPath . '/database/migrations');
        $applied = $migrator->migrate();

        if ($applied === []) {
            echo "Keine ausstehenden Migrationen.\n";
            break;
        }

        foreach ($applied as $name) {
            echo "Angewendet: {$name}\n";
        }
        break;

    case 'user:list':
        $pdo = Database::connect(resolveDbPath($rootPath));
        $stmt = $pdo->query('SELECT id, email, name, is_active, created_at, last_login_at FROM users ORDER BY id');
        $users = $stmt !== false ? $stmt->fetchAll() : [];

        if ($users === []) {
            echo "Keine Nutzer vorhanden.\n";
            break;
        }

        foreach ($users as $user) {
            $status = ((int) $user['is_active']) === 1 ? 'aktiv' : 'deaktiviert';
            echo sprintf(
                "#%d  %-30s %-25s %-12s zuletzt: %s\n",
                $user['id'],
                $user['email'],
                $user['name'],
                $status,
                $user['last_login_at'] ?? '—',
            );
        }
        break;

    case 'trash:purge':
        // Für den Cron-Betrieb: entfernt Seiten, deren Aufbewahrungsfrist im
        // Papierkorb abgelaufen ist, samt zugehöriger Bilddateien (FR-WS-06).
        $pdo = Database::connect(resolveDbPath($rootPath));
        $retentionDays = Env::int('TRASH_RETENTION_DAYS', 90);
        $storage = new UploadStorage($rootPath, Env::get('UPLOAD_PATH', 'var/uploads') ?? 'var/uploads');
        $attachments = new NoteAttachmentRepository($pdo);
        $files = new PageAttachmentRepository($pdo);
        $pages = new PageRepository($pdo);

        $expired = $pages->expiredTrashPageIds($retentionDays);
        $removedFiles = 0;

        foreach ($expired as $pageId) {
            $storageNames = array_merge(
                $attachments->storageNamesForPage($pageId),
                $files->storageNamesForPage($pageId),
            );
            foreach ($storageNames as $storageName) {
                $storage->delete($storageName);
                ++$removedFiles;
            }
            $pages->purge($pageId);
        }

        echo sprintf(
            "%d Seite(n) nach %d Tagen endgültig gelöscht, %d Datei(en) entfernt.\n",
            count($expired),
            $retentionDays,
            $removedFiles,
        );
        break;

    case 'backup:run':
        // Für den Cron-Betrieb. Jeder Lauf ist ein vollständiger Stand; nur die
        // seither hinzugekommenen Dateien werden tatsächlich kopiert (NFR-OPS-06).
        $pdo = Database::connect(resolveDbPath($rootPath));
        $storage = new UploadStorage($rootPath, Env::get('UPLOAD_PATH', 'var/uploads') ?? 'var/uploads');
        $service = new BackupService(
            $pdo,
            new AuditLogRepository($pdo),
            backupLayout($rootPath),
            $storage->basePath(),
            Env::int('BACKUP_KEEP', 14),
        );

        $result = $service->create();
        echo sprintf(
            "Sicherung %s: %d Datei(en) / %s erfasst, davon %d neu (%s).\n",
            $result['id'],
            $result['upload_count'],
            formatBytes($result['upload_bytes']),
            $result['new_files'],
            formatBytes($result['new_bytes']),
        );
        echo sprintf("Datenbankabzug: %s\n", formatBytes($result['database_bytes']));
        if ($result['pruned'] > 0 || $result['collected_files'] > 0) {
            echo sprintf(
                "Aufgeräumt: %d alte Sicherung(en), %d Pool-Datei(en) (%s).\n",
                $result['pruned'],
                $result['collected_files'],
                formatBytes($result['collected_bytes']),
            );
        }
        break;

    case 'backup:list':
        $pdo = Database::connect(resolveDbPath($rootPath));
        $storage = new UploadStorage($rootPath, Env::get('UPLOAD_PATH', 'var/uploads') ?? 'var/uploads');
        $service = new BackupService(
            $pdo,
            new AuditLogRepository($pdo),
            backupLayout($rootPath),
            $storage->basePath(),
            Env::int('BACKUP_KEEP', 14),
        );

        $snapshots = $service->snapshots();
        if ($snapshots === []) {
            echo "Keine Sicherungen vorhanden.\n";
            break;
        }

        foreach ($snapshots as $snapshot) {
            if (($snapshot['broken'] ?? false) === true) {
                echo sprintf("%-20s BESCHÄDIGT\n", $snapshot['id']);

                continue;
            }
            echo sprintf(
                "%-20s %s  %4d Seiten  %5d Datei(en)  %10s  %s\n",
                $snapshot['id'],
                $snapshot['created_at'],
                $snapshot['page_count'],
                $snapshot['upload_count'],
                formatBytes((int) $snapshot['total_bytes']),
                $snapshot['complete'] === true ? 'vollständig' : 'UNVOLLSTÄNDIG',
            );
        }

        $stats = $service->stats();
        echo sprintf(
            "\nAblage: %s — belegt %s, Aufbewahrung: %d Läufe.\n",
            $stats['path'],
            formatBytes($stats['stored_bytes']),
            $stats['keep'],
        );
        break;

    case 'backup:verify':
        $id = $argv[2] ?? null;
        if ($id === null) {
            fwrite(STDERR, "Aufruf: backup:verify <id>\n");
            exit(1);
        }

        $restorer = new BackupRestorer(
            backupLayout($rootPath),
            resolveDbPath($rootPath),
            resolvePath($rootPath, 'UPLOAD_PATH', 'var/uploads'),
        );
        $report = $restorer->verify($id);

        echo sprintf("Datenbankabzug: %s\n", $report['database_ok'] ? 'in Ordnung' : 'FEHLERHAFT');
        echo sprintf(
            "Dateien: %d erfasst, %d fehlen, %d beschädigt\n",
            $report['files_total'],
            $report['files_missing'],
            $report['files_corrupt'],
        );

        if (!$report['ok']) {
            fwrite(STDERR, "Die Sicherung ist nicht vollständig wiederherstellbar.\n");
            exit(1);
        }
        echo "Die Sicherung ist vollständig.\n";
        break;

    case 'backup:restore':
        // Ersetzt Datenbank und Uploads. Bewusst nur über die CLI erreichbar und
        // ohne offene Datenbankverbindung (siehe BackupRestorer).
        $id = $argv[2] ?? null;
        if ($id === null) {
            fwrite(STDERR, "Aufruf: backup:restore <id> [--prune] [--yes]\n");
            exit(1);
        }

        $options = array_slice($argv, 3);
        $prune = in_array('--prune', $options, true);
        $confirmed = in_array('--yes', $options, true);

        $layout = backupLayout($rootPath);
        $restorer = new BackupRestorer(
            $layout,
            resolveDbPath($rootPath),
            resolvePath($rootPath, 'UPLOAD_PATH', 'var/uploads'),
        );

        $check = $restorer->verify($id);
        if (!$check['database_ok']) {
            fwrite(STDERR, "Der Datenbankabzug fehlt oder ist beschädigt - Abbruch.\n");
            exit(1);
        }
        if ($check['files_missing'] > 0 || $check['files_corrupt'] > 0) {
            fwrite(STDERR, sprintf(
                "Warnung: %d Datei(en) fehlen, %d beschädigt. Diese bleiben nach dem Restore leer.\n",
                $check['files_missing'],
                $check['files_corrupt'],
            ));
        }

        if (!$confirmed) {
            echo "Sicherung {$id} einspielen? Datenbank und Uploads werden ersetzt.\n";
            echo "Die Anwendung sollte dabei gestoppt sein. Fortfahren? [ja/NEIN]: ";
            $answer = trim((string) fgets(STDIN));
            if (strtolower($answer) !== 'ja') {
                echo "Abgebrochen.\n";
                break;
            }
        }

        // Vor dem Überschreiben den aktuellen Stand sichern - ein Restore auf den
        // falschen Snapshot wäre sonst nicht rückholbar.
        $pdo = Database::connect(resolveDbPath($rootPath));
        $storage = new UploadStorage($rootPath, Env::get('UPLOAD_PATH', 'var/uploads') ?? 'var/uploads');
        $safety = new BackupService(
            $pdo,
            new AuditLogRepository($pdo),
            $layout,
            $storage->basePath(),
            Env::int('BACKUP_KEEP', 14),
        )->create();
        unset($pdo);
        echo "Sicherheitskopie des aktuellen Standes: {$safety['id']}\n";

        $result = $restorer->restore($id, $prune, static fn (string $line) => print($line));
        echo sprintf(
            "Wiederhergestellt: %d Datei(en) kopiert, %d unverändert, %d fehlten%s.\n",
            $result['restored_files'],
            $result['skipped_files'],
            $result['missing_files'],
            $prune ? sprintf(', %d entfernt', $result['pruned_files']) : '',
        );
        echo "Bitte anschließend 'php bin/console.php migrate' ausführen.\n";
        break;

    default:
        fwrite(STDERR, "Unbekanntes Kommando" . ($command !== null ? ": {$command}" : '') . "\n");
        fwrite(
            STDERR,
            "Verfügbare Kommandos: migrate, user:list, trash:purge,"
            . " backup:run, backup:list, backup:verify, backup:restore\n",
        );
        exit(1);
}
