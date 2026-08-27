-- Desktop-Assistant-Paarung: Eine Pairing-Sitzung beginnt auf dem Client
-- (POST /api/assistant/pair) und wird vom Nutzer in der Web-App bestätigt.
-- Codes liegen ausschließlich gehasht ab; der Anzeige-Code (user_code) landet
-- in einer Browser-URL, der device_code bleibt beim Client. Erst der
-- Bestätigungs-Poll erzeugt den Token und liefert ihn genau einmal aus.
CREATE TABLE device_pair_requests (
    id INTEGER PRIMARY KEY,
    user_code_hash TEXT NOT NULL UNIQUE,
    device_code_hash TEXT NOT NULL UNIQUE,
    client_id TEXT NOT NULL,
    label TEXT NOT NULL,
    platform TEXT,
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    approved_user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
    approved_at TEXT,
    consumed_at TEXT,
    token_id INTEGER
);

CREATE INDEX idx_device_pair_requests_expires ON device_pair_requests(expires_at);
CREATE INDEX idx_device_pair_requests_client ON device_pair_requests(client_id);
