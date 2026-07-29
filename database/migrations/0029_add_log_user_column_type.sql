-- migrator:no-transaction
--
-- SQLite kann die CHECK-Bedingung einer Tabelle nicht direkt ändern. Die
-- Spaltendefinition wird deshalb mit dem zusätzlichen Typ "user" neu aufgebaut.
PRAGMA foreign_keys = OFF;

BEGIN;

CREATE TABLE log_columns_rebuilt (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id     INTEGER NOT NULL,
    name        TEXT NOT NULL,
    type        TEXT NOT NULL CHECK (type IN ('text', 'location', 'time', 'hours', 'number', 'money', 'user')),
    position    INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
);

INSERT INTO log_columns_rebuilt (id, page_id, name, type, position, created_at)
SELECT id, page_id, name, type, position, created_at FROM log_columns;

DROP TABLE log_columns;
ALTER TABLE log_columns_rebuilt RENAME TO log_columns;

CREATE INDEX idx_log_columns_page_position ON log_columns(page_id, position);

COMMIT;

PRAGMA foreign_keys = ON;
