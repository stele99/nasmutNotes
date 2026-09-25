-- Notizbuch-Teilnehmer bekommen eine Rechtestufe: `write` (Vorgabe, bisheriges
-- Verhalten) oder `read` - etwa für Auszubildende oder Subunternehmer, die
-- Pläne und Aufgaben sehen, aber nichts ändern sollen.
ALTER TABLE notebook_shares ADD COLUMN permission TEXT NOT NULL DEFAULT 'write'
    CHECK (permission IN ('read', 'write'));
