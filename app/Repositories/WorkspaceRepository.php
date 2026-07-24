<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class WorkspaceRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function createForUser(int $userId, string $name = 'Mein Workspace'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO workspaces (user_id, name, created_at) VALUES (:user_id, :name, :now)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'name' => $name,
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findByUserId(int $userId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM workspaces WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();

        return $row !== false ? (int) $row['id'] : null;
    }
}
