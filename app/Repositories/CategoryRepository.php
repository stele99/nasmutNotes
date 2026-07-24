<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class CategoryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function listForPage(int $pageId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM categories WHERE page_id = :page_id ORDER BY position ASC');
        $stmt->execute(['page_id' => $pageId]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findByIdForPage(int $id, int $pageId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM categories WHERE id = :id AND page_id = :page_id');
        $stmt->execute(['id' => $id, 'page_id' => $pageId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /** @return array<string, mixed> */
    public function create(int $pageId, string $name, ?string $color, ?int $wipLimit): array
    {
        $position = $this->nextPosition($pageId);
        $stmt = $this->pdo->prepare(
            'INSERT INTO categories (page_id, name, color, position, wip_limit, created_at)
             VALUES (:page_id, :name, :color, :position, :wip_limit, :now)'
        );
        $stmt->execute([
            'page_id' => $pageId,
            'name' => $name,
            'color' => $color,
            'position' => $position,
            'wip_limit' => $wipLimit,
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $category = $this->findByIdForPage($id, $pageId);
        assert($category !== null);

        return $category;
    }

    /** @param array<string, mixed> $fields */
    public function update(int $id, array $fields): void
    {
        $allowed = ['name', 'color', 'position', 'wip_limit'];
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

        $sql = 'UPDATE categories SET ' . implode(', ', $set) . ' WHERE id = :id';
        $this->pdo->prepare($sql)->execute($params);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM categories WHERE id = :id')->execute(['id' => $id]);
    }

    public function nextPosition(int $pageId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 AS next FROM categories WHERE page_id = :page_id');
        $stmt->execute(['page_id' => $pageId]);

        return (int) $stmt->fetch()['next'];
    }
}
