<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * MCP-Token und Schreibfreigaben einzelner Seiten (FR-MCP).
 */
final class McpTokenRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(int $userId, string $label, string $tokenHash, string $writeMode, bool $canCreate): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mcp_tokens (user_id, label, token_hash, write_mode, can_create, created_at)
             VALUES (:user_id, :label, :token_hash, :write_mode, :can_create, :now)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'label' => $label,
            'token_hash' => $tokenHash,
            'write_mode' => $writeMode,
            'can_create' => $canCreate ? 1 : 0,
            'now' => self::now(),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findActiveById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mcp_tokens WHERE id = :id AND revoked_at IS NULL');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findActiveByHash(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mcp_tokens WHERE token_hash = :hash AND revoked_at IS NULL');
        $stmt->execute(['hash' => $tokenHash]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function allActiveForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM mcp_tokens WHERE user_id = :user_id AND revoked_at IS NULL
             ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    /** @param array{label?: string, write_mode?: string, can_create?: bool} $fields */
    public function update(int $id, array $fields): void
    {
        $sets = [];
        $params = ['id' => $id];
        if (array_key_exists('label', $fields)) {
            $sets[] = 'label = :label';
            $params['label'] = $fields['label'];
        }
        if (array_key_exists('write_mode', $fields)) {
            $sets[] = 'write_mode = :write_mode';
            $params['write_mode'] = $fields['write_mode'];
        }
        if (array_key_exists('can_create', $fields)) {
            $sets[] = 'can_create = :can_create';
            $params['can_create'] = $fields['can_create'] ? 1 : 0;
        }
        if ($sets === []) {
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE mcp_tokens SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    /**
     * Schreibt last_used_at höchstens einmal pro Minute - ein MCP-Client
     * schickt pro Arbeitsschritt mehrere Anfragen, und jede davon wäre sonst
     * ein Schreibzugriff auf die Datenbank.
     */
    public function touchLastUsed(int $id): void
    {
        $now = self::now();
        $stmt = $this->pdo->prepare(
            'UPDATE mcp_tokens SET last_used_at = :now
             WHERE id = :id AND (last_used_at IS NULL OR last_used_at < :threshold)'
        );
        $stmt->execute([
            'now' => $now,
            'id' => $id,
            'threshold' => gmdate('Y-m-d\TH:i:s.v\Z', time() - 60),
        ]);
    }

    public function revoke(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE mcp_tokens SET revoked_at = :now WHERE id = :id');
        $stmt->execute(['now' => self::now(), 'id' => $id]);
    }

    public function hasPageGrant(int $userId, int $pageId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM mcp_page_grants WHERE user_id = :user_id AND page_id = :page_id');
        $stmt->execute(['user_id' => $userId, 'page_id' => $pageId]);

        return $stmt->fetchColumn() !== false;
    }

    public function grantPage(int $userId, int $pageId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO mcp_page_grants (user_id, page_id, created_at) VALUES (:user_id, :page_id, :now)'
        );
        $stmt->execute(['user_id' => $userId, 'page_id' => $pageId, 'now' => self::now()]);
    }

    public function revokePageGrant(int $userId, int $pageId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM mcp_page_grants WHERE user_id = :user_id AND page_id = :page_id');
        $stmt->execute(['user_id' => $userId, 'page_id' => $pageId]);
    }

    /** Liegt die Seite in einem Notizbuch, das der Nutzer für MCP freigegeben hat? */
    public function hasNotebookGrantForPage(int $userId, int $pageId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM pages p
             JOIN mcp_notebook_grants g ON g.notebook_id = p.notebook_id AND g.user_id = :user_id
             WHERE p.id = :page_id'
        );
        $stmt->execute(['user_id' => $userId, 'page_id' => $pageId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Seiten, die für die MCP-Clients des Nutzers beschreibbar sind - direkt
     * oder über ihr Notizbuch freigegeben.
     *
     * @param list<int> $pageIds
     * @return array<int, true> Seiten-ID => freigegeben
     */
    public function grantedPageIds(int $userId, array $pageIds): array
    {
        if ($pageIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT p.id FROM pages p
             WHERE p.id IN ({$placeholders})
               AND (
                   EXISTS (SELECT 1 FROM mcp_page_grants g WHERE g.user_id = ? AND g.page_id = p.id)
                   OR EXISTS (SELECT 1 FROM mcp_notebook_grants n WHERE n.user_id = ? AND n.notebook_id = p.notebook_id)
               )"
        );
        $stmt->execute([...$pageIds, $userId, $userId]);

        $granted = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $pageId) {
            $granted[(int) $pageId] = true;
        }

        return $granted;
    }

    public function grantNotebook(int $userId, int $notebookId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO mcp_notebook_grants (user_id, notebook_id, created_at)
             VALUES (:user_id, :notebook_id, :now)'
        );
        $stmt->execute(['user_id' => $userId, 'notebook_id' => $notebookId, 'now' => self::now()]);
    }

    public function revokeNotebookGrant(int $userId, int $notebookId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM mcp_notebook_grants WHERE user_id = :user_id AND notebook_id = :notebook_id'
        );
        $stmt->execute(['user_id' => $userId, 'notebook_id' => $notebookId]);
    }

    /** @return array<int, true> Notizbuch-ID => freigegeben */
    public function grantedNotebookIds(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT notebook_id FROM mcp_notebook_grants WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);

        $granted = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $notebookId) {
            $granted[(int) $notebookId] = true;
        }

        return $granted;
    }

    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s.v\Z');
    }
}
