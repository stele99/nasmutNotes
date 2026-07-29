-- Zum Aufnahmeort ermittelte Anschrift (FR-NOTE-26). Bleibt leer, wenn die
-- Adresssuche abgeschaltet oder nicht erreichbar ist.
ALTER TABLE pages ADD COLUMN location_label TEXT;
