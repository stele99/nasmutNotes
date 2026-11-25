-- Notizbücher für die MCP-Clients eines Nutzers sperren (FR-MCP-08): Ein
-- gesperrtes Notizbuch und alle Seiten darin sind für dessen Clients
-- unsichtbar - weder auflisten, suchen, lesen noch schreiben oder anlegen,
-- unabhängig vom Schreibmodus des Tokens und von Seitenfreigaben. Wie die
-- Freigaben je Nutzer; Sperre und Notizbuch-Freigabe schließen sich aus
-- (McpTokenService::setNotebookMode).
CREATE TABLE mcp_notebook_exclusions (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    notebook_id INTEGER NOT NULL REFERENCES notebooks(id) ON DELETE CASCADE,
    created_at TEXT NOT NULL,
    PRIMARY KEY (user_id, notebook_id)
);

CREATE INDEX idx_mcp_notebook_exclusions_notebook ON mcp_notebook_exclusions(notebook_id);
