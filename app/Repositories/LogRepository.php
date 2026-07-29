<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Spalten, Einträge und Werte einer Logbuch-Seite (FR-LOG-01..09).
 */
final class LogRepository
{
    /** Obergrenze je Abfrage; ein Logbuch soll auch nach Jahren ladbar bleiben. */
    public const MAX_ENTRIES = 1000;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function columnsForPage(int $pageId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM log_columns WHERE page_id = :page_id ORDER BY position, id'
        );
        $stmt->execute(['page_id' => $pageId]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findColumn(int $columnId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM log_columns WHERE id = :id');
        $stmt->execute(['id' => $columnId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed> */
    public function createColumn(int $pageId, string $name, string $type, int $position): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO log_columns (page_id, name, type, position, created_at)
             VALUES (:page_id, :name, :type, :position, :now)'
        );
        $stmt->execute([
            'page_id' => $pageId,
            'name' => $name,
            'type' => $type,
            'position' => $position,
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);

        $column = $this->findColumn((int) $this->pdo->lastInsertId());
        assert($column !== null);

        return $column;
    }

    /** @param array<string, mixed> $fields */
    public function updateColumn(int $columnId, array $fields): void
    {
        $allowed = ['name', 'position'];
        $set = [];
        $params = ['id' => $columnId];
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

        $stmt = $this->pdo->prepare('UPDATE log_columns SET ' . implode(', ', $set) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    public function deleteColumn(int $columnId): void
    {
        $this->pdo->prepare('DELETE FROM log_columns WHERE id = :id')->execute(['id' => $columnId]);
    }

    public function nextColumnPosition(int $pageId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM log_columns WHERE page_id = :page_id');
        $stmt->execute(['page_id' => $pageId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Einträge samt Werten. Sortiert wird entweder nach dem Zeitpunkt oder nach
     * einer Spalte; bei Gleichstand entscheidet der Zeitpunkt, damit die
     * Reihenfolge stabil bleibt.
     *
     * @return array<int, array<string, mixed>>
     */
    public function entriesForPage(int $pageId, ?int $sortColumnId, bool $ascending): array
    {
        $direction = $ascending ? 'ASC' : 'DESC';
        $limit = self::MAX_ENTRIES;

        if ($sortColumnId === null) {
            $sql = "SELECT e.*, u.name AS created_by_name
                      FROM log_entries e
                 LEFT JOIN users u ON u.id = e.created_by
                     WHERE e.page_id = :page_id
                  ORDER BY e.occurred_at {$direction}, e.id {$direction}
                     LIMIT {$limit}";
            $params = ['page_id' => $pageId];
        } else {
            // NULL-Werte gehören ans Ende, unabhängig von der Richtung: Eine
            // leere Zelle ist kein kleiner Wert, sondern gar keiner.
            $sql = "SELECT e.*, u.name AS created_by_name
                      FROM log_entries e
                 LEFT JOIN users u ON u.id = e.created_by
                 LEFT JOIN log_values v ON v.entry_id = e.id AND v.column_id = :column_id
                     WHERE e.page_id = :page_id
                  ORDER BY (v.value_number IS NULL AND v.value_text IS NULL) ASC,
                           v.value_number {$direction},
                           v.value_text COLLATE NOCASE {$direction},
                           e.occurred_at DESC
                     LIMIT {$limit}";
            $params = ['page_id' => $pageId, 'column_id' => $sortColumnId];
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $entries = $stmt->fetchAll();

        return $this->withValues($entries);
    }

    /** @return array<string, mixed>|null */
    public function findEntry(int $entryId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.*, u.name AS created_by_name
               FROM log_entries e
          LEFT JOIN users u ON u.id = e.created_by
              WHERE e.id = :id'
        );
        $stmt->execute(['id' => $entryId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return $this->withValues([$row])[0];
    }

    public function createEntry(int $pageId, string $occurredAt, ?int $userId): int
    {
        $now = gmdate('Y-m-d\TH:i:s.v\Z');
        $stmt = $this->pdo->prepare(
            'INSERT INTO log_entries (page_id, occurred_at, created_at, updated_at, created_by)
             VALUES (:page_id, :occurred_at, :now, :now, :created_by)'
        );
        $stmt->execute([
            'page_id' => $pageId,
            'occurred_at' => $occurredAt,
            'now' => $now,
            'created_by' => $userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateEntryTime(int $entryId, string $occurredAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE log_entries SET occurred_at = :occurred_at, updated_at = :now WHERE id = :id'
        );
        $stmt->execute([
            'occurred_at' => $occurredAt,
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'id' => $entryId,
        ]);
    }

    public function touchEntry(int $entryId): void
    {
        $stmt = $this->pdo->prepare('UPDATE log_entries SET updated_at = :now WHERE id = :id');
        $stmt->execute(['now' => gmdate('Y-m-d\TH:i:s.v\Z'), 'id' => $entryId]);
    }

    public function deleteEntry(int $entryId): void
    {
        $this->pdo->prepare('DELETE FROM log_entries WHERE id = :id')->execute(['id' => $entryId]);
    }

    /** @param array{text: ?string, number: ?float, lat: ?float, lon: ?float} $value */
    public function setValue(int $entryId, int $columnId, array $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO log_values (entry_id, column_id, value_text, value_number, value_lat, value_lon)
             VALUES (:entry_id, :column_id, :text, :number, :lat, :lon)
             ON CONFLICT(entry_id, column_id) DO UPDATE SET
                 value_text = excluded.value_text,
                 value_number = excluded.value_number,
                 value_lat = excluded.value_lat,
                 value_lon = excluded.value_lon'
        );
        $stmt->execute([
            'entry_id' => $entryId,
            'column_id' => $columnId,
            'text' => $value['text'],
            'number' => $value['number'],
            'lat' => $value['lat'],
            'lon' => $value['lon'],
        ]);
    }

    public function clearValue(int $entryId, int $columnId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM log_values WHERE entry_id = :entry_id AND column_id = :column_id');
        $stmt->execute(['entry_id' => $entryId, 'column_id' => $columnId]);
    }

    /**
     * Alle Ortsspalten-Werte des Workspaces mit Koordinaten - Grundlage der
     * Umkreissuche (FR-NOTE-27), zusammen mit dem Seitentitel und dem
     * Spaltennamen für die Anzeige.
     *
     * @return array<int, array<string, mixed>>
     */
    public function locatedValuesForWorkspace(int $workspaceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT lv.entry_id, lv.value_text, lv.value_lat, lv.value_lon,
                    le.page_id, le.occurred_at,
                    lc.name AS column_name,
                    p.title AS page_title
               FROM log_values lv
               JOIN log_entries le ON le.id = lv.entry_id
               JOIN log_columns lc ON lc.id = lv.column_id
               JOIN pages p ON p.id = le.page_id
              WHERE p.workspace_id = :workspace_id
                AND p.deleted_at IS NULL
                AND lv.value_lat IS NOT NULL
                AND lv.value_lon IS NOT NULL'
        );
        $stmt->execute(['workspace_id' => $workspaceId]);

        return $stmt->fetchAll();
    }

    public function countEntries(int $pageId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM log_entries WHERE page_id = :page_id');
        $stmt->execute(['page_id' => $pageId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Lädt die Werte aller übergebenen Einträge in einer Abfrage nach.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function withValues(array $entries): array
    {
        if ($entries === []) {
            return [];
        }

        $ids = array_map(static fn (array $entry): int => (int) $entry['id'], $entries);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM log_values WHERE entry_id IN ({$placeholders})");
        $stmt->execute($ids);

        $byEntry = [];
        foreach ($stmt->fetchAll() as $value) {
            $byEntry[(int) $value['entry_id']][(int) $value['column_id']] = $value;
        }

        return array_values(array_map(
            static function (array $entry) use ($byEntry): array {
                $entry['values'] = $byEntry[(int) $entry['id']] ?? [];

                return $entry;
            },
            $entries,
        ));
    }
}
