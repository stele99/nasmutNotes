<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Wiederherstellung aus einer Sicherung (NFR-OPS-06, AK-43).
 *
 * Bewusst ohne `PDO` und nur über die CLI erreichbar: Der Restore ersetzt die
 * laufende Datenbankdatei; eine offene Verbindung darauf würde beim Schließen
 * womöglich noch schreiben und den eingespielten Stand wieder verderben. Aus
 * demselben Grund gibt es dafür keinen Knopf in der Oberfläche - ein Fehlklick
 * wäre nicht rückholbar.
 *
 * @phpstan-import-type Manifest from BackupLayout
 */
final class BackupRestorer
{
    public function __construct(
        private readonly BackupLayout $layout,
        private readonly string $databasePath,
        private readonly string $uploadPath,
    ) {
    }

    /**
     * Prüft, ob eine Sicherung vollständig und unverfälscht ist.
     *
     * @return array{
     *     id: string,
     *     database_ok: bool,
     *     files_total: int,
     *     files_missing: int,
     *     files_corrupt: int,
     *     ok: bool
     * }
     */
    public function verify(string $id): array
    {
        $manifest = $this->layout->manifest($id);

        $databaseFile = $this->layout->basePath() . '/' . $manifest['database']['file'];
        $databaseOk = is_file($databaseFile)
            && hash_file('sha256', $databaseFile) === $manifest['database']['sha256'];

        $missing = 0;
        $corrupt = 0;
        foreach ($manifest['uploads']['files'] as $entry) {
            $poolFile = $this->layout->poolPath($entry['sha256']);
            if (!is_file($poolFile)) {
                ++$missing;

                continue;
            }
            if (hash_file('sha256', $poolFile) !== $entry['sha256']) {
                ++$corrupt;
            }
        }

        return [
            'id' => $id,
            'database_ok' => $databaseOk,
            'files_total' => count($manifest['uploads']['files']),
            'files_missing' => $missing,
            'files_corrupt' => $corrupt,
            'ok' => $databaseOk && $missing === 0 && $corrupt === 0,
        ];
    }

    /**
     * Spielt eine Sicherung ein. Die Anwendung muss dabei stehen.
     *
     * @param bool          $prune Dateien entfernen, die das Manifest nicht kennt
     * @param callable|null $log   Fortschrittsausgabe, z. B. `fn (string $line) => print($line)`
     *
     * @return array{
     *     id: string,
     *     restored_files: int,
     *     skipped_files: int,
     *     missing_files: int,
     *     pruned_files: int
     * }
     */
    public function restore(string $id, bool $prune = false, ?callable $log = null): array
    {
        $manifest = $this->layout->manifest($id);
        $report = static function (string $line) use ($log): void {
            if ($log !== null) {
                $log($line);
            }
        };

        $databaseFile = $this->layout->basePath() . '/' . $manifest['database']['file'];
        if (!is_file($databaseFile)) {
            throw new \RuntimeException('Der Datenbankabzug dieser Sicherung fehlt.');
        }
        if (hash_file('sha256', $databaseFile) !== $manifest['database']['sha256']) {
            throw new \RuntimeException('Der Datenbankabzug ist beschädigt (Prüfsumme stimmt nicht).');
        }

        $this->restoreDatabase($databaseFile);
        $report("Datenbank eingespielt: {$manifest['database']['file']}\n");

        $restored = 0;
        $skipped = 0;
        $missing = 0;
        $keep = [];

        foreach ($manifest['uploads']['files'] as $entry) {
            $keep[$entry['path']] = true;
            $target = $this->uploadPath . '/' . $entry['path'];
            $poolFile = $this->layout->poolPath($entry['sha256']);

            if (!is_file($poolFile)) {
                ++$missing;
                $report("FEHLT im Pool: {$entry['path']}\n");

                continue;
            }

            // Unveränderte Dateien nicht anfassen - das hält einen Restore auf
            // einen fast aktuellen Stand schnell.
            if (is_file($target) && hash_file('sha256', $target) === $entry['sha256']) {
                ++$skipped;

                continue;
            }

            $this->copyInto($poolFile, $target);
            ++$restored;
        }

        $pruned = $prune ? $this->pruneUnknown($keep, $report) : 0;

        return [
            'id' => $id,
            'restored_files' => $restored,
            'skipped_files' => $skipped,
            'missing_files' => $missing,
            'pruned_files' => $pruned,
        ];
    }

    /**
     * Die WAL-Dateien müssen weg: Bleiben sie liegen, spielt SQLite ihren
     * Inhalt über den frisch eingespielten Abzug und der Restore wäre still
     * wirkungslos.
     */
    private function restoreDatabase(string $source): void
    {
        $directory = dirname($this->databasePath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException("Das Datenverzeichnis konnte nicht angelegt werden: {$directory}");
        }

        $temporary = $this->databasePath . '.restore-' . bin2hex(random_bytes(8));
        if (!copy($source, $temporary)) {
            @unlink($temporary);

            throw new \RuntimeException('Der Datenbankabzug konnte nicht kopiert werden.');
        }
        if (!rename($temporary, $this->databasePath)) {
            @unlink($temporary);

            throw new \RuntimeException('Die Datenbank konnte nicht ersetzt werden.');
        }

        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
    }

    private function copyInto(string $source, string $target): void
    {
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException("Verzeichnis konnte nicht angelegt werden: {$directory}");
        }

        $temporary = $target . '.tmp-' . bin2hex(random_bytes(8));
        if (!copy($source, $temporary)) {
            @unlink($temporary);

            throw new \RuntimeException("Datei konnte nicht wiederhergestellt werden: {$target}");
        }
        @chmod($temporary, 0640);
        if (!rename($temporary, $target)) {
            @unlink($temporary);

            throw new \RuntimeException("Datei konnte nicht aktiviert werden: {$target}");
        }
    }

    /**
     * @param array<string, true> $keep
     */
    private function pruneUnknown(array $keep, callable $report): int
    {
        if (!is_dir($this->uploadPath)) {
            return 0;
        }

        $pruned = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->uploadPath, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $info */
        foreach ($iterator as $info) {
            if (!$info->isFile()) {
                continue;
            }
            $relative = substr($info->getPathname(), strlen($this->uploadPath) + 1);
            if ($relative === '' || isset($keep[$relative]) || str_contains($relative, '.tmp-')) {
                continue;
            }
            if (@unlink($info->getPathname())) {
                ++$pruned;
                $report("Entfernt (nicht im Manifest): {$relative}\n");
            }
        }

        return $pruned;
    }
}
