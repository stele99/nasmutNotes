<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SearchRepository
{
    public const DEFAULT_LIMIT = 50;

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $limit = self::DEFAULT_LIMIT,
    ) {
    }

    /**
     * Sucht im Workspace, in allen per Link mit dem Nutzer geteilten Seiten und
     * in den Notizbüchern, an denen er teilnimmt. Die Seitenleiste schränkt
     * zusätzlich auf ihre Sammlung ein - dort sucht man im gewählten
     * Notizbuch, nicht im ganzen Workspace.
     *
     * Der Vergleich läuft über fold() (siehe Database::connect), damit Umlaute
     * unabhängig von Groß-/Kleinschreibung passen; instr() statt LIKE, weil
     * `%` und `_` im Suchbegriff sonst als Platzhalter gälten.
     *
     * @param ?string $collection `notebook`, `unassigned`, `shared` oder
     *                            `favorites`; alles andere sucht überall.
     * @return array{pages: array<int, array<string, mixed>>, has_more: bool}
     */
    public function search(
        int $workspaceId,
        int $userId,
        string $query,
        ?string $collection = null,
        ?int $notebookId = null,
        int $offset = 0,
    ): array {
        $scope = match ($collection) {
            // Ein Notizbuch gehört dem eigenen Workspace oder ist mit dem
            // Nutzer geteilt; der Zugriffsfilter unten deckt beides ab.
            'notebook' => $notebookId === null ? '' : ' AND p.notebook_id = :notebook_id',
            'unassigned' => ' AND p.workspace_id = :workspace_id AND p.notebook_id IS NULL',
            'shared' => ' AND (sl.id IS NOT NULL OR nbs.user_id IS NOT NULL)',
            'favorites' => ' AND p.workspace_id = :workspace_id AND p.is_favorite = 1',
            default => '',
        };

        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT p.id,
                    p.title,
                    p.type,
                    p.is_encrypted,
                    p.updated_at,
                    CASE WHEN p.workspace_id = :owner_workspace_id THEN 0 ELSE 1 END AS is_shared
             FROM pages p
             LEFT JOIN note_contents n ON n.page_id = p.id
             LEFT JOIN categories c ON c.page_id = p.id AND c.deleted_at IS NULL
             LEFT JOIN tasks t ON t.category_id = c.id AND t.deleted_at IS NULL
             LEFT JOIN log_entries le ON le.page_id = p.id AND le.deleted_at IS NULL
             LEFT JOIN log_values lv ON lv.entry_id = le.id
                AND lv.column_id NOT IN (SELECT id FROM log_columns WHERE deleted_at IS NOT NULL)
             LEFT JOIN page_attachments pa ON pa.page_id = p.id
             LEFT JOIN shared_page_access spa ON spa.user_id = :user_id
             LEFT JOIN share_links sl ON sl.id = spa.share_link_id
                AND sl.page_id = p.id
                AND sl.revoked_at IS NULL
                AND (sl.expires_at IS NULL OR sl.expires_at > :now)
             LEFT JOIN notebook_shares nbs ON nbs.notebook_id = p.notebook_id
                AND nbs.user_id = :share_user_id
             WHERE p.deleted_at IS NULL
               AND (p.workspace_id = :workspace_id OR sl.id IS NOT NULL OR nbs.user_id IS NOT NULL)
               AND (
                   instr(fold(p.title), :term) > 0
                   OR instr(fold(p.location_label), :term) > 0
                   OR instr(fold(n.content_text), :term) > 0
                   OR instr(fold(c.name), :term) > 0
                   OR instr(fold(t.title), :term) > 0
                   OR instr(fold(t.description), :term) > 0
                   OR instr(fold(t.responsible), :term) > 0
                   OR instr(fold(lv.value_text), :term) > 0
                   OR instr(fold(pa.original_name), :term) > 0
               )'
             . $scope
             . ' ORDER BY p.updated_at DESC, p.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('owner_workspace_id', $workspaceId, PDO::PARAM_INT);
        $stmt->bindValue('user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue('share_user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue('now', gmdate('Y-m-d\TH:i:s.v\Z'));
        $stmt->bindValue('workspace_id', $workspaceId, PDO::PARAM_INT);
        $stmt->bindValue('term', mb_strtolower($query));
        // Eine Zeile mehr als angezeigt verrät, ob es weitere Treffer gibt.
        $stmt->bindValue('limit', $this->limit + 1, PDO::PARAM_INT);
        $stmt->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        if ($collection === 'notebook' && $notebookId !== null) {
            $stmt->bindValue('notebook_id', $notebookId, PDO::PARAM_INT);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll();
        $hasMore = count($rows) > $this->limit;

        return [
            'pages' => array_map(static function (array $page): array {
                $page['is_encrypted'] = (bool) $page['is_encrypted'];
                $page['is_shared'] = (bool) $page['is_shared'];

                return $page;
            }, array_slice($rows, 0, $this->limit)),
            'has_more' => $hasMore,
        ];
    }
}
