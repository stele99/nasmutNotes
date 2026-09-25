-- Logbuch-Einträge bekommen wie Aufgaben (0018) eine Versionsnummer: Zwei
-- gleichzeitige Korrekturen an derselben Stundenzeile überschreiben sich nicht
-- mehr still, die zweite erhält einen Konflikt.
ALTER TABLE log_entries ADD COLUMN version INTEGER NOT NULL DEFAULT 1;

-- Offline angelegte Einträge und Aufgaben tragen eine vom Gerät erzeugte UUID.
-- Kommt dieselbe Anlage nach einem Verbindungsabbruch ein zweites Mal an,
-- liefert der Server den vorhandenen Datensatz statt eines Duplikats (analog
-- pages.client_uuid, 0045).
ALTER TABLE log_entries ADD COLUMN client_uuid TEXT;
ALTER TABLE tasks ADD COLUMN client_uuid TEXT;

CREATE UNIQUE INDEX idx_log_entries_client_uuid ON log_entries(client_uuid);
CREATE UNIQUE INDEX idx_tasks_client_uuid ON tasks(client_uuid);
