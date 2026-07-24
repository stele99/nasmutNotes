CREATE TABLE share_links (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id           INTEGER NOT NULL,
    token_hash        TEXT NOT NULL,
    permission        TEXT NOT NULL CHECK (permission IN ('read', 'write')),
    password_hash     TEXT,
    requires_login    INTEGER NOT NULL DEFAULT 0,
    expires_at        TEXT,
    revoked_at        TEXT,
    last_accessed_at  TEXT,
    access_count      INTEGER NOT NULL DEFAULT 0,
    created_at        TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX idx_share_links_token_hash ON share_links(token_hash);
CREATE INDEX idx_share_links_page_id ON share_links(page_id);
