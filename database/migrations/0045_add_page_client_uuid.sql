-- Offline angelegte Seiten tragen eine clientseitig erzeugte UUID (FR-OFFLINE).
-- Der Server erkennt daran einen erneuten Übertragungsversuch, dessen Antwort
-- verloren ging, und liefert die bereits angelegte Seite zurück, statt ein
-- Duplikat anzulegen. Bestandsseiten bleiben NULL; SQLite erlaubt in einem
-- Unique-Index beliebig viele NULLs.
ALTER TABLE pages ADD COLUMN client_uuid TEXT;
CREATE UNIQUE INDEX idx_pages_client_uuid ON pages(client_uuid);
