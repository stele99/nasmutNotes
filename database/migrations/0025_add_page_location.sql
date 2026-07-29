-- Aufnahmeort einer Notiz (FR-NOTE-25). Optional: Ohne Einwilligung des Nutzers
-- oder ohne Ortungsdienst bleiben die Spalten leer.
ALTER TABLE pages ADD COLUMN location_lat REAL;
ALTER TABLE pages ADD COLUMN location_lon REAL;
-- Genauigkeit in Metern, wie sie der Browser meldet.
ALTER TABLE pages ADD COLUMN location_accuracy REAL;
ALTER TABLE pages ADD COLUMN location_at TEXT;
