CREATE TABLE rate_limits (
    rate_key      TEXT PRIMARY KEY,
    attempt_count INTEGER NOT NULL DEFAULT 0,
    window_start  TEXT NOT NULL
);
