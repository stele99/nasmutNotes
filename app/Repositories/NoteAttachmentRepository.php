<?php

declare(strict_types=1);

namespace App\Repositories;

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
             ) VALUES (
                :page_id, :token_hash, :storage_name, :original_name, :mime_type,
                :byte_size, :width, :height, :created_by, :created_at
             )'
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
        ]);

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

    /** @return string[] */
    public function storageNamesForPage(int $pageId): array
    {
        $stmt = $this->pdo->prepare('SELECT storage_name FROM note_attachments WHERE page_id = :page_id');
        $stmt->execute(['page_id' => $pageId]);

        return array_column($stmt->fetchAll(), 'storage_name');
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
