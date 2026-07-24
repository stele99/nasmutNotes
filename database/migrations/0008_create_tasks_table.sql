CREATE TABLE tasks (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id       INTEGER NOT NULL,
    title             TEXT NOT NULL,
    description       TEXT,
    responsible       TEXT,
    link              TEXT,
    position          INTEGER NOT NULL DEFAULT 0,
    is_done           INTEGER NOT NULL DEFAULT 0,
    due_date          TEXT,
    priority          TEXT CHECK (priority IN ('low', 'medium', 'high')),
    import_batch_id   INTEGER,
    created_at        TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at        TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    FOREIGN KEY (import_batch_id) REFERENCES import_batches(id) ON DELETE SET NULL
);

CREATE INDEX idx_tasks_category_position ON tasks(category_id, position);
CREATE INDEX idx_tasks_import_batch_id ON tasks(import_batch_id);
