<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class TaskRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function listForCategory(int $categoryId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tasks WHERE category_id = :category_id ORDER BY position ASC');
        $stmt->execute(['category_id' => $categoryId]);

        return $stmt->fetchAll();
    }

    /**
     * Alle Tasks einer Seite in einer Abfrage, gruppiert nach Kategorie.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForPage(int $pageId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT tasks.* FROM tasks
             JOIN categories ON categories.id = tasks.category_id
             WHERE categories.page_id = :page_id
             ORDER BY tasks.category_id ASC, tasks.position ASC'
        );
        $stmt->execute(['page_id' => $pageId]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findByIdForPage(int $id, int $pageId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT tasks.* FROM tasks
             JOIN categories ON categories.id = tasks.category_id
             WHERE tasks.id = :id AND categories.page_id = :page_id'
        );
        $stmt->execute(['id' => $id, 'page_id' => $pageId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function categoryIdOf(int $taskId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT category_id FROM tasks WHERE id = :id');
        $stmt->execute(['id' => $taskId]);
        $row = $stmt->fetch();

        return $row !== false ? (int) $row['category_id'] : null;
    }

    /** @return array<string, mixed> */
    public function create(
        int $categoryId,
        string $title,
        ?string $description,
        ?string $responsible,
        ?string $link,
        bool $isDone = false,
    ): array {
        $position = $this->nextPosition($categoryId);
        $now = gmdate('Y-m-d\TH:i:s.v\Z');

        $stmt = $this->pdo->prepare(
            'INSERT INTO tasks (category_id, title, description, responsible, link, position, is_done, created_at, updated_at)
             VALUES (:category_id, :title, :description, :responsible, :link, :position, :is_done, :now, :now)'
        );
        $stmt->execute([
            'category_id' => $categoryId,
            'title' => $title,
            'description' => $description,
            'responsible' => $responsible,
            'link' => $link,
            'position' => $position,
            'is_done' => $isDone ? 1 : 0,
            'now' => $now,
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $task = $this->find($id);
        assert($task !== null);

        return $task;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tasks WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /** @param array<string, mixed> $fields */
    public function update(int $id, array $fields): void
    {
        $allowed = ['title', 'description', 'responsible', 'link', 'is_done', 'due_date', 'priority'];
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

        $sql = 'UPDATE tasks SET ' . implode(', ', $set) . ' WHERE id = :id';
        $this->pdo->prepare($sql)->execute($params);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM tasks WHERE id = :id')->execute(['id' => $id]);
    }

    public function moveAllToCategory(int $fromCategoryId, int $toCategoryId): void
    {
        $position = $this->nextPosition($toCategoryId);

        $stmt = $this->pdo->prepare('SELECT id FROM tasks WHERE category_id = :from ORDER BY position ASC');
        $stmt->execute(['from' => $fromCategoryId]);
        $ids = array_column($stmt->fetchAll(), 'id');

        $update = $this->pdo->prepare(
            'UPDATE tasks SET category_id = :to, position = :position WHERE id = :id'
        );
        foreach ($ids as $id) {
            $update->execute(['to' => $toCategoryId, 'position' => $position, 'id' => $id]);
            $position++;
        }
    }

    public function deleteAllInCategory(int $categoryId): void
    {
        $this->pdo->prepare('DELETE FROM tasks WHERE category_id = :category_id')->execute(['category_id' => $categoryId]);
    }

    public function countForCategory(int $categoryId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM tasks WHERE category_id = :category_id');
        $stmt->execute(['category_id' => $categoryId]);

        return (int) $stmt->fetch()['c'];
    }

    /** @param int[] $orderedTaskIds */
    public function renumberCategory(int $categoryId, array $orderedTaskIds): void
    {
        $stmt = $this->pdo->prepare('UPDATE tasks SET position = :position WHERE id = :id AND category_id = :category_id');
        foreach (array_values($orderedTaskIds) as $position => $taskId) {
            $stmt->execute(['position' => $position, 'id' => $taskId, 'category_id' => $categoryId]);
        }
    }

    public function setCategory(int $taskId, int $categoryId): void
    {
        $stmt = $this->pdo->prepare('UPDATE tasks SET category_id = :category_id WHERE id = :id');
        $stmt->execute(['category_id' => $categoryId, 'id' => $taskId]);
    }

    private function nextPosition(int $categoryId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 AS next FROM tasks WHERE category_id = :category_id');
        $stmt->execute(['category_id' => $categoryId]);

        return (int) $stmt->fetch()['next'];
    }
}
