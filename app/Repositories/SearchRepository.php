<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SearchRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function search(int $workspaceId, string $query): array
    {
        $term = '%' . $query . '%';
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT p.id, p.title, p.type, p.updated_at
             FROM pages p
             LEFT JOIN note_contents n ON n.page_id = p.id
             LEFT JOIN categories c ON c.page_id = p.id
             LEFT JOIN tasks t ON t.category_id = c.id
             WHERE p.workspace_id = :workspace_id
               AND p.deleted_at IS NULL
               AND (
                   p.title LIKE :term COLLATE NOCASE
                   OR n.content_text LIKE :term COLLATE NOCASE
                   OR c.name LIKE :term COLLATE NOCASE
                   OR t.title LIKE :term COLLATE NOCASE
                   OR t.description LIKE :term COLLATE NOCASE
               )
             ORDER BY p.updated_at DESC
             LIMIT 20'
        );
        $stmt->execute(['workspace_id' => $workspaceId, 'term' => $term]);

        return $stmt->fetchAll();
    }
}
