CREATE TABLE users (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    google_sub     TEXT NOT NULL,
    email          TEXT NOT NULL,
    name           TEXT NOT NULL DEFAULT '',
    avatar_url     TEXT,
    is_active      INTEGER NOT NULL DEFAULT 1,
    created_at     TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    last_login_at  TEXT
);

CREATE UNIQUE INDEX idx_users_google_sub ON users(google_sub);
CREATE UNIQUE INDEX idx_users_email ON users(email);
