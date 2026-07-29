ALTER TABLE users ADD COLUMN location_capture_mode TEXT
    CHECK (location_capture_mode IS NULL OR location_capture_mode IN ('manual', 'auto'));
