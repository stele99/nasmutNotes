<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class AuditLogRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $metadata */
    public function log(
        ?int $userId,
        string $action,
        ?string $objectType,
        ?int $objectId,
        ?string $ipHash,
        array $metadata = [],
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log (user_id, action, object_type, object_id, ip_hash, metadata, created_at)
             VALUES (:user_id, :action, :object_type, :object_id, :ip_hash, :metadata, :now)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'action' => $action,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'ip_hash' => $ipHash,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM audit_log ORDER BY created_at DESC LIMIT :limit');
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
