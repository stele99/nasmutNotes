-- Aufgaben, Kapitel, Logbuch-Einträge und -Spalten landen beim Löschen
-- zunächst in einem weichen Papierkorb: Die Oberfläche bietet „Rückgängig“ an,
-- trash:purge entfernt die Zeilen nach Ablauf von TRASH_RETENTION_DAYS endgültig.
--
-- Ein gelöschtes Kapitel behält seine Aufgaben, eine gelöschte Spalte ihre
-- Werte - beides verschwindet nur aus der Ansicht und kommt beim
-- Wiederherstellen vollständig zurück.
ALTER TABLE tasks ADD COLUMN deleted_at TEXT;
ALTER TABLE categories ADD COLUMN deleted_at TEXT;
ALTER TABLE log_entries ADD COLUMN deleted_at TEXT;
ALTER TABLE log_columns ADD COLUMN deleted_at TEXT;

CREATE INDEX idx_tasks_deleted_at ON tasks(deleted_at) WHERE deleted_at IS NOT NULL;
CREATE INDEX idx_categories_deleted_at ON categories(deleted_at) WHERE deleted_at IS NOT NULL;
CREATE INDEX idx_log_entries_deleted_at ON log_entries(deleted_at) WHERE deleted_at IS NOT NULL;
CREATE INDEX idx_log_columns_deleted_at ON log_columns(deleted_at) WHERE deleted_at IS NOT NULL;
