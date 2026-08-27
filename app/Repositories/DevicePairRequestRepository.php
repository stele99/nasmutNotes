<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Laufende Pairing-Sitzungen des Desktop-Assistant. Codes liegen nur gehasht
 * ab; die Zeile trägt nach der Bestätigung die Nutzer-ID, nach der Auslieferung
 * die ID des erzeugten Tokens. Verfallene Zeilen werden lazy aufgeräumt.
 */
final class DevicePairRequestRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(
        string $userCodeHash,
        string $deviceCodeHash,
        string $clientId,
        string $label,
        ?string $platform,
        string $createdAt,
        string $expiresAt,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO device_pair_requests
                (user_code_hash, device_code_hash, client_id, label, platform, created_at, expires_at)
             VALUES (:user_code, :device_code, :client_id, :label, :platform, :created_at, :expires_at)'
        );
        $stmt->execute([
            'user_code' => $userCodeHash,
            'device_code' => $deviceCodeHash,
            'client_id' => $clientId,
            'label' => $label,
            'platform' => $platform,
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findByUserCodeHash(string $hash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM device_pair_requests WHERE user_code_hash = :hash');
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findByDeviceCodeHash(string $hash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM device_pair_requests WHERE device_code_hash = :hash');
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function markApproved(int $id, int $userId, string $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE device_pair_requests
             SET approved_user_id = :user_id, approved_at = :now
             WHERE id = :id'
        );
        $stmt->execute(['user_id' => $userId, 'now' => $now, 'id' => $id]);
    }

    public function markConsumed(int $id, ?int $tokenId, string $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE device_pair_requests SET consumed_at = :now, token_id = :token_id WHERE id = :id'
        );
        $stmt->execute(['now' => $now, 'token_id' => $tokenId, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM device_pair_requests WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function deleteExpired(string $now): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM device_pair_requests WHERE expires_at < :now');
        $stmt->execute(['now' => $now]);
    }

    public function deleteForClient(string $clientId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM device_pair_requests WHERE client_id = :client_id');
        $stmt->execute(['client_id' => $clientId]);
    }
}
