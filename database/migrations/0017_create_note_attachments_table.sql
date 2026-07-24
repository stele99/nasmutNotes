CREATE TABLE note_attachments (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id       INTEGER NOT NULL,
    token_hash    TEXT NOT NULL,
    storage_name  TEXT NOT NULL,
    original_name TEXT,
    mime_type     TEXT NOT NULL CHECK (mime_type IN ('image/png', 'image/jpeg', 'image/webp')),
    byte_size     INTEGER NOT NULL,
    width         INTEGER NOT NULL,
    height        INTEGER NOT NULL,
    created_by    INTEGER,
    created_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX idx_note_attachments_token_hash ON note_attachments(token_hash);
CREATE UNIQUE INDEX idx_note_attachments_storage_name ON note_attachments(storage_name);
CREATE INDEX idx_note_attachments_page_id ON note_attachments(page_id);
