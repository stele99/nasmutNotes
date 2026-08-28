-- Diktier-Vorlagen: benannte Anweisungen ans LLM, wie ein Diktat aufbereitet
-- werden soll (z.B. als Angebot mit Position/Menge/Preis), samt optionalem
-- Vokabular für Transkription und Nachbearbeitung. user_id NULL kennzeichnet
-- eine globale, von einem Admin gepflegte Vorlage; sonst gehört sie dem
-- angegebenen Nutzer.
CREATE TABLE voice_templates (
    id INTEGER PRIMARY KEY,
    user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    instruction TEXT NOT NULL,
    vocabulary TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

CREATE INDEX idx_voice_templates_user ON voice_templates(user_id);

-- Sorgt dafür, dass Diktat für Notizen direkt nach dem Update ohne
-- weiteres Zutun nutzbar bleibt: eine globale Vorlage, die dem bisherigen
-- Verhalten (nur bereinigen, nicht inhaltlich umformen) entspricht.
INSERT INTO voice_templates (user_id, name, instruction, vocabulary)
VALUES (
    NULL,
    'Standard',
    'Bereinige und strukturiere das Diktat wie eine normale Notiz, ohne besondere inhaltliche Umformatierung.',
    ''
);
