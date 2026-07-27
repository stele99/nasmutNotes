-- Speicherkontingent je Nutzer; NULL bedeutet "Standardwert aus app_settings".
ALTER TABLE users ADD COLUMN storage_quota_mb INTEGER;

-- Schlüssel/Wert-Ablage für zur Laufzeit änderbare Einstellungen. Bislang nur
-- das Standard-Speicherkontingent, bewusst allgemein gehalten.
CREATE TABLE app_settings (
    key         TEXT PRIMARY KEY,
    value       TEXT NOT NULL,
    updated_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);
