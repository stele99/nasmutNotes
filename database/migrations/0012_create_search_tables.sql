-- Reintext-Spiegel aller durchsuchbaren Objekte (siehe URS Kap. 6.2)
CREATE TABLE search_documents (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id  INTEGER NOT NULL,
    object_type   TEXT NOT NULL CHECK (object_type IN ('page', 'task')),
    object_id     INTEGER NOT NULL,
    page_id       INTEGER NOT NULL,
    title         TEXT NOT NULL DEFAULT '',
    body          TEXT NOT NULL DEFAULT '',
    meta          TEXT NOT NULL DEFAULT '',
    is_deleted    INTEGER NOT NULL DEFAULT 0,
    updated_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE (object_type, object_id)
);

CREATE INDEX idx_search_documents_workspace_deleted ON search_documents(workspace_id, is_deleted);

CREATE VIRTUAL TABLE search_fts USING fts5(
    title, body, meta,
    content       = 'search_documents',
    content_rowid = 'id',
    tokenize      = "unicode61 remove_diacritics 2"
);

CREATE TRIGGER search_documents_ai AFTER INSERT ON search_documents BEGIN
    INSERT INTO search_fts(rowid, title, body, meta)
    VALUES (new.id, new.title, new.body, new.meta);
END;

CREATE TRIGGER search_documents_ad AFTER DELETE ON search_documents BEGIN
    INSERT INTO search_fts(search_fts, rowid, title, body, meta)
    VALUES ('delete', old.id, old.title, old.body, old.meta);
END;

CREATE TRIGGER search_documents_au AFTER UPDATE ON search_documents BEGIN
    INSERT INTO search_fts(search_fts, rowid, title, body, meta)
    VALUES ('delete', old.id, old.title, old.body, old.meta);
    INSERT INTO search_fts(rowid, title, body, meta)
    VALUES (new.id, new.title, new.body, new.meta);
END;
