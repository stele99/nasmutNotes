<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class InviteRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(
        string $tokenHash,
        ?string $email,
        ?string $note,
        int $createdBy,
        int $maxUses,
        string $expiresAt,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO invites (token_hash, email, note, created_by, max_uses, used_count, expires_at, created_at)
             VALUES (:token_hash, :email, :note, :created_by, :max_uses, 0, :expires_at, :now)'
        );
        $stmt->execute([
            'token_hash' => $tokenHash,
            'email' => $email,
            'note' => $note,
            'created_by' => $createdBy,
            'max_uses' => $maxUses,
            'expires_at' => $expiresAt,
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findByTokenHash(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM invites WHERE token_hash = :token_hash');
        $stmt->execute(['token_hash' => $tokenHash]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function incrementUse(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE invites SET used_count = used_count + 1 WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function revoke(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE invites SET revoked_at = :now WHERE id = :id');
        $stmt->execute(['now' => gmdate('Y-m-d\TH:i:s.v\Z'), 'id' => $id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $stmt = $this->pdo->query(
            "SELECT invites.*, users.email AS created_by_email
             FROM invites
             JOIN users ON users.id = invites.created_by
             ORDER BY invites.created_at DESC"
        );

        return $stmt !== false ? $stmt->fetchAll() : [];
    }

    /** @param array<string, mixed> $invite */
    public static function status(array $invite): string
    {
        if ($invite['revoked_at'] !== null) {
            return 'revoked';
        }

        if ($invite['expires_at'] < gmdate('Y-m-d\TH:i:s.v\Z')) {
            return 'expired';
        }

        if ((int) $invite['used_count'] >= (int) $invite['max_uses']) {
            return 'used';
        }

        return 'open';
    }
}
