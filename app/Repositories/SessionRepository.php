<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SessionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(int $userId, string $tokenHash, ?string $userAgent, ?string $ipHash, string $expiresAt): int
    {
        $now = gmdate('Y-m-d\TH:i:s.v\Z');
        $stmt = $this->pdo->prepare(
            'INSERT INTO sessions (user_id, token_hash, user_agent, ip_hash, created_at, last_seen_at, expires_at)
             VALUES (:user_id, :token_hash, :user_agent, :ip_hash, :now, :now, :expires_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'user_agent' => $userAgent,
            'ip_hash' => $ipHash,
            'now' => $now,
            'expires_at' => $expiresAt,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findActiveByTokenHash(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM sessions
             WHERE token_hash = :token_hash
               AND revoked_at IS NULL
               AND expires_at > :now"
        );
        $stmt->execute([
            'token_hash' => $tokenHash,
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function touch(int $id, string $newExpiresAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sessions SET last_seen_at = :now, expires_at = :expires_at WHERE id = :id'
        );
        $stmt->execute([
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'expires_at' => $newExpiresAt,
            'id' => $id,
        ]);
    }

    public function revoke(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE sessions SET revoked_at = :now WHERE id = :id');
        $stmt->execute(['now' => gmdate('Y-m-d\TH:i:s.v\Z'), 'id' => $id]);
    }

    public function revokeAllForUser(int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sessions SET revoked_at = :now WHERE user_id = :user_id AND revoked_at IS NULL'
        );
        $stmt->execute(['now' => gmdate('Y-m-d\TH:i:s.v\Z'), 'user_id' => $userId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function activeForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM sessions
             WHERE user_id = :user_id AND revoked_at IS NULL AND expires_at > :now
             ORDER BY last_seen_at DESC"
        );
        $stmt->execute(['user_id' => $userId, 'now' => gmdate('Y-m-d\TH:i:s.v\Z')]);

        return $stmt->fetchAll();
    }
}
