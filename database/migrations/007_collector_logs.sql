-- Phase 7.1: Abruf-Logs für Medizinfuchs-Collector
CREATE TABLE IF NOT EXISTS collector_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id INTEGER,
    pzn TEXT,
    url TEXT,
    http_code INTEGER,
    duration_ms INTEGER,
    status TEXT,
    error_message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
