CREATE TABLE note_contents (
    page_id       INTEGER PRIMARY KEY,
    content       TEXT NOT NULL DEFAULT '{"type":"doc","content":[]}',
    content_text  TEXT NOT NULL DEFAULT '',
    version       INTEGER NOT NULL DEFAULT 1,
    updated_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
);
