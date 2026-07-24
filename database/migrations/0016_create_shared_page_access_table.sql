CREATE TABLE shared_page_access (
    user_id          INTEGER NOT NULL,
    share_link_id    INTEGER NOT NULL,
    created_at       TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    last_accessed_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    PRIMARY KEY (user_id, share_link_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (share_link_id) REFERENCES share_links(id) ON DELETE CASCADE
);

CREATE INDEX idx_shared_page_access_user ON shared_page_access(user_id);
CREATE INDEX idx_shared_page_access_share_link ON shared_page_access(share_link_id);
