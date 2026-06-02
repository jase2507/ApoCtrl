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
        self::addColumnIfNotExists($pdo, 'import_logs', 'status', "TEXT NOT NULL DEFAULT 'running'");
        self::ensureUniqueCompetitorNameIndex($pdo);
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

        $insert = $pdo->prepare(
            'INSERT INTO users (username, password_hash, role, created_at)
             VALUES (:username, :password_hash, :role, :created_at)'
        );

        $insert->execute([
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
