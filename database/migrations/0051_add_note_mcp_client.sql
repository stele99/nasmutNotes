-- Herkunft von Notizänderungen im Versionsverlauf (FR-MCP-07): Name des
-- MCP-Zugangs, über den der aktuelle Stand bzw. eine gesicherte Fassung
-- geschrieben wurde. NULL = im Editor geschrieben. Bewusst der Name zum
-- Zeitpunkt der Änderung statt einer Token-ID: Der Verlauf bleibt lesbar,
-- auch wenn der Zugang später umbenannt oder widerrufen wird.
ALTER TABLE note_contents ADD COLUMN mcp_client TEXT;
ALTER TABLE note_versions ADD COLUMN mcp_client TEXT;
