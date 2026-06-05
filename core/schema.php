<?php

declare(strict_types=1);

/**
 * ApoCtrl – Datenbankschema (SQLite)
 * Vollständiges Schema gemäß Spezifikation; Phase 1 nutzt users + audit_logs aktiv.
 */

return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'User',
    active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    pzn TEXT UNIQUE,
    name TEXT,
    manufacturer TEXT,
    cost_price REAL,
    sale_price REAL,
    min_price REAL,
    target_rank INTEGER,
    strategy TEXT,
    category TEXT,
    active INTEGER NOT NULL DEFAULT 1,
    is_test INTEGER NOT NULL DEFAULT 0,
    shop_url TEXT,
    package_size TEXT,
    avp_price REAL,
    own_shipping_cost REAL NOT NULL DEFAULT 0,
    last_shop_sync_at DATETIME,
    shop_sync_status TEXT,
    shop_sync_error TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS competitors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    url TEXT,
    type TEXT,
    priority INTEGER DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    is_test INTEGER NOT NULL DEFAULT 0,
    notes TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS price_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    competitor_id INTEGER NOT NULL,
    price REAL,
    shipping_cost REAL DEFAULT 0,
    delivery_status TEXT,
    ranking INTEGER,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (competitor_id) REFERENCES competitors(id)
)
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS own_price_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    price REAL,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id)
)
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS suggestions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    current_price REAL,
    suggested_price REAL,
    reason TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id)
)
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS alerts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER,
    type TEXT,
    priority TEXT,
    message TEXT,
    status TEXT NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id)
)
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    action TEXT NOT NULL,
    details TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
)
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS import_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename TEXT NOT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME,
    record_count INTEGER DEFAULT 0,
    error_count INTEGER DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'running',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS export_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    export_type TEXT NOT NULL,
    filename TEXT,
    record_count INTEGER DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS collector_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    started_at DATETIME NOT NULL,
    finished_at DATETIME,
    products_processed INTEGER DEFAULT 0,
    snapshots_created INTEGER DEFAULT 0,
    errors INTEGER DEFAULT 0,
    status TEXT NOT NULL
)
SQL,
    <<<'SQL'
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
)
SQL,
];
