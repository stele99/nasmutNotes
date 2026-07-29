ALTER TABLE users ADD COLUMN nearby_search_radius_km REAL NOT NULL DEFAULT 1.0
    CHECK (nearby_search_radius_km >= 0.1 AND nearby_search_radius_km <= 50.0);
