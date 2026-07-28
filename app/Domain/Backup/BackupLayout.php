<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Support\NotFoundException;

/**
 * Verzeichnisaufbau einer Sicherung (NFR-OPS-06).
 *
 * ```
 * pool/ab/ab34…      Inhaltsadressierte Kopie jeder je gesicherten Upload-Datei
 * snapshots/<id>.json Ein Manifest je Lauf - beschreibt den vollständigen Stand
 * db/<id>.sqlite      VACUUM-INTO-Abzug je Lauf
 * tmp/                Zusammengebaute Download-Archive, flüchtig
 * ```
 *
 * Bewusst ohne Datenbankzugriff: Der Restore ersetzt die laufende Datenbank und
 * darf deshalb keine offene Verbindung dorthin brauchen.
 *
 * @phpstan-type UploadEntry array{path: string, bytes: int, mtime: int, sha256: string}
 * @phpstan-type Manifest array{
 *     id: string,
 *     created_at: string,
 *     migrations: int,
 *     database: array{file: string, bytes: int, sha256: string},
 *     tables: array<string, int>,
 *     uploads: array{count: int, bytes: int, files: array<int, UploadEntry>}
 * }
 */
final class BackupLayout
{
    /** Kennung eines Laufs: `2026-07-28-030000`. Streng geprüft - sie landet in Dateipfaden. */
    public const ID_PATTERN = '/^\d{4}-\d{2}-\d{2}-\d{6}$/';

    public function __construct(private readonly string $basePath)
    {
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function snapshotPath(string $id): string
    {
        return $this->basePath . '/snapshots/' . $this->assertId($id) . '.json';
    }

    public function databasePath(string $id): string
    {
        return $this->basePath . '/db/' . $this->assertId($id) . '.sqlite';
    }

    /**
     * Der Pool ist inhaltsadressiert: Ein nachträglich komprimiertes Bild
     * (UploadStorage::replace) bekommt eine neue Prüfsumme und damit einen
     * neuen Eintrag - ältere Snapshots behalten so ihren echten Stand.
     */
    public function poolPath(string $sha256): string
    {
        return $this->basePath . '/pool/' . substr($sha256, 0, 2) . '/' . $sha256;
    }

    public function tmpPath(): string
    {
        return $this->basePath . '/tmp';
    }

    /**
     * Vorhandene Läufe, neueste zuerst.
     *
     * @return array<int, string>
     */
    public function ids(): array
    {
        $ids = [];
        foreach (glob($this->basePath . '/snapshots/*.json') ?: [] as $file) {
            $id = basename($file, '.json');
            if (preg_match(self::ID_PATTERN, $id) === 1) {
                $ids[] = $id;
            }
        }
        rsort($ids);

        return $ids;
    }

    public function exists(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1 && is_file($this->snapshotPath($id));
    }

    /**
     * @return Manifest
     */
    public function manifest(string $id): array
    {
        $raw = @file_get_contents($this->snapshotPath($id));
        $decoded = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            throw new NotFoundException('Die Sicherung ist unbekannt oder beschädigt.');
        }

        return $this->normalize($decoded, $id);
    }

    public function ensureDirectories(): void
    {
        foreach (['snapshots', 'db', 'pool', 'tmp'] as $directory) {
            $path = $this->basePath . '/' . $directory;
            if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
                throw new \RuntimeException("Das Sicherungsverzeichnis konnte nicht angelegt werden: {$path}");
            }
        }
    }

    public function assertId(string $id): string
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new NotFoundException('Unbekannte Sicherung.');
        }

        return $id;
    }

    /**
     * Schreibt eine Datei erst vollständig und schiebt sie dann an ihren Platz -
     * ein abgebrochener Lauf hinterlässt so kein halbes Manifest.
     */
    public function writeAtomic(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException("Verzeichnis konnte nicht angelegt werden: {$directory}");
        }

        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
            @unlink($temporary);

            throw new \RuntimeException("Datei konnte nicht geschrieben werden: {$path}");
        }
        @chmod($temporary, 0640);
        if (!rename($temporary, $path)) {
            @unlink($temporary);

            throw new \RuntimeException("Datei konnte nicht aktiviert werden: {$path}");
        }
    }

    /**
     * @param array<mixed> $decoded
     *
     * @return Manifest
     */
    private function normalize(array $decoded, string $id): array
    {
        $database = is_array($decoded['database'] ?? null) ? $decoded['database'] : [];
        $uploads = is_array($decoded['uploads'] ?? null) ? $decoded['uploads'] : [];

        $files = [];
        foreach (is_array($uploads['files'] ?? null) ? $uploads['files'] : [] as $entry) {
            if (!is_array($entry) || !is_string($entry['path'] ?? null) || !is_string($entry['sha256'] ?? null)) {
                continue;
            }
            $files[] = [
                'path' => (string) $entry['path'],
                'bytes' => (int) ($entry['bytes'] ?? 0),
                'mtime' => (int) ($entry['mtime'] ?? 0),
                'sha256' => (string) $entry['sha256'],
            ];
        }

        $tables = [];
        foreach (is_array($decoded['tables'] ?? null) ? $decoded['tables'] : [] as $name => $count) {
            $tables[(string) $name] = (int) $count;
        }

        return [
            'id' => is_string($decoded['id'] ?? null) ? $decoded['id'] : $id,
            'created_at' => is_string($decoded['created_at'] ?? null) ? $decoded['created_at'] : '',
            'migrations' => (int) ($decoded['migrations'] ?? 0),
            'database' => [
                'file' => is_string($database['file'] ?? null) ? $database['file'] : '',
                'bytes' => (int) ($database['bytes'] ?? 0),
                'sha256' => is_string($database['sha256'] ?? null) ? $database['sha256'] : '',
            ],
            'tables' => $tables,
            'uploads' => [
                'count' => (int) ($uploads['count'] ?? count($files)),
                'bytes' => (int) ($uploads['bytes'] ?? 0),
                'files' => $files,
            ],
        ];
    }
}
