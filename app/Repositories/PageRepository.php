<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Log\LogColumnType;
use App\Domain\Log\LogService;
use PDO;

final class PageRepository
{
    private const SORTS = [
        'updated' => 'pages.updated_at DESC',
        'title' => 'pages.title COLLATE NOCASE ASC',
        'created' => 'pages.created_at DESC',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{lat: float, lon: float, accuracy: ?float, label?: ?string}|null $location Aufnahmeort (FR-NOTE-25).
     * @return array<string, mixed>
     */
    public function create(
        int $workspaceId,
        string $type,
        string $title,
        ?string $icon,
        ?int $notebookId = null,
        ?array $location = null,
    ): array {
        $now = gmdate('Y-m-d\TH:i:s.v\Z');
        $stmt = $this->pdo->prepare(
            'INSERT INTO pages (workspace_id, type, title, icon, notebook_id, created_at, updated_at,
                                location_lat, location_lon, location_accuracy, location_label, location_at)
             VALUES (:workspace_id, :type, :title, :icon, :notebook_id, :now, :now,
                     :location_lat, :location_lon, :location_accuracy, :location_label, :location_at)'
        );
        $stmt->execute([
            'workspace_id' => $workspaceId,
            'type' => $type,
            'title' => $title,
            'icon' => $icon,
            'notebook_id' => $notebookId,
            'now' => $now,
            'location_lat' => $location['lat'] ?? null,
            'location_lon' => $location['lon'] ?? null,
            'location_accuracy' => $location['accuracy'] ?? null,
            'location_label' => $location['label'] ?? null,
            'location_at' => $location === null ? null : $now,
        ]);

        $id = (int) $this->pdo->lastInsertId();

        if ($type === 'note') {
            $this->pdo->prepare(
                'INSERT INTO note_contents (page_id, updated_at) VALUES (:id, :now)'
            )->execute(['id' => $id, 'now' => $now]);
        }

        // Ein Logbuch ohne Spalte wäre nicht benutzbar; die erste entsteht
        // deshalb zusammen mit der Seite (FR-LOG-02).
        if ($type === 'log') {
            $this->pdo->prepare(
                'INSERT INTO log_columns (page_id, name, type, position, created_at)
                 VALUES (:id, :name, :type, 0, :now)'
            )->execute([
                'id' => $id,
                'name' => LogService::DEFAULT_COLUMN_NAME,
                'type' => LogColumnType::Text->value,
                'now' => $now,
            ]);
        }

        $page = $this->findByIdForWorkspace($id, $workspaceId);
        assert($page !== null);

        return $page;
    }

    /** @return array<string, mixed>|null */
    public function findByIdForWorkspace(int $id, int $workspaceId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pages.*, notebooks.name AS notebook_name, notebooks.icon AS notebook_icon, notebooks.color AS notebook_color
               FROM pages
          LEFT JOIN notebooks ON notebooks.id = pages.notebook_id
              WHERE pages.id = :id AND pages.workspace_id = :workspace_id'
        );
        $stmt->execute(['id' => $id, 'workspace_id' => $workspaceId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pages WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForWorkspace(
        int $workspaceId,
        string $sort = 'updated',
        ?string $typeFilter = null,
        bool $includeTrashed = false,
        ?int $notebookId = null,
        bool $unassigned = false,
    ): array {
        $orderBy = self::SORTS[$sort] ?? self::SORTS['updated'];

        $sql = 'SELECT pages.*, notebooks.name AS notebook_name
                  FROM pages
             LEFT JOIN notebooks ON notebooks.id = pages.notebook_id
                 WHERE pages.workspace_id = :workspace_id';
        $params = ['workspace_id' => $workspaceId];

        $sql .= $includeTrashed ? ' AND pages.deleted_at IS NOT NULL' : ' AND pages.deleted_at IS NULL';

        if ($typeFilter !== null) {
            $sql .= ' AND pages.type = :type';
            $params['type'] = $typeFilter;
        }
        if ($notebookId !== null) {
            $sql .= ' AND pages.notebook_id = :notebook_id';
            $params['notebook_id'] = $notebookId;
        } elseif ($unassigned) {
            $sql .= ' AND pages.notebook_id IS NULL';
        }

        $sql .= ' ORDER BY pages.is_favorite DESC, ' . $orderBy;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Kurzinfos für die Seitenliste: Textanfang und letzter Bearbeiter einer
     * Notiz bzw. Aufgabenzahl einer Task-Seite. Bewusst zwei Sammelabfragen
     * statt einer Abfrage je Seite.
     *
     * @param list<int> $pageIds
     * @return array<int, array{preview: ?string, last_editor_name: ?string, task_count: ?int,
     *     open_task_count: ?int, attachment_count: int, log_entry_count: ?int, latest_entry_at: ?string}>
     */
    public function summaries(array $pageIds): array
    {
        $summaries = [];

        // SQLite begrenzt die Anzahl gebundener Parameter; große Workspaces
        // würden ohne Stückelung an dieser Grenze scheitern.
        foreach (array_chunk($pageIds, 400) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));

            $stmt = $this->pdo->prepare(
                "SELECT note_contents.page_id AS page_id,
                        substr(note_contents.content_text, 1, 400) AS preview,
                        users.name AS last_editor_name
                 FROM note_contents
                 LEFT JOIN users ON users.id = note_contents.updated_by
                 WHERE note_contents.page_id IN ({$placeholders})"
            );
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll() as $row) {
                $summaries[(int) $row['page_id']] = [
                    'preview' => $this->firstLine((string) ($row['preview'] ?? '')),
                    'last_editor_name' => $row['last_editor_name'] !== null
                        ? (string) $row['last_editor_name']
                        : null,
                    'task_count' => null,
                    'open_task_count' => null,
                    'attachment_count' => 0,
                    'log_entry_count' => null,
                    'latest_entry_at' => null,
                ];
            }

            $stmt = $this->pdo->prepare(
                "SELECT categories.page_id AS page_id,
                        COUNT(tasks.id) AS task_count,
                        COALESCE(SUM(CASE WHEN tasks.is_done = 0 THEN 1 ELSE 0 END), 0) AS open_task_count
                 FROM categories
                 LEFT JOIN tasks ON tasks.category_id = categories.id
                 WHERE categories.page_id IN ({$placeholders})
                 GROUP BY categories.page_id"
            );
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll() as $row) {
                $summaries[(int) $row['page_id']] = [
                    'preview' => null,
                    'last_editor_name' => null,
                    'task_count' => (int) $row['task_count'],
                    'open_task_count' => (int) $row['open_task_count'],
                    'attachment_count' => 0,
                    'log_entry_count' => null,
                    'latest_entry_at' => null,
                ];
            }

            // Logbücher zeigen in der Liste ihre Einträge samt jüngstem Datum.
            $stmt = $this->pdo->prepare(
                "SELECT page_id, COUNT(*) AS entry_count, MAX(occurred_at) AS latest_entry_at
                 FROM log_entries
                 WHERE page_id IN ({$placeholders})
                 GROUP BY page_id"
            );
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll() as $row) {
                $summaries[(int) $row['page_id']] = [
                    'preview' => null,
                    'last_editor_name' => null,
                    'task_count' => null,
                    'open_task_count' => null,
                    'attachment_count' => 0,
                    'log_entry_count' => (int) $row['entry_count'],
                    'latest_entry_at' => $row['latest_entry_at'] !== null ? (string) $row['latest_entry_at'] : null,
                ];
            }

            // Zahl der Dateianhänge: Der Offline-Prefetch fragt die Anhangliste
            // nur für Seiten ab, die überhaupt welche haben.
            $stmt = $this->pdo->prepare(
                "SELECT page_id, COUNT(*) AS attachment_count
                 FROM page_attachments
                 WHERE page_id IN ({$placeholders})
                 GROUP BY page_id"
            );
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll() as $row) {
                $pageId = (int) $row['page_id'];
                $summary = $summaries[$pageId] ?? [
                    'preview' => null,
                    'last_editor_name' => null,
                    'task_count' => null,
                    'open_task_count' => null,
                    'attachment_count' => 0,
                    'log_entry_count' => null,
                    'latest_entry_at' => null,
                ];
                $summary['attachment_count'] = (int) $row['attachment_count'];
                $summaries[$pageId] = $summary;
            }
        }

        return $summaries;
    }

    /**
     * Erste nicht leere Zeile des Notiztexts, gekürzt auf Listenlänge.
     */
    private function firstLine(string $text): ?string
    {
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                return mb_strlen($line) > 140 ? mb_substr($line, 0, 140) . '…' : $line;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $fields */
    public function updateFields(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $allowed = [
            'title', 'icon', 'is_favorite', 'sort_order', 'default_view', 'notebook_id',
            'location_lat', 'location_lon', 'location_accuracy', 'location_label', 'location_at',
        ];
        $set = [];
        $params = ['id' => $id];

        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $set[] = "{$key} = :{$key}";
            $params[$key] = $value;
        }

        if ($set === []) {
            return;
        }

        $set[] = 'updated_at = :updated_at';
        $params['updated_at'] = gmdate('Y-m-d\TH:i:s.v\Z');

        $sql = 'UPDATE pages SET ' . implode(', ', $set) . ' WHERE id = :id';
        $this->pdo->prepare($sql)->execute($params);
    }

    /** @param list<int> $pageIds */
    public function moveToNotebook(int $workspaceId, array $pageIds, ?int $notebookId): int
    {
        if ($pageIds === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE pages
                SET notebook_id = ?, updated_at = ?
              WHERE workspace_id = ? AND deleted_at IS NULL AND id IN ({$placeholders})"
        );
        $stmt->execute([
            $notebookId,
            gmdate('Y-m-d\TH:i:s.v\Z'),
            $workspaceId,
            ...$pageIds,
        ]);

        return $stmt->rowCount();
    }

    /** @param list<int> $pageIds */
    public function softDeleteMany(int $workspaceId, array $pageIds): int
    {
        if ($pageIds === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE pages
                SET deleted_at = ?
              WHERE workspace_id = ? AND deleted_at IS NULL AND id IN ({$placeholders})"
        );
        $stmt->execute([
            gmdate('Y-m-d\TH:i:s.v\Z'),
            $workspaceId,
            ...$pageIds,
        ]);

        return $stmt->rowCount();
    }

    public function touchUpdatedAt(int $id, ?string $updatedAt = null): void
    {
        $stmt = $this->pdo->prepare('UPDATE pages SET updated_at = :now WHERE id = :id');
        $stmt->execute(['now' => $updatedAt ?? gmdate('Y-m-d\TH:i:s.v\Z'), 'id' => $id]);
    }

    /**
     * Übernimmt die Zeitstempel aus einem Import (FR-IMP-22). Der Notizinhalt
     * bekommt dasselbe Änderungsdatum, damit die Anzeige „Zuletzt geändert"
     * nicht den Importzeitpunkt nennt.
     */
    public function setTimestamps(int $id, ?string $createdAt, ?string $updatedAt): void
    {
        if ($createdAt !== null) {
            $this->pdo->prepare('UPDATE pages SET created_at = :value WHERE id = :id')
                ->execute(['value' => $createdAt, 'id' => $id]);
        }
        if ($updatedAt !== null) {
            $this->pdo->prepare('UPDATE pages SET updated_at = :value WHERE id = :id')
                ->execute(['value' => $updatedAt, 'id' => $id]);
            $this->pdo->prepare('UPDATE note_contents SET updated_at = :value WHERE page_id = :id')
                ->execute(['value' => $updatedAt, 'id' => $id]);
        }
    }

    public function softDelete(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE pages SET deleted_at = :now WHERE id = :id');
        $stmt->execute(['now' => gmdate('Y-m-d\TH:i:s.v\Z'), 'id' => $id]);
    }

    public function restore(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE pages SET deleted_at = NULL WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function purge(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM pages WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Seiten, deren Aufbewahrungsfrist im Papierkorb abgelaufen ist. Bewusst
     * nur lesend: Der Aufrufer muss vorher die Bilddateien einsammeln.
     *
     * @return list<int>
     */
    public function expiredTrashPageIds(int $retentionDays): array
    {
        $threshold = gmdate('Y-m-d\TH:i:s.v\Z', time() - ($retentionDays * 86400));

        $stmt = $this->pdo->prepare('SELECT id FROM pages WHERE deleted_at IS NOT NULL AND deleted_at < :threshold');
        $stmt->execute(['threshold' => $threshold]);

        return array_values(array_map(
            static fn (array $row): int => (int) $row['id'],
            $stmt->fetchAll(),
        ));
    }

    /**
     * @return array{
     *     notebooks: int,
     *     pages: int,
     *     tasks: int,
     *     files: int,
     *     storage_bytes: int,
     *     top_items: list<array{id: int, title: string, type: string, deleted_at: ?string, bytes: int}>
     * }
     */
    public function workspaceStats(int $workspaceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM notebooks WHERE workspace_id = :workspace_id) AS notebooks,
                (SELECT COUNT(*) FROM pages WHERE workspace_id = :workspace_id AND deleted_at IS NULL) AS pages,
                (SELECT COUNT(tasks.id)
                   FROM tasks
                   JOIN categories ON categories.id = tasks.category_id
                   JOIN pages ON pages.id = categories.page_id
                  WHERE pages.workspace_id = :workspace_id AND pages.deleted_at IS NULL) AS tasks,
                ((SELECT COUNT(note_attachments.id)
                    FROM note_attachments
                    JOIN pages ON pages.id = note_attachments.page_id
                   WHERE pages.workspace_id = :workspace_id AND pages.deleted_at IS NULL)
                 +
                 (SELECT COUNT(page_attachments.id)
                    FROM page_attachments
                    JOIN pages ON pages.id = page_attachments.page_id
                   WHERE pages.workspace_id = :workspace_id AND pages.deleted_at IS NULL)) AS files,
                ((SELECT COALESCE(SUM(note_attachments.byte_size), 0)
                    FROM note_attachments
                    JOIN pages ON pages.id = note_attachments.page_id
                   WHERE pages.workspace_id = :workspace_id)
                 +
                 (SELECT COALESCE(SUM(page_attachments.byte_size), 0)
                    FROM page_attachments
                    JOIN pages ON pages.id = page_attachments.page_id
                   WHERE pages.workspace_id = :workspace_id)
                 +
                 (SELECT COALESCE(SUM(LENGTH(note_contents.content)), 0)
                    FROM note_contents
                    JOIN pages ON pages.id = note_contents.page_id
                   WHERE pages.workspace_id = :workspace_id)
                 +
                 (SELECT COALESCE(SUM(LENGTH(note_versions.content)), 0)
                    FROM note_versions
                    JOIN pages ON pages.id = note_versions.page_id
                   WHERE pages.workspace_id = :workspace_id)) AS storage_bytes'
        );
        $stmt->execute(['workspace_id' => $workspaceId]);
        $row = $stmt->fetch();

        return [
            'notebooks' => (int) ($row['notebooks'] ?? 0),
            'pages' => (int) ($row['pages'] ?? 0),
            'tasks' => (int) ($row['tasks'] ?? 0),
            'files' => (int) ($row['files'] ?? 0),
            'storage_bytes' => (int) ($row['storage_bytes'] ?? 0),
            'top_items' => $this->workspaceStorageTopItems($workspaceId),
        ];
    }

    /**
     * @return list<array{id: int, title: string, type: string, deleted_at: ?string, bytes: int}>
     */
    private function workspaceStorageTopItems(int $workspaceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pages.id,
                    pages.title,
                    pages.type,
                    pages.deleted_at,
                    COALESCE(LENGTH(note_contents.content), 0)
                    + COALESCE(versions.byte_size, 0)
                    + COALESCE(images.byte_size, 0)
                    + COALESCE(files.byte_size, 0) AS storage_bytes
               FROM pages
          LEFT JOIN note_contents ON note_contents.page_id = pages.id
          LEFT JOIN (
                    SELECT page_id, SUM(LENGTH(content)) AS byte_size
                      FROM note_versions
                  GROUP BY page_id
                    ) AS versions ON versions.page_id = pages.id
          LEFT JOIN (
                    SELECT page_id, SUM(byte_size) AS byte_size
                      FROM note_attachments
                  GROUP BY page_id
                    ) AS images ON images.page_id = pages.id
          LEFT JOIN (
                    SELECT page_id, SUM(byte_size) AS byte_size
                      FROM page_attachments
                  GROUP BY page_id
                    ) AS files ON files.page_id = pages.id
              WHERE pages.workspace_id = :workspace_id
           ORDER BY storage_bytes DESC, pages.updated_at DESC, pages.id DESC
              LIMIT 10'
        );
        $stmt->execute(['workspace_id' => $workspaceId]);

        return array_values(array_map(
            static fn (array $item): array => [
                'id' => (int) $item['id'],
                'title' => (string) $item['title'],
                'type' => (string) $item['type'],
                'deleted_at' => $item['deleted_at'] !== null ? (string) $item['deleted_at'] : null,
                'bytes' => (int) $item['storage_bytes'],
            ],
            $stmt->fetchAll(),
        ));
    }

    /** @return array<int, array<string, mixed>> */
    public function purgeExpiredTrash(int $retentionDays): array
    {
        $threshold = gmdate('Y-m-d\TH:i:s.v\Z', time() - ($retentionDays * 86400));

        $stmt = $this->pdo->prepare('SELECT id FROM pages WHERE deleted_at IS NOT NULL AND deleted_at < :threshold');
        $stmt->execute(['threshold' => $threshold]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $this->purge((int) $row['id']);
        }

        return $rows;
    }
}
