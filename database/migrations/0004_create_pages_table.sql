CREATE TABLE pages (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id  INTEGER NOT NULL,
    type          TEXT NOT NULL CHECK (type IN ('note', 'task')),
    title         TEXT NOT NULL,
    icon          TEXT,
    is_favorite   INTEGER NOT NULL DEFAULT 0,
    sort_order    INTEGER NOT NULL DEFAULT 0,
    default_view  TEXT NOT NULL DEFAULT 'board' CHECK (default_view IN ('board', 'list')),
    deleted_at    TEXT,
    created_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
);

CREATE INDEX idx_pages_workspace_deleted_updated ON pages(workspace_id, deleted_at, updated_at DESC);
