-- Logbuch-Seiten (FR-LOG-01..09): frei definierbare Spalten, Einträge mit
-- Zeitpunkt und je Spalte einem Wert.

CREATE TABLE log_columns (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id     INTEGER NOT NULL,
    name        TEXT NOT NULL,
    -- text = Freitext, location = Ort, time = Uhrzeit, hours = Stunden,
    -- number = Zahl, money = Betrag in Euro.
    type        TEXT NOT NULL CHECK (type IN ('text', 'location', 'time', 'hours', 'number', 'money')),
    position    INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
);

CREATE INDEX idx_log_columns_page_position ON log_columns(page_id, position);

CREATE TABLE log_entries (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id     INTEGER NOT NULL,
    -- Datum und Uhrzeit des Eintrags in UTC; vom Nutzer änderbar und damit
    -- unabhängig von created_at.
    occurred_at TEXT NOT NULL,
    created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    created_by  INTEGER,
    FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX idx_log_entries_page_occurred ON log_entries(page_id, occurred_at DESC);

-- Ein Wert je Eintrag und Spalte. Text und Zahl stehen getrennt, damit nach
-- Zahlenspalten richtig sortiert und summiert werden kann; Ortsspalten legen
-- zusätzlich die Koordinaten ab.
CREATE TABLE log_values (
    entry_id     INTEGER NOT NULL,
    column_id    INTEGER NOT NULL,
    value_text   TEXT,
    value_number REAL,
    value_lat    REAL,
    value_lon    REAL,
    PRIMARY KEY (entry_id, column_id),
    FOREIGN KEY (entry_id) REFERENCES log_entries(id) ON DELETE CASCADE,
    FOREIGN KEY (column_id) REFERENCES log_columns(id) ON DELETE CASCADE
);

CREATE INDEX idx_log_values_column ON log_values(column_id);
