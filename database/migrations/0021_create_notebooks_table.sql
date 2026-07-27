CREATE TABLE notebooks (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL,
    name         TEXT NOT NULL,
    name_key     TEXT NOT NULL,
    sort_order   INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    UNIQUE (workspace_id, name_key)
);

ALTER TABLE pages ADD COLUMN notebook_id INTEGER REFERENCES notebooks(id) ON DELETE SET NULL;

CREATE INDEX idx_notebooks_workspace_sort ON notebooks(workspace_id, sort_order, name COLLATE NOCASE);
CREATE INDEX idx_pages_notebook_id ON pages(notebook_id);
CREATE INDEX idx_pages_workspace_notebook_deleted_updated
    ON pages(workspace_id, notebook_id, deleted_at, updated_at DESC);
