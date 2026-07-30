<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class NoteContentRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $pageId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT note_contents.*, pages.is_encrypted, users.name AS last_editor_name
             FROM note_contents
             JOIN pages ON pages.id = note_contents.page_id
             LEFT JOIN users ON users.id = note_contents.updated_by
             WHERE note_contents.page_id = :page_id'
        );
        $stmt->execute(['page_id' => $pageId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Speichert nur, wenn die übergebene Version noch dem Serverstand entspricht
     * (optimistische Sperre, Kap. 10.4). Gibt true bei Erfolg zurück.
     */
    public function saveIfVersionMatches(
        int $pageId,
        string $content,
        string $contentText,
        int $expectedVersion,
        int $updatedBy,
        string $updatedAt,
    ): bool {
        $stmt = $this->pdo->prepare(
            'UPDATE note_contents
              SET content = :content, content_text = :content_text, version = version + 1, updated_at = :now, updated_by = :updated_by
             WHERE page_id = :page_id AND version = :expected_version'
        );
        $stmt->execute([
            'content' => $content,
            'content_text' => $contentText,
            'now' => $updatedAt,
            'page_id' => $pageId,
            'expected_version' => $expectedVersion,
            'updated_by' => $updatedBy,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function replaceForCopy(int $pageId, string $content, string $contentText, int $updatedBy): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE note_contents
                SET content = :content, content_text = :content_text, version = 1,
                    updated_at = :now, updated_by = :updated_by
              WHERE page_id = :page_id'
        );
        $stmt->execute([
            'page_id' => $pageId,
            'content' => $content,
            'content_text' => $contentText,
            'updated_by' => $updatedBy,
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
    }

}
