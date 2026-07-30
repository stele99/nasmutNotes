<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class NoteVersionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(int $pageId, string $content, ?int $createdBy): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO note_versions (page_id, content, created_at, created_by)
             VALUES (:page_id, :content, :created_at, :created_by)'
        );
        $stmt->execute([
            'page_id' => $pageId,
            'content' => $content,
            'created_at' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'created_by' => $createdBy,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForPage(int $pageId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT note_versions.id,
                    note_versions.page_id,
                    note_versions.content,
                    note_versions.created_at,
                    note_versions.created_by,
                    users.name AS created_by_name
               FROM note_versions
               LEFT JOIN users ON users.id = note_versions.created_by
              WHERE note_versions.page_id = :page_id
              ORDER BY note_versions.created_at DESC, note_versions.id DESC'
        );
        $stmt->execute(['page_id' => $pageId]);

        return $stmt->fetchAll() ?: [];
    }

    /** @return array<string, mixed>|null */
    public function findForPage(int $pageId, int $versionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT note_versions.id,
                    note_versions.page_id,
                    note_versions.content,
                    note_versions.created_at,
                    note_versions.created_by,
                    users.name AS created_by_name
               FROM note_versions
               LEFT JOIN users ON users.id = note_versions.created_by
              WHERE note_versions.page_id = :page_id
                AND note_versions.id = :id'
        );
        $stmt->execute([
            'page_id' => $pageId,
            'id' => $versionId,
        ]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function prune(int $pageId, int $keep): void
    {
        if ($keep < 1) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'DELETE FROM note_versions
              WHERE page_id = :page_id
                AND id NOT IN (
                    SELECT id FROM (
                        SELECT id
                          FROM note_versions
                         WHERE page_id = :page_id_inner
                         ORDER BY created_at DESC, id DESC
                         LIMIT :keep
                    )
                )'
        );
        $stmt->bindValue('page_id', $pageId, PDO::PARAM_INT);
        $stmt->bindValue('page_id_inner', $pageId, PDO::PARAM_INT);
        $stmt->bindValue('keep', $keep, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function deleteForPage(int $pageId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM note_versions WHERE page_id = :page_id');
        $stmt->execute(['page_id' => $pageId]);
    }
}
