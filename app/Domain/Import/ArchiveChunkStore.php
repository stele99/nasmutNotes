<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Support\NotFoundException;
use App\Support\ValidationException;

/**
 * Zwischenspeicher für Archive, die in Teilen hochgeladen werden (FR-IMP-25).
 *
 * Jede Anfrage bleibt damit klein genug für `upload_max_filesize` und
 * `post_max_size`; das Archiv wächst serverseitig aus den Teilen zusammen.
 * Die Teile liegen außerhalb des Web-Roots, jede Sitzung gehört genau einem
 * Nutzer und läuft nach wenigen Stunden ab.
 */
final class ArchiveChunkStore
{
    private const ID_PATTERN = '/^[a-f0-9]{32}$/';

    /** Angefangene Uploads, die niemand abschließt, sollen nicht liegen bleiben. */
    private const TTL_SECONDS = 6 * 3600;

    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * Beginnt eine Upload-Sitzung und liefert deren Kennung.
     */
    public function begin(int $userId, string $fileName, int $totalBytes, int $maxBytes): string
    {
        if ($totalBytes < 1 || $totalBytes > $maxBytes) {
            throw new ValidationException(
                'Das Archiv überschreitet die zulässige Größe von ' . $this->megabytes($maxBytes) . ' MB.'
            );
        }

        $this->sweep();
        $this->ensureDirectory();

        $id = bin2hex(random_bytes(16));
        file_put_contents($this->partPath($id), '', LOCK_EX);
        $this->writeMeta($id, [
            'user_id' => $userId,
            'file_name' => mb_substr(basename(str_replace("\0", '', $fileName)), 0, 255),
            'total_bytes' => $totalBytes,
            'received_bytes' => 0,
            'next_index' => 0,
            'created_at' => time(),
        ]);

        return $id;
    }

    /**
     * Hängt den nächsten Teil an. Die Teile müssen in der Reihenfolge kommen,
     * in der sie geschnitten wurden — sonst ergäbe sich stillschweigend ein
     * beschädigtes Archiv.
     *
     * @return array{received_bytes: int, total_bytes: int, next_index: int, complete: bool}
     */
    public function append(int $userId, string $id, int $index, string $bytes): array
    {
        $meta = $this->readMeta($userId, $id);

        if ($index !== $meta['next_index']) {
            throw new ValidationException(
                "Teil {$index} kam außer der Reihe an (erwartet: {$meta['next_index']})."
            );
        }
        if ($bytes === '') {
            throw new ValidationException('Der übertragene Teil ist leer.');
        }

        $received = $meta['received_bytes'] + strlen($bytes);
        if ($received > $meta['total_bytes']) {
            $this->discard($id);

            throw new ValidationException('Es wurden mehr Daten übertragen als angekündigt.');
        }

        if (file_put_contents($this->partPath($id), $bytes, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Der Teil konnte nicht zwischengespeichert werden.');
        }

        $meta['received_bytes'] = $received;
        $meta['next_index'] = $index + 1;
        $this->writeMeta($id, $meta);

        return [
            'received_bytes' => $received,
            'total_bytes' => $meta['total_bytes'],
            'next_index' => $meta['next_index'],
            'complete' => $received === $meta['total_bytes'],
        ];
    }

    /**
     * Pfad des vollständig übertragenen Archivs.
     *
     * @return array{path: string, file_name: string, size: int}
     */
    public function finish(int $userId, string $id): array
    {
        $meta = $this->readMeta($userId, $id);
        if ($meta['received_bytes'] !== $meta['total_bytes']) {
            throw new ValidationException(
                "Der Upload ist unvollständig ({$meta['received_bytes']} von {$meta['total_bytes']} Bytes)."
            );
        }

        return [
            'path' => $this->partPath($id),
            'file_name' => $meta['file_name'],
            'size' => $meta['total_bytes'],
        ];
    }

    /**
     * Bricht eine Sitzung ab. Unbekannte oder fremde Kennungen bleiben
     * folgenlos — ein Abbruch soll nichts über andere Uploads verraten.
     */
    public function abandon(int $userId, string $id): void
    {
        try {
            $this->readMeta($userId, $id);
        } catch (NotFoundException) {
            return;
        }

        $this->discard($id);
    }

    public function discard(string $id): void
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return;
        }
        @unlink($this->partPath($id));
        @unlink($this->metaPath($id));
    }

    /** Entfernt abgelaufene Sitzungen; gibt die Zahl der gelöschten zurück. */
    public function sweep(): int
    {
        $removed = 0;
        foreach (glob($this->basePath . '/*.json') ?: [] as $metaFile) {
            $raw = @file_get_contents($metaFile);
            $decoded = $raw !== false ? json_decode($raw, true) : null;
            $createdAt = is_array($decoded) ? (int) ($decoded['created_at'] ?? 0) : 0;
            if (time() - $createdAt > self::TTL_SECONDS) {
                $this->discard(basename($metaFile, '.json'));
                ++$removed;
            }
        }

        return $removed;
    }

    /** @return array{user_id: int, file_name: string, total_bytes: int, received_bytes: int, next_index: int, created_at: int} */
    private function readMeta(int $userId, string $id): array
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new NotFoundException('Unbekannter Upload.');
        }

        $raw = @file_get_contents($this->metaPath($id));
        $decoded = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            throw new NotFoundException('Der Upload ist abgelaufen oder unbekannt.');
        }

        // Der Besitz hängt an der Sitzung, nicht an der Kennung: Wer sie errät,
        // kommt trotzdem nicht an fremde Teile.
        if ((int) ($decoded['user_id'] ?? 0) !== $userId) {
            throw new NotFoundException('Der Upload ist abgelaufen oder unbekannt.');
        }

        return [
            'user_id' => (int) $decoded['user_id'],
            'file_name' => (string) ($decoded['file_name'] ?? 'import.zip'),
            'total_bytes' => (int) ($decoded['total_bytes'] ?? 0),
            'received_bytes' => (int) ($decoded['received_bytes'] ?? 0),
            'next_index' => (int) ($decoded['next_index'] ?? 0),
            'created_at' => (int) ($decoded['created_at'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $meta */
    private function writeMeta(string $id, array $meta): void
    {
        $encoded = json_encode($meta, JSON_THROW_ON_ERROR);
        if (file_put_contents($this->metaPath($id), $encoded, LOCK_EX) === false) {
            throw new \RuntimeException('Der Upload-Zustand konnte nicht gespeichert werden.');
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->basePath) && !mkdir($this->basePath, 0750, true) && !is_dir($this->basePath)) {
            throw new \RuntimeException('Das Verzeichnis für Upload-Teile konnte nicht angelegt werden.');
        }
    }

    private function partPath(string $id): string
    {
        return $this->basePath . '/' . $id . '.part';
    }

    private function metaPath(string $id): string
    {
        return $this->basePath . '/' . $id . '.json';
    }

    private function megabytes(int $bytes): int
    {
        return (int) max(1, round($bytes / (1024 * 1024)));
    }
}
