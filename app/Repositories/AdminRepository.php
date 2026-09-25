<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Auswertungen für das Admin-Dashboard (FR-ADM-01..04). Bewusst lesend und in
 * Sammelabfragen gehalten - die Zahlen sollen den laufenden Betrieb nicht
 * belasten.
 */
final class AdminRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Alle Nutzer mit Seiten-, Aufgaben- und Speicherzahlen. `attachment_bytes`
     * ist die Summe der hochgeladenen Bilder, `content_bytes` die Größe des
     * gespeicherten Notiz-JSON inklusive Versionsschnappschüssen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function usersWithUsage(): array
    {
        $stmt = $this->pdo->query(
            "SELECT users.id,
                    users.email,
                    users.name,
                    users.is_active,
                    users.created_at,
                    users.last_login_at,
                    users.storage_quota_mb,
                    (SELECT COUNT(*)
                       FROM pages
                       JOIN workspaces ON workspaces.id = pages.workspace_id
                      WHERE workspaces.user_id = users.id
                        AND pages.deleted_at IS NULL) AS page_count,
                    (SELECT COUNT(*)
                       FROM pages
                       JOIN workspaces ON workspaces.id = pages.workspace_id
                      WHERE workspaces.user_id = users.id
                        AND pages.deleted_at IS NOT NULL) AS trashed_page_count,
                    (SELECT COUNT(*)
                       FROM tasks
                       JOIN categories ON categories.id = tasks.category_id
                       JOIN pages ON pages.id = categories.page_id
                       JOIN workspaces ON workspaces.id = pages.workspace_id
                      WHERE workspaces.user_id = users.id
                        AND tasks.deleted_at IS NULL
                        AND categories.deleted_at IS NULL) AS task_count,
                    (SELECT COUNT(*)
                       FROM note_attachments
                       JOIN pages ON pages.id = note_attachments.page_id
                       JOIN workspaces ON workspaces.id = pages.workspace_id
                      WHERE workspaces.user_id = users.id) AS image_count,
                    (SELECT COUNT(*)
                       FROM note_attachments
                       JOIN pages ON pages.id = note_attachments.page_id
                       JOIN workspaces ON workspaces.id = pages.workspace_id
                      WHERE workspaces.user_id = users.id)
                    + (SELECT COUNT(*)
                         FROM page_attachments
                         JOIN pages ON pages.id = page_attachments.page_id
                         JOIN workspaces ON workspaces.id = pages.workspace_id
                        WHERE workspaces.user_id = users.id) AS attachment_count,
                    (SELECT COALESCE(SUM(note_attachments.byte_size), 0)
                       FROM note_attachments
                       JOIN pages ON pages.id = note_attachments.page_id
                       JOIN workspaces ON workspaces.id = pages.workspace_id
                      WHERE workspaces.user_id = users.id)
                    + (SELECT COALESCE(SUM(page_attachments.byte_size), 0)
                         FROM page_attachments
                         JOIN pages ON pages.id = page_attachments.page_id
                         JOIN workspaces ON workspaces.id = pages.workspace_id
                        WHERE workspaces.user_id = users.id) AS attachment_bytes,
                    (SELECT COALESCE(SUM(LENGTH(note_contents.content)), 0)
                       FROM note_contents
                       JOIN pages ON pages.id = note_contents.page_id
                       JOIN workspaces ON workspaces.id = pages.workspace_id
                      WHERE workspaces.user_id = users.id)
                    + (SELECT COALESCE(SUM(LENGTH(note_versions.content)), 0)
                         FROM note_versions
                         JOIN pages ON pages.id = note_versions.page_id
                         JOIN workspaces ON workspaces.id = pages.workspace_id
                        WHERE workspaces.user_id = users.id) AS content_bytes,
                    (SELECT COUNT(*)
                       FROM notebooks
                       JOIN workspaces ON workspaces.id = notebooks.workspace_id
                      WHERE workspaces.user_id = users.id) AS notebook_count,
                    (SELECT COUNT(DISTINCT notebook_shares.notebook_id)
                       FROM notebook_shares
                       JOIN notebooks ON notebooks.id = notebook_shares.notebook_id
                       JOIN workspaces ON workspaces.id = notebooks.workspace_id
                      WHERE workspaces.user_id = users.id) AS shared_notebook_count,
                    (SELECT COALESCE(SUM(total_tokens), 0)
                       FROM ai_usage_log
                      WHERE user_id = users.id) AS ai_tokens_total,
                    (SELECT COALESCE(SUM(total_tokens), 0)
                       FROM ai_usage_log
                      WHERE user_id = users.id
                        AND created_at >= strftime('%Y-%m-%dT%H:%M:%fZ', 'now', '-30 days')) AS ai_tokens_30d
               FROM users
              ORDER BY users.name COLLATE NOCASE ASC, users.id ASC"
        );

        return $stmt !== false ? $stmt->fetchAll() : [];
    }

    /**
     * Speichernamen aller Anhänge eines Nutzers - nötig, um die Dateien vor dem
     * Löschen des Datensatzes vom Datenträger zu räumen.
     *
     * @return list<string>
     */
    public function attachmentStorageNamesForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT note_attachments.storage_name
               FROM note_attachments
               JOIN pages ON pages.id = note_attachments.page_id
               JOIN workspaces ON workspaces.id = pages.workspace_id
              WHERE workspaces.user_id = :user_id
             UNION ALL
             SELECT page_attachments.storage_name
               FROM page_attachments
               JOIN pages ON pages.id = page_attachments.page_id
               JOIN workspaces ON workspaces.id = pages.workspace_id
              WHERE workspaces.user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);

        return array_values(array_map(
            static fn (array $row): string => (string) $row['storage_name'],
            $stmt->fetchAll(),
        ));
    }

    /**
     * Alle Anhänge mit Kennung, Größe und zugehöriger Seite. Grundlage der
     * Verwaisten-Suche; die Zuordnung zu Notizinhalten erfolgt in PHP, weil die
     * Referenz im ProseMirror-JSON steckt.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allAttachments(): array
    {
        $stmt = $this->pdo->query(
            'SELECT note_attachments.id,
                    note_attachments.page_id,
                    note_attachments.token_hash,
                    note_attachments.storage_name,
                    note_attachments.byte_size,
                    note_attachments.created_at,
                    pages.title AS page_title,
                    pages.is_encrypted
               FROM note_attachments
               LEFT JOIN pages ON pages.id = note_attachments.page_id
              ORDER BY note_attachments.id ASC'
        );

        return $stmt !== false ? $stmt->fetchAll() : [];
    }

    /**
     * Notizinhalte und Versionsschnappschüsse - beides kann Anhänge
     * referenzieren, ein Bild ist also erst ohne Treffer in beiden verwaist.
     *
     * @return list<string>
     */
    public function allNoteDocuments(): array
    {
        $documents = [];

        foreach (['SELECT content FROM note_contents', 'SELECT content FROM note_versions'] as $sql) {
            $stmt = $this->pdo->query($sql);
            if ($stmt === false) {
                continue;
            }
            foreach ($stmt->fetchAll() as $row) {
                $documents[] = (string) $row['content'];
            }
        }

        return $documents;
    }

    /** @param list<int> $attachmentIds */
    public function deleteAttachments(array $attachmentIds): void
    {
        if ($attachmentIds === []) {
            return;
        }

        foreach (array_chunk($attachmentIds, 400) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->pdo->prepare("DELETE FROM note_attachments WHERE id IN ({$placeholders})");
            $stmt->execute($chunk);
        }
    }

    public function setUserQuota(int $userId, ?int $quotaMb): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET storage_quota_mb = :quota WHERE id = :id');
        $stmt->execute(['quota' => $quotaMb, 'id' => $userId]);
    }

    public function setUserActive(int $userId, bool $active): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET is_active = :active WHERE id = :id');
        $stmt->execute(['active' => $active ? 1 : 0, 'id' => $userId]);
    }

    /**
     * Beendet alle Sitzungen und widerruft alle Geräte-Tokens eines Nutzers.
     *
     * @return array{sessions: int, device_tokens: int}
     */
    public function revokeAllAccess(int $userId): array
    {
        $now = gmdate('Y-m-d\TH:i:s.v\Z');
        $sessions = $this->pdo->prepare(
            'UPDATE sessions SET revoked_at = :now WHERE user_id = :user_id AND revoked_at IS NULL'
        );
        $sessions->execute(['now' => $now, 'user_id' => $userId]);
        $tokens = $this->pdo->prepare(
            'UPDATE device_tokens SET revoked_at = :now WHERE user_id = :user_id AND revoked_at IS NULL'
        );
        $tokens->execute(['now' => $now, 'user_id' => $userId]);

        return ['sessions' => $sessions->rowCount(), 'device_tokens' => $tokens->rowCount()];
    }

    /**
     * Übergibt alle Notizbücher samt Seiten (auch die im Papierkorb) an einen
     * anderen Workspace. Seiten ohne Notizbuch landen in einem neuen Notizbuch
     * `$collectName`, damit sie beim Empfänger nicht untergehen. Namens-
     * gleichheit löst ein Zusatz mit dem Namen des Vorbesitzers auf.
     *
     * Muss innerhalb einer Transaktion laufen.
     *
     * @return array{notebooks: int, pages: int}
     */
    public function transferContent(int $fromWorkspaceId, int $toWorkspaceId, string $collectName, string $suffix): array
    {
        $now = gmdate('Y-m-d\TH:i:s.v\Z');

        $unassigned = $this->pdo->prepare(
            'SELECT COUNT(*) FROM pages WHERE workspace_id = :workspace_id AND notebook_id IS NULL'
        );
        $unassigned->execute(['workspace_id' => $fromWorkspaceId]);
        if ((int) $unassigned->fetchColumn() > 0) {
            $insert = $this->pdo->prepare(
                'INSERT INTO notebooks (workspace_id, name, name_key, sort_order, created_at, updated_at)
                 VALUES (:workspace_id, :name, :name_key, 0, :now, :now)'
            );
            $name = $this->availableNotebookName($fromWorkspaceId, $collectName, $suffix);
            $insert->execute([
                'workspace_id' => $fromWorkspaceId,
                'name' => $name,
                'name_key' => mb_strtolower($name),
                'now' => $now,
            ]);
            $collectId = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare(
                'UPDATE pages SET notebook_id = :notebook_id WHERE workspace_id = :workspace_id AND notebook_id IS NULL'
            )->execute(['notebook_id' => $collectId, 'workspace_id' => $fromWorkspaceId]);
        }

        $notebooks = $this->pdo->prepare('SELECT id, name FROM notebooks WHERE workspace_id = :workspace_id');
        $notebooks->execute(['workspace_id' => $fromWorkspaceId]);
        $rename = $this->pdo->prepare(
            'UPDATE notebooks SET workspace_id = :to_workspace, name = :name, name_key = :name_key, updated_at = :now
              WHERE id = :id'
        );
        $notebookCount = 0;
        foreach ($notebooks->fetchAll() as $notebook) {
            $name = $this->availableNotebookName($toWorkspaceId, (string) $notebook['name'], $suffix);
            $rename->execute([
                'to_workspace' => $toWorkspaceId,
                'name' => $name,
                'name_key' => mb_strtolower($name),
                'now' => $now,
                'id' => (int) $notebook['id'],
            ]);
            ++$notebookCount;
        }

        // Der Empfänger ist jetzt Eigentümer - eine Teilnahme an diesen
        // Notizbüchern wäre doppelt.
        $this->pdo->prepare(
            'DELETE FROM notebook_shares
              WHERE user_id = (SELECT user_id FROM workspaces WHERE id = :to_workspace)
                AND notebook_id IN (SELECT id FROM notebooks WHERE workspace_id = :to_workspace_again)'
        )->execute(['to_workspace' => $toWorkspaceId, 'to_workspace_again' => $toWorkspaceId]);

        $pages = $this->pdo->prepare(
            'UPDATE pages SET workspace_id = :to_workspace WHERE workspace_id = :from_workspace'
        );
        $pages->execute(['to_workspace' => $toWorkspaceId, 'from_workspace' => $fromWorkspaceId]);

        return ['notebooks' => $notebookCount, 'pages' => $pages->rowCount()];
    }

    private function availableNotebookName(int $workspaceId, string $name, string $suffix): string
    {
        $exists = $this->pdo->prepare(
            'SELECT 1 FROM notebooks WHERE workspace_id = :workspace_id AND name_key = :name_key'
        );
        $candidate = $name;
        for ($attempt = 1; ; ++$attempt) {
            $exists->execute(['workspace_id' => $workspaceId, 'name_key' => mb_strtolower($candidate)]);
            if ($exists->fetchColumn() === false) {
                return $candidate;
            }
            $candidate = mb_substr($name, 0, 80) . ' (' . $suffix . ($attempt > 1 ? ' ' . $attempt : '') . ')';
        }
    }

    public function deleteUser(int $userId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
    }

    /** Eigene Notizbücher, an denen mindestens ein anderer Nutzer teilnimmt. */
    public function sharedNotebookCountForUser(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT notebook_shares.notebook_id)
               FROM notebook_shares
               JOIN notebooks ON notebooks.id = notebook_shares.notebook_id
               JOIN workspaces ON workspaces.id = notebooks.workspace_id
              WHERE workspaces.user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    public function workspaceIdForUser(int $userId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM workspaces WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /** @return array<string, mixed>|null */
    public function findUser(int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }
}
