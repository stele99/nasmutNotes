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
                      WHERE workspaces.user_id = users.id) AS task_count,
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
                        WHERE workspaces.user_id = users.id) AS content_bytes
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
                    pages.title AS page_title
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

    public function deleteUser(int $userId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
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
