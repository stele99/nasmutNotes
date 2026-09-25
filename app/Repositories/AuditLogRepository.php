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

    /**
     * Gefilterte Ansicht für den Admin-Bereich, neueste zuerst. Liefert eine
     * Zeile mehr als `$limit`, damit der Aufrufer weiß, ob es weitergeht.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(
        ?int $userId,
        ?string $action,
        ?string $from,
        ?string $to,
        int $limit,
        int $offset,
    ): array {
        $where = [];
        $params = [];
        if ($userId !== null) {
            $where[] = 'audit_log.user_id = :user_id';
            $params['user_id'] = $userId;
        }
        if ($action !== null) {
            $where[] = 'audit_log.action = :action';
            $params['action'] = $action;
        }
        if ($from !== null) {
            $where[] = 'audit_log.created_at >= :from';
            $params['from'] = $from;
        }
        if ($to !== null) {
            $where[] = 'audit_log.created_at < :to';
            $params['to'] = $to;
        }

        $stmt = $this->pdo->prepare(
            'SELECT audit_log.id, audit_log.user_id, audit_log.action, audit_log.object_type,
                    audit_log.object_id, audit_log.metadata, audit_log.created_at,
                    users.email AS user_email, users.name AS user_name
               FROM audit_log
               LEFT JOIN users ON users.id = audit_log.user_id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY audit_log.created_at DESC, audit_log.id DESC
              LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $limit + 1, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return list<string> */
    public function actions(): array
    {
        $stmt = $this->pdo->query('SELECT DISTINCT action FROM audit_log ORDER BY action');

        return $stmt !== false ? array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN))) : [];
    }

    /** Entfernt Einträge, die älter als die Aufbewahrungsfrist sind. */
    public function purgeOlderThan(int $days): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM audit_log WHERE created_at < :cutoff');
        $stmt->execute(['cutoff' => gmdate('Y-m-d\TH:i:s.v\Z', time() - ($days * 86400))]);

        return $stmt->rowCount();
    }
}
