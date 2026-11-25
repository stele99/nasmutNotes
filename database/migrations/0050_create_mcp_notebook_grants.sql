-- Schreibfreigabe ganzer Notizbücher für die MCP-Clients eines Nutzers
-- (FR-MCP-03). Gilt für alle Seiten, die gerade im Notizbuch liegen - auch
-- für später hinzukommende; wird eine Seite herausverschoben, endet die
-- Freigabe für sie. Wie mcp_page_grants je Nutzer, damit Teilnehmer eines
-- geteilten Notizbuchs nur für ihre eigenen Clients entscheiden.
CREATE TABLE mcp_notebook_grants (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    notebook_id INTEGER NOT NULL REFERENCES notebooks(id) ON DELETE CASCADE,
    created_at TEXT NOT NULL,
    PRIMARY KEY (user_id, notebook_id)
);

CREATE INDEX idx_mcp_notebook_grants_notebook ON mcp_notebook_grants(notebook_id);
