<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/modules/maintenance/MaintenanceRepository.php';
require_once dirname(__DIR__) . '/modules/maintenance/MaintenanceService.php';

$pdo = Database::getConnection();
Database::initializeSchema($pdo);

$repository = new MaintenanceRepository($pdo);
$service = new MaintenanceService($repository);

$failures = 0;

function check(bool $condition, string $label, int &$failures): void
{
    if ($condition) {
        echo "[OK] {$label}\n";
        return;
    }

    $failures++;
    echo "[FAIL] {$label}\n";
}

function insertProduct(PDO $pdo, string $pzn, string $name, int $isTest): int
{
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        'INSERT INTO products (pzn, name, active, is_test, created_at, updated_at)
         VALUES (:pzn, :name, 1, :is_test, :created_at, :updated_at)'
    );
    $stmt->execute([
        'pzn' => $pzn,
        'name' => $name,
        'is_test' => $isTest,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return (int) $pdo->lastInsertId();
}

$competitorId = (int) $pdo->query('SELECT id FROM competitors ORDER BY id ASC LIMIT 1')->fetchColumn();
if ($competitorId <= 0) {
    $pdo->exec(
        "INSERT INTO competitors (name, active, priority, is_test, created_at, updated_at)
         VALUES ('Maintenance Test Apo', 1, 0, 1, datetime('now'), datetime('now'))"
    );
    $competitorId = (int) $pdo->lastInsertId();
}
$competitorCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM competitors')->fetchColumn();
$auditCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();

$testPzn = '91' . random_int(100000, 999999);
$prodPzn = '92' . random_int(100000, 999999);
$testProductId = insertProduct($pdo, $testPzn, 'Maintenance Test Produkt', 1);
$prodProductId = insertProduct($pdo, $prodPzn, 'Maintenance Echtes Produkt', 0);

$pdo->prepare(
    'INSERT INTO price_snapshots (product_id, competitor_id, price, shipping_cost, delivery_status, captured_at)
     VALUES (:pid, :cid, 9.99, 0, \'lieferbar\', datetime(\'now\'))'
)->execute(['pid' => $testProductId, 'cid' => $competitorId]);
$pdo->prepare(
    'INSERT INTO price_snapshots (product_id, competitor_id, price, shipping_cost, delivery_status, captured_at)
     VALUES (:pid, :cid, 19.99, 0, \'lieferbar\', datetime(\'now\'))'
)->execute(['pid' => $prodProductId, 'cid' => $competitorId]);
$pdo->prepare(
    'INSERT INTO own_price_snapshots (product_id, price, captured_at)
     VALUES (:pid, 8.50, datetime(\'now\'))'
)->execute(['pid' => $testProductId]);
$pdo->prepare(
    'INSERT INTO own_price_snapshots (product_id, price, captured_at)
     VALUES (:pid, 18.50, datetime(\'now\'))'
)->execute(['pid' => $prodProductId]);

$_SESSION['auth_user'] = ['id' => 99, 'username' => 'testuser', 'role' => 'User'];
check($service->cleanupTestData() === null, 'Nicht-Admin darf nicht löschen', $failures);
check(
    (int) $pdo->query('SELECT COUNT(*) FROM products WHERE id = ' . $testProductId)->fetchColumn() === 1,
    'Testprodukt bleibt nach Nicht-Admin-Versuch',
    $failures,
);

$_SESSION['auth_user'] = ['id' => 1, 'username' => 'admin', 'role' => 'Admin'];
$result = $service->cleanupTestData();
check($result !== null, 'Admin-Bereinigung ausgeführt', $failures);
check(
    (int) $pdo->query('SELECT COUNT(*) FROM products WHERE id = ' . $testProductId)->fetchColumn() === 0,
    'Testprodukt wird gelöscht',
    $failures,
);
check(
    (int) $pdo->query('SELECT COUNT(*) FROM products WHERE id = ' . $prodProductId)->fetchColumn() === 1,
    'Produktives Produkt bleibt erhalten',
    $failures,
);
check(
    (int) $pdo->query('SELECT COUNT(*) FROM price_snapshots WHERE product_id = ' . $testProductId)->fetchColumn() === 0,
    'Test-Snapshots werden gelöscht',
    $failures,
);
check(
    (int) $pdo->query('SELECT COUNT(*) FROM own_price_snapshots WHERE product_id = ' . $testProductId)->fetchColumn() === 0,
    'Test-Own-Snapshots werden gelöscht',
    $failures,
);
check(
    (int) $pdo->query('SELECT COUNT(*) FROM price_snapshots WHERE product_id = ' . $prodProductId)->fetchColumn() >= 1,
    'Produktive Snapshots bleiben erhalten',
    $failures,
);
check(
    (int) $pdo->query('SELECT COUNT(*) FROM competitors')->fetchColumn() === $competitorCountBefore,
    'Wettbewerber bleiben erhalten',
    $failures,
);
check(
    (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn() === $auditCountBefore,
    'Audit-Logs unverändert (Repository-Test)',
    $failures,
);

$pdo->prepare('DELETE FROM price_snapshots WHERE product_id = :id')->execute(['id' => $prodProductId]);
$pdo->prepare('DELETE FROM own_price_snapshots WHERE product_id = :id')->execute(['id' => $prodProductId]);
$pdo->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $prodProductId]);

echo $failures === 0 ? "MAINTENANCE TESTS BESTANDEN\n" : "MAINTENANCE TESTS FEHLGESCHLAGEN ({$failures})\n";
exit($failures === 0 ? 0 : 1);
