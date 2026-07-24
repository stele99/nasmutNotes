<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ShareRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(int $pageId, string $tokenHash, string $permission): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO share_links (page_id, token_hash, permission, created_at)
             VALUES (:page_id, :token_hash, :permission, :created_at)'
        );
        $stmt->execute([
            'page_id' => $pageId,
            'token_hash' => $tokenHash,
            'permission' => $permission,
            'created_at' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findActiveByTokenHash(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT share_links.id AS share_id,
                    share_links.page_id,
                    share_links.permission,
                    share_links.expires_at,
                    share_links.created_at,
                    pages.title,
                    pages.type,
                    pages.workspace_id,
                    workspaces.user_id AS owner_id
             FROM share_links
             JOIN pages ON pages.id = share_links.page_id
             JOIN workspaces ON workspaces.id = pages.workspace_id
             WHERE share_links.token_hash = :token_hash
               AND share_links.revoked_at IS NULL
               AND pages.deleted_at IS NULL
               AND (share_links.expires_at IS NULL OR share_links.expires_at > :now)'
        );
        $stmt->execute([
            'token_hash' => $tokenHash,
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function recordAccess(int $userId, int $shareLinkId): void
    {
        $now = gmdate('Y-m-d\TH:i:s.v\Z');
        $stmt = $this->pdo->prepare(
            'INSERT INTO shared_page_access (user_id, share_link_id, created_at, last_accessed_at)
             VALUES (:user_id, :share_link_id, :created_at, :last_accessed_at)
             ON CONFLICT(user_id, share_link_id)
             DO UPDATE SET last_accessed_at = excluded.last_accessed_at'
        );
        $stmt->execute([
            'user_id' => $userId,
            'share_link_id' => $shareLinkId,
            'created_at' => $now,
            'last_accessed_at' => $now,
        ]);

        $stmt = $this->pdo->prepare(
            'UPDATE share_links
             SET last_accessed_at = :last_accessed_at,
                 access_count = access_count + 1
             WHERE id = :id'
        );
        $stmt->execute([
            'last_accessed_at' => $now,
            'id' => $shareLinkId,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function findSharedPageForUser(int $userId, int $pageId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pages.*,
                    share_links.permission AS share_permission
             FROM shared_page_access
             JOIN share_links ON share_links.id = shared_page_access.share_link_id
             JOIN pages ON pages.id = share_links.page_id
             WHERE shared_page_access.user_id = :user_id
               AND pages.id = :page_id
               AND pages.deleted_at IS NULL
               AND share_links.revoked_at IS NULL
               AND (share_links.expires_at IS NULL OR share_links.expires_at > :now)
             ORDER BY CASE WHEN share_links.permission = \'write\' THEN 0 ELSE 1 END,
                      share_links.created_at DESC
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'page_id' => $pageId,
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function listForUser(int $userId, ?string $typeFilter = null): array
    {
        $sql =
            'SELECT pages.*,
                    CASE WHEN MAX(CASE WHEN share_links.permission = \'write\' THEN 1 ELSE 0 END) = 1
                         THEN \'write\' ELSE \'read\' END AS share_permission
             FROM shared_page_access
             JOIN share_links ON share_links.id = shared_page_access.share_link_id
             JOIN pages ON pages.id = share_links.page_id
             WHERE shared_page_access.user_id = :user_id
               AND pages.deleted_at IS NULL
               AND share_links.revoked_at IS NULL
               AND (share_links.expires_at IS NULL OR share_links.expires_at > :now)';
        $params = [
            'user_id' => $userId,
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ];

        if ($typeFilter !== null) {
            $sql .= ' AND pages.type = :type';
            $params['type'] = $typeFilter;
        }

        $sql .= ' GROUP BY pages.id ORDER BY pages.updated_at DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM share_links WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function listForPage(int $pageId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, page_id, permission, expires_at, revoked_at, last_accessed_at, access_count, created_at
             FROM share_links
             WHERE page_id = :page_id AND revoked_at IS NULL
             ORDER BY created_at DESC'
        );
        $stmt->execute(['page_id' => $pageId]);

        return $stmt->fetchAll();
    }

    public function revoke(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE share_links SET revoked_at = :revoked_at WHERE id = :id AND revoked_at IS NULL'
        );
        $stmt->execute([
            'revoked_at' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'id' => $id,
        ]);
    }

    public function leavePage(int $userId, int $pageId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM shared_page_access
             WHERE user_id = :user_id
               AND share_link_id IN (
                   SELECT id FROM share_links WHERE page_id = :page_id
               )'
        );
        $stmt->execute([
            'user_id' => $userId,
            'page_id' => $pageId,
        ]);
    }
}
