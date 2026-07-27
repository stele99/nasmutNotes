<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class NotebookRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed> */
    public function create(
        int $workspaceId,
        string $name,
        string $nameKey,
        int $sortOrder,
        string $color = '#2563eb',
        string $icon = 'book-open',
    ): array {
        $now = gmdate('Y-m-d\TH:i:s.v\Z');
        $stmt = $this->pdo->prepare(
            'INSERT INTO notebooks (workspace_id, name, name_key, sort_order, color, icon, created_at, updated_at)
             VALUES (:workspace_id, :name, :name_key, :sort_order, :color, :icon, :now, :now)'
        );
        $stmt->execute([
            'workspace_id' => $workspaceId,
            'name' => $name,
            'name_key' => $nameKey,
            'sort_order' => $sortOrder,
            'color' => $color,
            'icon' => $icon,
            'now' => $now,
        ]);

        $notebook = $this->findByIdForWorkspace((int) $this->pdo->lastInsertId(), $workspaceId);
        assert($notebook !== null);

        return $notebook;
    }

    /** @return array<string, mixed>|null */
    public function findByIdForWorkspace(int $id, int $workspaceId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT notebooks.*, COUNT(pages.id) AS page_count
             FROM notebooks
             LEFT JOIN pages ON pages.notebook_id = notebooks.id
             WHERE notebooks.id = :id AND notebooks.workspace_id = :workspace_id
             GROUP BY notebooks.id'
        );
        $stmt->execute(['id' => $id, 'workspace_id' => $workspaceId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function listForWorkspace(int $workspaceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT notebooks.*, COUNT(pages.id) AS page_count
             FROM notebooks
             LEFT JOIN pages ON pages.notebook_id = notebooks.id
             WHERE notebooks.workspace_id = :workspace_id
             GROUP BY notebooks.id
             ORDER BY notebooks.sort_order ASC, notebooks.name COLLATE NOCASE ASC, notebooks.id ASC'
        );
        $stmt->execute(['workspace_id' => $workspaceId]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findByNameKey(int $workspaceId, string $nameKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM notebooks WHERE workspace_id = :workspace_id AND name_key = :name_key');
        $stmt->execute(['workspace_id' => $workspaceId, 'name_key' => $nameKey]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /** @param array<string, mixed> $fields */
    public function updateFields(int $id, array $fields): void
    {
        $allowed = ['name', 'name_key', 'sort_order', 'color', 'icon'];
        $set = [];
        $params = ['id' => $id];
        foreach ($fields as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $set[] = "{$key} = :{$key}";
                $params[$key] = $value;
            }
        }
        if ($set === []) {
            return;
        }

        $set[] = 'updated_at = :updated_at';
        $params['updated_at'] = gmdate('Y-m-d\TH:i:s.v\Z');
        $this->pdo->prepare('UPDATE notebooks SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
    }

    public function unassignPages(int $id): void
    {
        $this->pdo->prepare('UPDATE pages SET notebook_id = NULL WHERE notebook_id = :id')->execute(['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM notebooks WHERE id = :id')->execute(['id' => $id]);
    }
}
