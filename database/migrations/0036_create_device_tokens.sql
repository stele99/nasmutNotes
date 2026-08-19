-- Automations-Token für NotesVoice (FR-NVOICE): getrennt vom Session-Cookie,
-- ausschließlich für POST /api/voice/quick gültig, jederzeit widerrufbar.
CREATE TABLE device_tokens (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    label TEXT NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL,
    last_used_at TEXT,
    revoked_at TEXT
);

CREATE INDEX idx_device_tokens_user ON device_tokens(user_id);
