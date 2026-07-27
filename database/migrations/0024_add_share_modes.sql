ALTER TABLE share_links ADD COLUMN mode TEXT NOT NULL DEFAULT 'write'
    CHECK (mode IN ('read', 'write', 'read_copy'));

UPDATE share_links
SET mode = CASE permission WHEN 'write' THEN 'write' ELSE 'read' END;

CREATE INDEX idx_share_links_active_mode ON share_links(mode, revoked_at, expires_at);
