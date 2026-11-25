-- MCP-Zugang (FR-MCP): Eigene Token für KI-Clients, die nasmut notes über das
-- Model Context Protocol bedienen. Bewusst getrennt von device_tokens - ein
-- MCP-Token öffnet weder NotesVoice noch den Assistant-Proxy und umgekehrt.
--
-- write_mode legt fest, was der Client schreiben darf:
--   none     nur lesen
--   selected nur Seiten, die der Nutzer selbst für MCP freigegeben hat
--            (mcp_page_grants, Schalter im Teilen-Dialog der Seite)
--   all      jede Seite, die der Nutzer auch selbst bearbeiten darf
-- can_create erlaubt zusätzlich das Anlegen neuer Notizen.
CREATE TABLE mcp_tokens (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    label TEXT NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    write_mode TEXT NOT NULL DEFAULT 'none' CHECK (write_mode IN ('none', 'selected', 'all')),
    can_create INTEGER NOT NULL DEFAULT 0 CHECK (can_create IN (0, 1)),
    created_at TEXT NOT NULL,
    last_used_at TEXT,
    revoked_at TEXT
);

CREATE INDEX idx_mcp_tokens_user ON mcp_tokens(user_id);

-- Schreibfreigabe einzelner Seiten für die MCP-Clients eines Nutzers. Je
-- Nutzer statt je Seite, damit auch Empfänger einer Schreibfreigabe für sich
-- selbst entscheiden, ohne die Clients des Eigentümers zu berühren.
CREATE TABLE mcp_page_grants (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    created_at TEXT NOT NULL,
    PRIMARY KEY (user_id, page_id)
);

CREATE INDEX idx_mcp_page_grants_page ON mcp_page_grants(page_id);
