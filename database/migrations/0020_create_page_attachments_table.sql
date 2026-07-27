-- Dateianhänge einer Seite (FR-NOTE-18). Anders als note_attachments, die als
-- Bildknoten im Dokument stecken, hängen diese an der Seite selbst und werden
-- unter der Überschrift als Badges gezeigt.
CREATE TABLE page_attachments (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id        INTEGER NOT NULL,
    token_hash     TEXT NOT NULL,
    storage_name   TEXT NOT NULL,
    original_name  TEXT NOT NULL,
    mime_type      TEXT NOT NULL,
    byte_size      INTEGER NOT NULL,
    created_by     INTEGER,
    created_at     TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX idx_page_attachments_token_hash ON page_attachments(token_hash);
CREATE UNIQUE INDEX idx_page_attachments_storage_name ON page_attachments(storage_name);
CREATE INDEX idx_page_attachments_page_id ON page_attachments(page_id);
