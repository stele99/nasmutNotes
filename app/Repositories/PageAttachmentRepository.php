<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class PageAttachmentRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed> */
    public function create(
        int $pageId,
        string $tokenHash,
        string $storageName,
        string $originalName,
        string $mimeType,
        int $byteSize,
        ?int $createdBy,
    ): array {
        $stmt = $this->pdo->prepare(
            'INSERT INTO page_attachments
                (page_id, token_hash, storage_name, original_name, mime_type, byte_size, created_by, created_at)
             VALUES (:page_id, :token_hash, :storage_name, :original_name, :mime_type, :byte_size, :created_by, :now)'
        );
        $stmt->execute([
            'page_id' => $pageId,
            'token_hash' => $tokenHash,
            'storage_name' => $storageName,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'byte_size' => $byteSize,
            'created_by' => $createdBy,
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $row = $this->findById($id);
        if ($row === null) {
            throw new \RuntimeException('Der Anhang konnte nicht gelesen werden.');
        }

        return $row;
    }

    /** @return array<int, array<string, mixed>> */
    public function listForPage(int $pageId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT page_attachments.*, users.name AS created_by_name
               FROM page_attachments
               LEFT JOIN users ON users.id = page_attachments.created_by
              WHERE page_attachments.page_id = :page_id
              ORDER BY page_attachments.created_at ASC, page_attachments.id ASC'
        );
        $stmt->execute(['page_id' => $pageId]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM page_attachments WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findByTokenHash(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM page_attachments WHERE token_hash = :token_hash');
        $stmt->execute(['token_hash' => $tokenHash]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM page_attachments WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /** Belegter Anhangspeicher des Nutzers, dem die Seite gehört. */
    public function usedBytesForPageOwner(int $pageId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(page_attachments.byte_size), 0)
               FROM page_attachments
               JOIN pages ON pages.id = page_attachments.page_id
              WHERE pages.workspace_id = (SELECT workspace_id FROM pages WHERE id = :page_id)'
        );
        $stmt->execute(['page_id' => $pageId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return string[] */
    public function storageNamesForPage(int $pageId): array
    {
        $stmt = $this->pdo->prepare('SELECT storage_name FROM page_attachments WHERE page_id = :page_id');
        $stmt->execute(['page_id' => $pageId]);

        return array_map(
            static fn (array $row): string => (string) $row['storage_name'],
            $stmt->fetchAll(),
        );
    }
}
