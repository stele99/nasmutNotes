<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Domain\User;
use App\Repositories\AuditLogRepository;
use App\Support\NotFoundException;
use FilesystemIterator;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

/**
 * Sicherung von Datenbank und Uploads (NFR-OPS-06, NFR-OPS-08).
 *
 * Jeder Lauf erzeugt einen **vollständigen** Stand: einen `VACUUM INTO`-Abzug
 * der Datenbank und ein Manifest, das jede Upload-Datei des Zeitpunkts
 * auflistet. Gespeichert werden die Dateien aber nur einmal - in einem
 * inhaltsadressierten Pool. Ein täglicher Lauf kostet damit nur die seither
 * hinzugekommenen Dateien, und trotzdem lässt sich jeder Snapshot für sich
 * allein wiederherstellen; das Löschen eines alten Snapshots beschädigt keinen
 * anderen (anders als bei einer Inkrementalkette).
 *
 * Reihenfolge ist bewusst: erst der Datenbankabzug, dann die Dateien. Ein
 * Upload dazwischen landet dann als Datei ohne Datenbankzeile im Backup - ein
 * harmloser Waisen-Anhang. Andersherum entstünde eine Zeile ohne Datei und
 * damit ein kaputter Anhang nach dem Restore.
 *
 * @phpstan-import-type Manifest from BackupLayout
 * @phpstan-import-type UploadEntry from BackupLayout
 */
final class BackupService
{
    /** Zusammengebaute Download-Archive verfallen; sie sind jederzeit neu erzeugbar. */
    private const TMP_TTL_SECONDS = 3600;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogRepository $auditLog,
        private readonly BackupLayout $layout,
        private readonly string $uploadPath,
        private readonly int $keep,
    ) {
    }

    /**
     * @return array{
     *     id: string,
     *     created_at: string,
     *     database_bytes: int,
     *     upload_count: int,
     *     upload_bytes: int,
     *     new_files: int,
     *     new_bytes: int,
     *     pruned: int,
     *     collected_files: int,
     *     collected_bytes: int
     * }
     */
    public function create(?User $actor = null, ?string $ipHash = null): array
    {
        $this->layout->ensureDirectories();

        $id = gmdate('Y-m-d-His');
        if ($this->layout->exists($id)) {
            // Zwei Läufe in derselben Sekunde: der zweite hätte denselben Namen.
            sleep(1);
            $id = gmdate('Y-m-d-His');
        }

        $cache = $this->hashCache();
        $database = $this->writeDatabaseSnapshot($id);

        $files = $this->collectUploadFiles($cache);
        $newFiles = 0;
        $newBytes = 0;
        $uploadBytes = 0;
        foreach ($files as $entry) {
            $uploadBytes += $entry['bytes'];
            if ($this->storeInPool($this->uploadPath . '/' . $entry['path'], $entry['sha256'])) {
                ++$newFiles;
                $newBytes += $entry['bytes'];
            }
        }

        $manifest = [
            'id' => $id,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'migrations' => $this->migrationCount(),
            'database' => $database,
            'tables' => $this->tableCounts(),
            'uploads' => [
                'count' => count($files),
                'bytes' => $uploadBytes,
                'files' => array_values($files),
            ],
        ];

        $this->layout->writeAtomic(
            $this->layout->snapshotPath($id),
            (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        $pruned = $this->prune();
        $collected = $this->garbageCollect();
        $this->sweepTmp();

        if ($actor !== null) {
            $this->auditLog->log($actor->id, 'backup_created', null, null, $ipHash, [
                'id' => $id,
                'upload_count' => count($files),
                'new_files' => $newFiles,
            ]);
        }

        return [
            'id' => $id,
            'created_at' => $manifest['created_at'],
            'database_bytes' => $database['bytes'],
            'upload_count' => count($files),
            'upload_bytes' => $uploadBytes,
            'new_files' => $newFiles,
            'new_bytes' => $newBytes,
            'pruned' => $pruned,
            'collected_files' => $collected['files'],
            'collected_bytes' => $collected['bytes'],
        ];
    }

    /**
     * Übersicht für die Oberfläche, neueste Sicherung zuerst.
     *
     * @return array<int, array<string, mixed>>
     */
    public function snapshots(): array
    {
        $result = [];
        foreach ($this->layout->ids() as $id) {
            try {
                $manifest = $this->layout->manifest($id);
            } catch (\Throwable) {
                $result[] = ['id' => $id, 'created_at' => '', 'complete' => false, 'broken' => true];

                continue;
            }

            $result[] = [
                'id' => $id,
                'created_at' => $manifest['created_at'],
                'migrations' => $manifest['migrations'],
                'database_bytes' => $manifest['database']['bytes'],
                'upload_count' => $manifest['uploads']['count'],
                'upload_bytes' => $manifest['uploads']['bytes'],
                'total_bytes' => $manifest['database']['bytes'] + $manifest['uploads']['bytes'],
                'page_count' => $manifest['tables']['pages'] ?? 0,
                'complete' => $this->missingPieces($manifest) === 0,
                'broken' => false,
            ];
        }

        return $result;
    }

    /**
     * @return array{
     *     path: string,
     *     keep: int,
     *     snapshot_count: int,
     *     stored_bytes: int,
     *     live_upload_bytes: int,
     *     last_created_at: ?string
     * }
     */
    public function stats(): array
    {
        $ids = $this->layout->ids();
        $last = null;
        if ($ids !== []) {
            try {
                $last = $this->layout->manifest($ids[0])['created_at'];
            } catch (\Throwable) {
                $last = null;
            }
        }

        return [
            'path' => $this->layout->basePath(),
            'keep' => $this->keep,
            'snapshot_count' => count($ids),
            'stored_bytes' => $this->directoryBytes($this->layout->basePath() . '/pool')
                + $this->directoryBytes($this->layout->basePath() . '/db'),
            'live_upload_bytes' => $this->directoryBytes($this->uploadPath),
            'last_created_at' => $last,
        ];
    }

    public function delete(string $id, User $actor, ?string $ipHash): void
    {
        $this->layout->assertId($id);
        if (!$this->layout->exists($id)) {
            throw new NotFoundException('Die Sicherung ist unbekannt.');
        }

        $this->removeSnapshot($id);
        $collected = $this->garbageCollect();

        $this->auditLog->log($actor->id, 'backup_deleted', null, null, $ipHash, [
            'id' => $id,
            'collected_files' => $collected['files'],
        ]);
    }

    /**
     * Baut aus Pool und Manifest ein vollständiges, in sich geschlossenes ZIP.
     * Nach außen ein normales Vollbackup - intern liegt nichts doppelt herum.
     *
     * @return array{path: string, filename: string, bytes: int}
     */
    public function archive(string $id, ?User $actor = null, ?string $ipHash = null): array
    {
        $manifest = $this->layout->manifest($id);
        $this->sweepTmp();

        $directory = $this->layout->tmpPath();
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Das temporäre Verzeichnis konnte nicht angelegt werden.');
        }

        $path = $directory . '/' . $id . '-' . bin2hex(random_bytes(6)) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new \RuntimeException('Das Archiv konnte nicht angelegt werden.');
        }

        $zip->addFromString(
            'manifest.json',
            (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        $zip->addFromString('RESTORE.txt', $this->restoreInstructions($manifest));

        $databaseFile = $this->layout->basePath() . '/' . $manifest['database']['file'];
        if (!is_file($databaseFile)) {
            $zip->close();
            @unlink($path);

            throw new NotFoundException('Der Datenbankabzug dieser Sicherung fehlt.');
        }
        $zip->addFile($databaseFile, 'db/app.sqlite');

        foreach ($manifest['uploads']['files'] as $entry) {
            $poolFile = $this->layout->poolPath($entry['sha256']);
            if (!is_file($poolFile)) {
                continue;
            }
            $name = 'uploads/' . $entry['path'];
            $zip->addFile($poolFile, $name);
            // Bilder und Anhänge sind bereits komprimiert; Deflate kostet hier
            // nur Rechenzeit und bringt praktisch nichts.
            $zip->setCompressionName($name, ZipArchive::CM_STORE);
        }

        if (!$zip->close()) {
            @unlink($path);

            throw new \RuntimeException('Das Archiv konnte nicht geschrieben werden.');
        }

        if ($actor !== null) {
            $this->auditLog->log($actor->id, 'backup_downloaded', null, null, $ipHash, ['id' => $id]);
        }

        return [
            'path' => $path,
            'filename' => 'nasmutnotes-backup-' . $id . '.zip',
            'bytes' => (int) filesize($path),
        ];
    }

    /**
     * Vollständige Datenliste des aktuellen Uploads-Baums, angereichert um die
     * Prüfsumme. Unveränderte Dateien werden nicht neu gelesen: Stimmen Größe
     * und Änderungszeitpunkt mit dem letzten Manifest überein, gilt die dort
     * hinterlegte Prüfsumme. Ein nachträglich komprimiertes Bild
     * (UploadStorage::replace schreibt über `rename`) ändert die mtime immer und
     * wird deshalb zuverlässig neu erfasst.
     *
     * @param array<string, UploadEntry> $cache
     *
     * @return array<string, UploadEntry>
     */
    private function collectUploadFiles(array $cache): array
    {
        if (!is_dir($this->uploadPath)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->uploadPath, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $info */
        foreach ($iterator as $info) {
            if (!$info->isFile()) {
                continue;
            }

            $relative = substr($info->getPathname(), strlen($this->uploadPath) + 1);
            // Halbfertige Uploads gehören nicht in die Sicherung.
            if ($relative === '' || str_contains($relative, '.tmp-')) {
                continue;
            }

            $bytes = (int) $info->getSize();
            $mtime = (int) $info->getMTime();
            $known = $cache[$relative] ?? null;
            $sha256 = $known !== null && $known['bytes'] === $bytes && $known['mtime'] === $mtime
                ? $known['sha256']
                : (string) hash_file('sha256', $info->getPathname());

            $files[$relative] = [
                'path' => $relative,
                'bytes' => $bytes,
                'mtime' => $mtime,
                'sha256' => $sha256,
            ];
        }

        ksort($files);

        return $files;
    }

    /**
     * Prüfsummen des jüngsten Manifests, damit unveränderte Dateien nicht bei
     * jedem Lauf neu gehasht werden müssen.
     *
     * @return array<string, UploadEntry>
     */
    private function hashCache(): array
    {
        $ids = $this->layout->ids();
        if ($ids === []) {
            return [];
        }

        try {
            $manifest = $this->layout->manifest($ids[0]);
        } catch (\Throwable) {
            return [];
        }

        $cache = [];
        foreach ($manifest['uploads']['files'] as $entry) {
            $cache[$entry['path']] = $entry;
        }

        return $cache;
    }

    /** @return array{file: string, bytes: int, sha256: string} */
    private function writeDatabaseSnapshot(string $id): array
    {
        $target = $this->layout->databasePath($id);
        if (is_file($target)) {
            throw new \RuntimeException('Für diesen Zeitpunkt existiert bereits ein Datenbankabzug.');
        }

        // `VACUUM INTO` ist der einzige konsistente Weg: Ein Dateikopie der
        // laufenden Datenbank wäre im WAL-Modus unbrauchbar (NFR-OPS-06).
        $this->pdo->exec("VACUUM INTO '" . str_replace("'", "''", $target) . "'");

        if (!is_file($target)) {
            throw new \RuntimeException('Der Datenbankabzug wurde nicht angelegt.');
        }
        @chmod($target, 0640);

        return [
            'file' => 'db/' . $id . '.sqlite',
            'bytes' => (int) filesize($target),
            'sha256' => (string) hash_file('sha256', $target),
        ];
    }

    /** @return bool `true`, wenn die Datei neu in den Pool kopiert wurde. */
    private function storeInPool(string $source, string $sha256): bool
    {
        $target = $this->layout->poolPath($sha256);
        if (is_file($target)) {
            return false;
        }
        if (!is_file($source)) {
            return false;
        }

        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Das Pool-Verzeichnis konnte nicht angelegt werden.');
        }

        $temporary = $target . '.tmp-' . bin2hex(random_bytes(8));
        if (!copy($source, $temporary)) {
            @unlink($temporary);

            throw new \RuntimeException("Die Datei konnte nicht gesichert werden: {$source}");
        }
        @chmod($temporary, 0640);
        if (!rename($temporary, $target)) {
            @unlink($temporary);

            throw new \RuntimeException("Die Sicherung konnte nicht aktiviert werden: {$source}");
        }

        return true;
    }

    /** Entfernt die ältesten Läufe über die Aufbewahrungsgrenze hinaus. */
    private function prune(): int
    {
        if ($this->keep < 1) {
            return 0;
        }

        $removed = 0;
        foreach (array_slice($this->layout->ids(), $this->keep) as $id) {
            $this->removeSnapshot($id);
            ++$removed;
        }

        return $removed;
    }

    /**
     * Löscht Pool-Dateien, auf die kein aufbewahrtes Manifest mehr zeigt.
     *
     * @return array{files: int, bytes: int}
     */
    private function garbageCollect(): array
    {
        $referenced = [];
        foreach ($this->layout->ids() as $id) {
            try {
                $manifest = $this->layout->manifest($id);
            } catch (\Throwable) {
                // Bei unvollständiger Information lieber nichts löschen.
                return ['files' => 0, 'bytes' => 0];
            }
            foreach ($manifest['uploads']['files'] as $entry) {
                $referenced[$entry['sha256']] = true;
            }
        }

        $pool = $this->layout->basePath() . '/pool';
        if (!is_dir($pool)) {
            return ['files' => 0, 'bytes' => 0];
        }

        $files = 0;
        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($pool, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $info */
        foreach ($iterator as $info) {
            if (!$info->isFile()) {
                continue;
            }
            $name = $info->getFilename();
            if (isset($referenced[$name])) {
                continue;
            }
            // Fremde Dateien im Pool bleiben unangetastet.
            if (preg_match('/^[a-f0-9]{64}$/', $name) !== 1) {
                continue;
            }

            $size = (int) $info->getSize();
            if (@unlink($info->getPathname())) {
                ++$files;
                $bytes += $size;
            }
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    private function removeSnapshot(string $id): void
    {
        @unlink($this->layout->snapshotPath($id));
        @unlink($this->layout->databasePath($id));
    }

    /**
     * Zahl der Dateien, die für einen vollständigen Restore fehlen.
     *
     * @param Manifest $manifest
     */
    private function missingPieces(array $manifest): int
    {
        $missing = 0;
        if (!is_file($this->layout->basePath() . '/' . $manifest['database']['file'])) {
            ++$missing;
        }
        foreach ($manifest['uploads']['files'] as $entry) {
            if (!is_file($this->layout->poolPath($entry['sha256']))) {
                ++$missing;
            }
        }

        return $missing;
    }

    private function sweepTmp(): void
    {
        foreach (glob($this->layout->tmpPath() . '/*.zip') ?: [] as $file) {
            if (time() - (int) @filemtime($file) > self::TMP_TTL_SECONDS) {
                @unlink($file);
            }
        }
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        $statement = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        );
        $names = $statement !== false ? $statement->fetchAll(PDO::FETCH_COLUMN) : [];

        $counts = [];
        foreach ($names as $name) {
            $name = (string) $name;
            try {
                $result = $this->pdo->query('SELECT COUNT(*) FROM "' . str_replace('"', '""', $name) . '"');
                if ($result !== false) {
                    $counts[$name] = (int) $result->fetchColumn();
                }
            } catch (\PDOException) {
                // Schattentabellen der Volltextsuche sind nicht direkt zählbar.
                continue;
            }
        }

        return $counts;
    }

    private function migrationCount(): int
    {
        try {
            $result = $this->pdo->query('SELECT COUNT(*) FROM migrations');

            return $result !== false ? (int) $result->fetchColumn() : 0;
        } catch (\PDOException) {
            return 0;
        }
    }

    private function directoryBytes(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $info */
        foreach ($iterator as $info) {
            if ($info->isFile()) {
                $bytes += (int) $info->getSize();
            }
        }

        return $bytes;
    }

    /** @param Manifest $manifest */
    private function restoreInstructions(array $manifest): string
    {
        return <<<TEXT
        nasmutNotes - Sicherung {$manifest['id']} vom {$manifest['created_at']}

        Inhalt
          db/app.sqlite   Vollstaendiger Datenbankabzug (VACUUM INTO)
          uploads/        Bilder und Dateianhaenge, Pfade wie unter UPLOAD_PATH
          manifest.json   Pruefsummen, Tabellenstaende, Migrationsstand

        Wiederherstellen (Anwendung vorher stoppen bzw. offline nehmen):

          1. php bin/console.php backup:restore {$manifest['id']}

             Das ist der empfohlene Weg. Er prueft die Pruefsummen, legt vorher
             automatisch eine Sicherung des aktuellen Standes an und raeumt die
             WAL-Dateien korrekt weg.

          2. Von Hand aus diesem Archiv:

             cp db/app.sqlite <DB_PATH>
             rm -f <DB_PATH>-wal <DB_PATH>-shm
             cp -r uploads/. <UPLOAD_PATH>/
             php bin/console.php migrate

             Die WAL-Dateien muessen weg: Sonst setzt SQLite den alten Stand
             ueber den eingespielten Abzug.
        TEXT;
    }
}
