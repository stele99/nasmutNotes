#!/usr/bin/env php
<?php

declare(strict_types=1);

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

    default:
        fwrite(STDERR, "Unbekanntes Kommando" . ($command !== null ? ": {$command}" : '') . "\n");
        fwrite(STDERR, "Verfügbare Kommandos: migrate, user:list, trash:purge\n");
        exit(1);
}
