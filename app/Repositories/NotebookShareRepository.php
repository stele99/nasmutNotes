<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class NotebookShareRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function add(int $userId, int $notebookId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO notebook_shares (user_id, notebook_id, created_at)
             VALUES (:user_id, :notebook_id, :created_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'notebook_id' => $notebookId,
            'created_at' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
    }

    public function remove(int $userId, int $notebookId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM notebook_shares WHERE user_id = :user_id AND notebook_id = :notebook_id'
        );
        $stmt->execute(['user_id' => $userId, 'notebook_id' => $notebookId]);
    }

    public function exists(int $userId, int $notebookId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM notebook_shares WHERE user_id = :user_id AND notebook_id = :notebook_id'
        );
        $stmt->execute(['user_id' => $userId, 'notebook_id' => $notebookId]);

        return $stmt->fetch() !== false;
    }

    /**
     * Ein Notizbuch, das der Nutzer von jemand anderem geteilt bekommen hat -
     * die Kern-Zugriffsprüfung für die Teilnehmerrolle.
     *
     * @return array<string, mixed>|null
     */
    public function findSharedNotebookForUser(int $userId, int $notebookId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT notebooks.*, owner.name AS owner_name
             FROM notebook_shares
             JOIN notebooks ON notebooks.id = notebook_shares.notebook_id
             JOIN workspaces ON workspaces.id = notebooks.workspace_id
             JOIN users owner ON owner.id = workspaces.user_id
             WHERE notebook_shares.user_id = :user_id
               AND notebooks.id = :notebook_id'
        );
        $stmt->execute(['user_id' => $userId, 'notebook_id' => $notebookId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Eine Seite in einem Notizbuch, das der Nutzer geteilt bekommen hat -
     * dritter Zugriffspfad neben eigenem Workspace und Seitenfreigabe.
     *
     * @return array<string, mixed>|null
     */
    public function findPageInSharedNotebook(int $userId, int $pageId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pages.*, owner.name AS owner_name
             FROM pages
             JOIN notebooks ON notebooks.id = pages.notebook_id
             JOIN workspaces ON workspaces.id = notebooks.workspace_id
             JOIN users owner ON owner.id = workspaces.user_id
             JOIN notebook_shares ON notebook_shares.notebook_id = notebooks.id
                  AND notebook_shares.user_id = :user_id
             WHERE pages.id = :page_id
               AND pages.deleted_at IS NULL'
        );
        $stmt->execute(['user_id' => $userId, 'page_id' => $pageId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Alle Notizbücher, die der Nutzer von anderen geteilt bekommen hat,
     * inklusive Name des Eigentümers und Seitenzahl.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listSharedNotebooksForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT notebooks.*, COUNT(pages.id) AS page_count,
                    owner.name AS owner_name, notebook_shares.created_at AS shared_at
             FROM notebook_shares
             JOIN notebooks ON notebooks.id = notebook_shares.notebook_id
             JOIN workspaces ON workspaces.id = notebooks.workspace_id
             JOIN users owner ON owner.id = workspaces.user_id
             LEFT JOIN pages ON pages.notebook_id = notebooks.id
             WHERE notebook_shares.user_id = :user_id
             GROUP BY notebooks.id
             ORDER BY notebooks.name COLLATE NOCASE ASC, notebooks.id ASC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    /**
     * Teilnehmer eines Notizbuchs für die Verwaltungsansicht des Eigentümers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listParticipants(int $notebookId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT users.id, users.name, users.email, notebook_shares.created_at
             FROM notebook_shares
             JOIN users ON users.id = notebook_shares.user_id
             WHERE notebook_shares.notebook_id = :notebook_id
             ORDER BY users.name COLLATE NOCASE ASC, users.id ASC'
        );
        $stmt->execute(['notebook_id' => $notebookId]);

        return $stmt->fetchAll();
    }

    /**
     * Anzahl Teilnehmer je Notizbuch eines Workspaces - Grundlage der
     * Teilen-Kennzeichnung in der Liste des Eigentümers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function countForWorkspace(int $workspaceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT notebook_shares.notebook_id, COUNT(*) AS participant_count
             FROM notebook_shares
             JOIN notebooks ON notebooks.id = notebook_shares.notebook_id
             WHERE notebooks.workspace_id = :workspace_id
             GROUP BY notebook_shares.notebook_id'
        );
        $stmt->execute(['workspace_id' => $workspaceId]);

        return $stmt->fetchAll();
    }
}
