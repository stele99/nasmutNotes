<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class DeviceTokenRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(int $userId, string $label, string $tokenHash): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO device_tokens (user_id, label, token_hash, created_at)
             VALUES (:user_id, :label, :token_hash, :now)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'label' => $label,
            'token_hash' => $tokenHash,
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM device_tokens WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findActiveByHash(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM device_tokens WHERE token_hash = :hash AND revoked_at IS NULL'
        );
        $stmt->execute(['hash' => $tokenHash]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function allActiveForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM device_tokens WHERE user_id = :user_id AND revoked_at IS NULL
             ORDER BY created_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public function touchLastUsed(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE device_tokens SET last_used_at = :now WHERE id = :id');
        $stmt->execute(['now' => gmdate('Y-m-d\TH:i:s.v\Z'), 'id' => $id]);
    }

    public function revoke(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE device_tokens SET revoked_at = :now WHERE id = :id');
        $stmt->execute(['now' => gmdate('Y-m-d\TH:i:s.v\Z'), 'id' => $id]);
    }
}
