-- Phase 7.3: Medizinfuchs URL-Auflösung
ALTER TABLE collector_logs ADD COLUMN resolved_url TEXT;
ALTER TABLE collector_logs ADD COLUMN source_url TEXT;
