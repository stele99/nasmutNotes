-- migrator:no-transaction
--
-- Der neue Seitentyp "log" muss in die CHECK-Bedingung von pages. SQLite kann
-- Bedingungen nicht ändern, die Tabelle wird deshalb nach dem offiziellen
-- Verfahren neu aufgebaut (https://sqlite.org/lang_altertable.html#otheralter).
--
-- Dafür müssen die Fremdschlüssel abgeschaltet sein: Mit eingeschalteten
-- Fremdschlüsseln führt DROP TABLE ein implizites DELETE aus und räumte über
-- ON DELETE CASCADE sämtliche Notizen, Aufgaben und Freigaben mit weg. Das
-- PRAGMA wirkt nur außerhalb einer Transaktion, daher der Marker oben.
PRAGMA foreign_keys = OFF;

BEGIN;

CREATE TABLE pages_rebuilt (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id      INTEGER NOT NULL,
    type              TEXT NOT NULL CHECK (type IN ('note', 'task', 'log')),
    title             TEXT NOT NULL,
    icon              TEXT,
    is_favorite       INTEGER NOT NULL DEFAULT 0,
    sort_order        INTEGER NOT NULL DEFAULT 0,
    default_view      TEXT NOT NULL DEFAULT 'board' CHECK (default_view IN ('board', 'list')),
    deleted_at        TEXT,
    created_at        TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at        TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    notebook_id       INTEGER,
    location_lat      REAL,
    location_lon      REAL,
    location_accuracy REAL,
    location_at       TEXT,
    location_label    TEXT,
    FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    FOREIGN KEY (notebook_id) REFERENCES notebooks(id) ON DELETE SET NULL
);

INSERT INTO pages_rebuilt (
    id, workspace_id, type, title, icon, is_favorite, sort_order, default_view,
    deleted_at, created_at, updated_at, notebook_id,
    location_lat, location_lon, location_accuracy, location_at, location_label
)
SELECT
    id, workspace_id, type, title, icon, is_favorite, sort_order, default_view,
    deleted_at, created_at, updated_at, notebook_id,
    location_lat, location_lon, location_accuracy, location_at, location_label
FROM pages;

DROP TABLE pages;

ALTER TABLE pages_rebuilt RENAME TO pages;

CREATE INDEX idx_pages_workspace_deleted_updated ON pages(workspace_id, deleted_at, updated_at DESC);
CREATE INDEX idx_pages_notebook_id ON pages(notebook_id);
CREATE INDEX idx_pages_workspace_notebook_deleted_updated
    ON pages(workspace_id, notebook_id, deleted_at, updated_at DESC);

COMMIT;

PRAGMA foreign_keys = ON;
