<?php

declare(strict_types=1);

/**
 * ApoCtrl – SQLite-Datenbankverbindung (PDO)
 */

class Database
{
    private static ?PDO $connection = null;

    /**
     * @param array<string, mixed> $config
     */
    public static function connect(array $config): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $dbPath = (string) $config['database']['path'];
        $dbDir = dirname($dbPath);

        if (!is_dir($dbDir)) {
            if (!@mkdir($dbDir, 0755, true) && !is_dir($dbDir)) {
                throw new RuntimeException(
                    'Datenbankverzeichnis konnte nicht erstellt werden: ' . $dbDir
                );
            }
        }

        if (!is_writable($dbDir)) {
            throw new RuntimeException(
                'Datenbankverzeichnis ist nicht beschreibbar: ' . $dbDir
            );
        }

        $dsn = 'sqlite:' . $dbPath;

        try {
            self::$connection = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Datenbankverbindung fehlgeschlagen: ' . $e->getMessage(),
                0,
                $e
            );
        }

        self::$connection->exec('PRAGMA foreign_keys = ON');
        self::$connection->exec('PRAGMA journal_mode = WAL');

        return self::$connection;
    }

    public static function getConnection(): PDO
    {
        if (!self::$connection instanceof PDO) {
            throw new RuntimeException('Datenbankverbindung nicht initialisiert.');
        }

        return self::$connection;
    }

    public static function initializeSchema(PDO $pdo): void
    {
        $statements = require __DIR__ . '/schema.php';

        foreach ($statements as $sql) {
            $pdo->exec($sql);
        }

        self::runMigrations($pdo);
    }

    public static function runMigrations(PDO $pdo): void
    {
        self::addColumnIfNotExists($pdo, 'competitors', 'notes', 'TEXT');
        self::addColumnIfNotExists($pdo, 'competitors', 'is_test', 'INTEGER NOT NULL DEFAULT 0');
        self::addColumnIfNotExists($pdo, 'competitors', 'type', 'TEXT');
        self::addColumnIfNotExists($pdo, 'products', 'shop_url', 'TEXT');
        self::addColumnIfNotExists($pdo, 'products', 'package_size', 'TEXT');
        self::addColumnIfNotExists($pdo, 'products', 'avp_price', 'REAL');
        self::addColumnIfNotExists($pdo, 'products', 'own_shipping_cost', 'REAL NOT NULL DEFAULT 0');
        self::addColumnIfNotExists($pdo, 'products', 'last_shop_sync_at', 'DATETIME');
        self::addColumnIfNotExists($pdo, 'products', 'shop_sync_status', 'TEXT');
        self::addColumnIfNotExists($pdo, 'products', 'shop_sync_error', 'TEXT');
        self::addColumnIfNotExists($pdo, 'products', 'is_test', 'INTEGER NOT NULL DEFAULT 0');
        self::markTestProducts($pdo);
        self::addColumnIfNotExists($pdo, 'import_logs', 'status', "TEXT NOT NULL DEFAULT 'running'");
        self::markPhase4CompetitorsAsTest($pdo);
        self::ensureOwnShopCompetitor($pdo);
        self::ensureUniqueCompetitorNameIndex($pdo);
        self::addColumnIfNotExists($pdo, 'price_snapshots', 'created_at', 'DATETIME');
        self::backfillSnapshotCreatedAt($pdo);
        self::ensureSnapshotIndexes($pdo);
        self::ensureCollectorLogsTable($pdo);
        self::addColumnIfNotExists($pdo, 'users', 'active', 'INTEGER NOT NULL DEFAULT 1');
        self::addColumnIfNotExists($pdo, 'users', 'updated_at', 'DATETIME');
        self::backfillUsersUpdatedAt($pdo);
    }

    private static function backfillUsersUpdatedAt(PDO $pdo): void
    {
        $pdo->exec(
            "UPDATE users SET updated_at = created_at WHERE updated_at IS NULL OR updated_at = ''"
        );
        $pdo->exec(
            'UPDATE users SET active = 1 WHERE active IS NULL'
        );
    }

    private static function ensureCollectorLogsTable(PDO $pdo): void
    {
        $pdo->exec(
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
SQL
        );
    }

    private static function markTestProducts(PDO $pdo): void
    {
        $testPzns = [
            '00012345',
            '11111111',
            '12345678',
            '22222222',
            '55555555',
            '77777777',
            '99999999',
        ];

        $stmt = $pdo->prepare('UPDATE products SET is_test = 1 WHERE pzn = :pzn');
        foreach ($testPzns as $pzn) {
            $stmt->execute(['pzn' => $pzn]);
        }
    }

    private static function backfillSnapshotCreatedAt(PDO $pdo): void
    {
        $pdo->exec(
            'UPDATE price_snapshots SET created_at = captured_at WHERE created_at IS NULL'
        );
    }

    private static function ensureSnapshotIndexes(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_snapshots_product ON price_snapshots (product_id)'
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_snapshots_capture ON price_snapshots (captured_at)'
        );
    }

    private static function addColumnIfNotExists(PDO $pdo, string $table, string $column, string $definition): void
    {
        $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($columns as $col) {
            if (($col['name'] ?? '') === $column) {
                return;
            }
        }

        $pdo->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
    }

    private static function ensureOwnShopCompetitor(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            "SELECT id FROM competitors WHERE type = 'own' ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute();
        $existingId = $stmt->fetchColumn();

        $now = date('Y-m-d H:i:s');

        if ($existingId !== false) {
            $update = $pdo->prepare(
                "UPDATE competitors SET
                    name = 'Eigener Shop',
                    url = 'https://shop.apotheker-seidel.de/',
                    active = 1,
                    priority = -100,
                    is_test = 0,
                    updated_at = :updated_at
                 WHERE id = :id"
            );
            $update->execute([
                'id' => (int) $existingId,
                'updated_at' => $now,
            ]);

            $pdo->exec(
                "UPDATE competitors
                 SET active = 0, updated_at = datetime('now')
                 WHERE type = 'own' AND id != " . (int) $existingId
            );

            return;
        }

        $byName = $pdo->prepare(
            "SELECT id FROM competitors WHERE LOWER(TRIM(name)) = 'eigener shop' ORDER BY id ASC LIMIT 1"
        );
        $byName->execute();
        $nameId = $byName->fetchColumn();

        if ($nameId !== false) {
            $promote = $pdo->prepare(
                "UPDATE competitors SET
                    name = 'Eigener Shop',
                    url = 'https://shop.apotheker-seidel.de/',
                    type = 'own',
                    active = 1,
                    priority = -100,
                    is_test = 0,
                    updated_at = :updated_at
                 WHERE id = :id"
            );
            $promote->execute([
                'id' => (int) $nameId,
                'updated_at' => $now,
            ]);

            return;
        }

        $insert = $pdo->prepare(
            "INSERT INTO competitors (name, url, type, priority, active, is_test, created_at, updated_at)
             VALUES ('Eigener Shop', 'https://shop.apotheker-seidel.de/', 'own', -100, 1, 0, :created_at, :updated_at)"
        );
        $insert->execute([
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private static function markPhase4CompetitorsAsTest(PDO $pdo): void
    {
        $pdo->exec(
            "UPDATE competitors
             SET is_test = 1, active = 0, updated_at = datetime('now')
             WHERE name IN ('Phase4-A', 'Phase4-B', 'Phase4-C', 'Phase4-D')"
        );
    }

    private static function ensureUniqueCompetitorNameIndex(PDO $pdo): void
    {
        $duplicates = (int) $pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT LOWER(TRIM(name)) AS n
                FROM competitors
                GROUP BY LOWER(TRIM(name))
                HAVING COUNT(*) > 1
            ) t"
        )->fetchColumn();

        if ($duplicates > 0) {
            logError('Unique-Index competitors.name übersprungen: vorhandene Duplikate.');
            return;
        }

        $pdo->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_competitors_name_unique
             ON competitors (LOWER(TRIM(name)))'
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function seedDefaultAdmin(PDO $pdo, array $config): void
    {
        $adminConfig = $config['default_admin'] ?? [];

        if (empty($adminConfig['enabled'])) {
            return;
        }

        $password = (string) ($adminConfig['password'] ?? '');

        if ($password === '') {
            return;
        }

        $stmt = $pdo->query('SELECT COUNT(*) FROM users');
        $count = (int) $stmt->fetchColumn();

        if ($count > 0) {
            return;
        }

        $username = (string) ($adminConfig['username'] ?? 'admin');
        $role = (string) ($adminConfig['role'] ?? 'Admin');

        $now = date('Y-m-d H:i:s');
        $insert = $pdo->prepare(
            'INSERT INTO users (username, password_hash, role, active, created_at, updated_at)
             VALUES (:username, :password_hash, :role, 1, :created_at, :updated_at)'
        );

        $insert->execute([
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
