ALTER TABLE pages
ADD COLUMN is_encrypted INTEGER NOT NULL DEFAULT 0
CHECK (is_encrypted IN (0, 1));
