<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class PageRepository
{
    private const SORTS = [
        'updated' => 'updated_at DESC',
        'title' => 'title COLLATE NOCASE ASC',
        'created' => 'created_at DESC',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed> */
    public function create(int $workspaceId, string $type, string $title, ?string $icon): array
    {
        $now = gmdate('Y-m-d\TH:i:s.v\Z');
        $stmt = $this->pdo->prepare(
            'INSERT INTO pages (workspace_id, type, title, icon, created_at, updated_at)
             VALUES (:workspace_id, :type, :title, :icon, :now, :now)'
        );
        $stmt->execute([
            'workspace_id' => $workspaceId,
            'type' => $type,
            'title' => $title,
            'icon' => $icon,
            'now' => $now,
        ]);

        $id = (int) $this->pdo->lastInsertId();

        if ($type === 'note') {
            $this->pdo->prepare(
                'INSERT INTO note_contents (page_id, updated_at) VALUES (:id, :now)'
            )->execute(['id' => $id, 'now' => $now]);
        } elseif ($type === 'task') {
            $this->createDefaultCategories($id);
        }

        $page = $this->findByIdForWorkspace($id, $workspaceId);
        assert($page !== null);

        return $page;
    }

    private function createDefaultCategories(int $pageId): void
    {
        $now = gmdate('Y-m-d\TH:i:s.v\Z');
        $stmt = $this->pdo->prepare(
            'INSERT INTO categories (page_id, name, position, created_at) VALUES (:page_id, :name, :position, :now)'
        );
        foreach (['Offen', 'In Arbeit', 'Erledigt'] as $position => $name) {
            $stmt->execute(['page_id' => $pageId, 'name' => $name, 'position' => $position, 'now' => $now]);
        }
    }

    /** @return array<string, mixed>|null */
    public function findByIdForWorkspace(int $id, int $workspaceId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pages WHERE id = :id AND workspace_id = :workspace_id');
        $stmt->execute(['id' => $id, 'workspace_id' => $workspaceId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForWorkspace(
        int $workspaceId,
        string $sort = 'updated',
        ?string $typeFilter = null,
        bool $includeTrashed = false,
    ): array {
        $orderBy = self::SORTS[$sort] ?? self::SORTS['updated'];

        $sql = 'SELECT * FROM pages WHERE workspace_id = :workspace_id';
        $params = ['workspace_id' => $workspaceId];

        $sql .= $includeTrashed ? ' AND deleted_at IS NOT NULL' : ' AND deleted_at IS NULL';

        if ($typeFilter !== null) {
            $sql .= ' AND type = :type';
            $params['type'] = $typeFilter;
        }

        $sql .= ' ORDER BY is_favorite DESC, ' . $orderBy;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @param array<string, mixed> $fields */
    public function updateFields(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $allowed = ['title', 'icon', 'is_favorite', 'sort_order', 'default_view'];
        $set = [];
        $params = ['id' => $id];

        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $set[] = "{$key} = :{$key}";
            $params[$key] = $value;
        }

        if ($set === []) {
            return;
        }

        $set[] = 'updated_at = :updated_at';
        $params['updated_at'] = gmdate('Y-m-d\TH:i:s.v\Z');

        $sql = 'UPDATE pages SET ' . implode(', ', $set) . ' WHERE id = :id';
        $this->pdo->prepare($sql)->execute($params);
    }

    public function touchUpdatedAt(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE pages SET updated_at = :now WHERE id = :id');
        $stmt->execute(['now' => gmdate('Y-m-d\TH:i:s.v\Z'), 'id' => $id]);
    }

    public function softDelete(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE pages SET deleted_at = :now WHERE id = :id');
        $stmt->execute(['now' => gmdate('Y-m-d\TH:i:s.v\Z'), 'id' => $id]);
    }

    public function restore(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE pages SET deleted_at = NULL WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function purge(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM pages WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function purgeExpiredTrash(int $retentionDays): array
    {
        $threshold = gmdate('Y-m-d\TH:i:s.v\Z', time() - ($retentionDays * 86400));

        $stmt = $this->pdo->prepare('SELECT id FROM pages WHERE deleted_at IS NOT NULL AND deleted_at < :threshold');
        $stmt->execute(['threshold' => $threshold]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $this->purge((int) $row['id']);
        }

        return $rows;
    }
}
