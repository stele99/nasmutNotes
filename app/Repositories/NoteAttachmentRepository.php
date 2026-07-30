<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Notes\NoteEncryptionException;
use PDO;

final class NoteAttachmentRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed> */
    public function create(
        int $pageId,
        string $tokenHash,
        string $storageName,
        ?string $originalName,
        string $mimeType,
        int $byteSize,
        int $width,
        int $height,
        int $createdBy,
    ): array {
        $stmt = $this->pdo->prepare(
            'INSERT INTO note_attachments (
                page_id, token_hash, storage_name, original_name, mime_type,
                byte_size, width, height, created_by, created_at
             ) SELECT
                :page_id, :token_hash, :storage_name, :original_name, :mime_type,
                :byte_size, :width, :height, :created_by, :created_at
               FROM pages
              WHERE id = :page_id_guard AND is_encrypted = 0'
        );
        $stmt->execute([
            'page_id' => $pageId,
            'token_hash' => $tokenHash,
            'storage_name' => $storageName,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'byte_size' => $byteSize,
            'width' => $width,
            'height' => $height,
            'created_by' => $createdBy,
            'created_at' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'page_id_guard' => $pageId,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new NoteEncryptionException(
                'ENCRYPTION_STATE_CONFLICT',
                'Verschlüsselte Notizen können keine Bilder enthalten.',
                409,
            );
        }

        $attachment = $this->findById((int) $this->pdo->lastInsertId());
        assert($attachment !== null);

        return $attachment;
    }

    /** @return array<string, mixed>|null */
    public function findByTokenHash(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM note_attachments WHERE token_hash = :token_hash');
        $stmt->execute(['token_hash' => $tokenHash]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /** @param string[] $tokenHashes */
    public function allBelongToPage(int $pageId, array $tokenHashes): bool
    {
        $tokenHashes = array_values(array_unique($tokenHashes));
        if ($tokenHashes === []) {
            return true;
        }

        $placeholders = implode(', ', array_fill(0, count($tokenHashes), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS count
             FROM note_attachments
             WHERE page_id = ? AND token_hash IN ({$placeholders})"
        );
        $stmt->execute([$pageId, ...$tokenHashes]);

        return (int) $stmt->fetch()['count'] === count($tokenHashes);
    }

    /**
     * Bereits belegter Bildspeicher des Nutzers, dem die Seite gehört.
     * Maßgeblich ist der Eigentümer, nicht der Hochladende - eine Freigabe
     * verbraucht das Kontingent des Eigentümers (FR-ADM-06).
     */
    public function usedBytesForPageOwner(int $pageId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(note_attachments.byte_size), 0)
               FROM note_attachments
               JOIN pages ON pages.id = note_attachments.page_id
              WHERE pages.workspace_id = (SELECT workspace_id FROM pages WHERE id = :page_id)'
        );
        $stmt->execute(['page_id' => $pageId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Persönliches Kontingent des Seiteneigentümers in MB; null bedeutet, dass
     * der Standardwert gilt.
     */
    public function quotaMbForPageOwner(int $pageId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT users.storage_quota_mb
               FROM pages
               JOIN workspaces ON workspaces.id = pages.workspace_id
               JOIN users ON users.id = workspaces.user_id
              WHERE pages.id = :page_id'
        );
        $stmt->execute(['page_id' => $pageId]);
        $value = $stmt->fetchColumn();

        return $value !== false && $value !== null ? (int) $value : null;
    }

    /** @return string[] */
    public function storageNamesForPage(int $pageId): array
    {
        $stmt = $this->pdo->prepare('SELECT storage_name FROM note_attachments WHERE page_id = :page_id');
        $stmt->execute(['page_id' => $pageId]);

        return array_column($stmt->fetchAll(), 'storage_name');
    }

    /** @return list<array<string, mixed>> */
    public function listForPage(int $pageId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM note_attachments WHERE page_id = :page_id ORDER BY id ASC');
        $stmt->execute(['page_id' => $pageId]);

        return array_values($stmt->fetchAll());
    }

    /** @return list<array<string, mixed>> */
    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT note_attachments.*
               FROM note_attachments
               JOIN pages ON pages.id = note_attachments.page_id
               JOIN workspaces ON workspaces.id = pages.workspace_id
              WHERE workspaces.user_id = :user_id
                AND pages.is_encrypted = 0
           ORDER BY note_attachments.id ASC'
        );
        $stmt->execute(['user_id' => $userId]);

        return array_values($stmt->fetchAll());
    }

    public function updateImageMetadata(int $id, int $byteSize, int $width, int $height): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE note_attachments
                SET byte_size = :byte_size, width = :width, height = :height
              WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'byte_size' => $byteSize,
            'width' => $width,
            'height' => $height,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM note_attachments WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }
}
