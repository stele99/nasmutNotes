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
    public function search(int $workspaceId, int $userId, string $query): array
    {
        $term = '%' . $query . '%';
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT p.id,
                    p.title,
                    p.type,
                    p.updated_at,
                    CASE WHEN p.workspace_id = :owner_workspace_id THEN 0 ELSE 1 END AS is_shared
             FROM pages p
             LEFT JOIN note_contents n ON n.page_id = p.id
             LEFT JOIN categories c ON c.page_id = p.id
             LEFT JOIN tasks t ON t.category_id = c.id
             LEFT JOIN shared_page_access spa ON spa.user_id = :user_id
             LEFT JOIN share_links sl ON sl.id = spa.share_link_id
                AND sl.page_id = p.id
                AND sl.revoked_at IS NULL
                AND (sl.expires_at IS NULL OR sl.expires_at > :now)
             WHERE p.deleted_at IS NULL
               AND (p.workspace_id = :workspace_id OR sl.id IS NOT NULL)
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
        $stmt->execute([
            'owner_workspace_id' => $workspaceId,
            'user_id' => $userId,
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'workspace_id' => $workspaceId,
            'term' => $term,
        ]);

        return $stmt->fetchAll();
    }
}
