-- Phase 8: Benutzerverwaltung
ALTER TABLE users ADD COLUMN active INTEGER NOT NULL DEFAULT 1;
ALTER TABLE users ADD COLUMN updated_at DATETIME;

UPDATE users SET updated_at = created_at WHERE updated_at IS NULL OR updated_at = '';
UPDATE users SET active = 1 WHERE active IS NULL;
