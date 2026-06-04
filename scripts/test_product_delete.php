<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/modules/products/ProductRepository.php';
require_once dirname(__DIR__) . '/modules/products/ProductValidator.php';
require_once dirname(__DIR__) . '/modules/competitors/CompetitorRepository.php';
require_once dirname(__DIR__) . '/modules/snapshots/SnapshotRepository.php';
require_once dirname(__DIR__) . '/modules/snapshots/SnapshotService.php';

$pdo = Database::getConnection();
Database::initializeSchema($pdo);

$products = new ProductRepository($pdo);
$validator = new ProductValidator($products);
$competitors = new CompetitorRepository($pdo);
$snapshots = new SnapshotService(new SnapshotRepository($pdo));

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

$competitorId = (int) $pdo->query("SELECT id FROM competitors WHERE active = 1 ORDER BY id ASC LIMIT 1")->fetchColumn();
if ($competitorId <= 0) {
    $pdo->exec(
        "INSERT INTO competitors (name, active, priority, is_test, created_at, updated_at)
         VALUES ('Delete Test Apo', 1, 0, 1, datetime('now'), datetime('now'))"
    );
    $competitorId = (int) $pdo->lastInsertId();
}
$competitorCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM competitors')->fetchColumn();

$pzn = '82' . random_int(100000, 999999);
$result = $validator->validate([
    'pzn' => $pzn,
    'name' => 'Delete Test Produkt',
    'manufacturer' => 'Test GmbH',
    'sale_price' => '12.50',
    'min_price' => '10.00',
    'active' => '1',
]);
$productId = $products->create($result['data']);

$snapshots->captureSnapshot($productId, $competitorId, 12.50, 0.0, 'lieferbar');
$pdo->prepare(
    'INSERT INTO own_price_snapshots (product_id, price, captured_at) VALUES (:pid, 12.50, datetime(\'now\'))'
)->execute(['pid' => $productId]);

check($products->findById($productId) !== null, 'Produkt angelegt', $failures);
check(
    (int) $pdo->query('SELECT COUNT(*) FROM price_snapshots WHERE product_id = ' . $productId)->fetchColumn() >= 1,
    'Snapshots erzeugt',
    $failures,
);
check(
    (int) $pdo->query('SELECT COUNT(*) FROM own_price_snapshots WHERE product_id = ' . $productId)->fetchColumn() >= 1,
    'Own-Shop-Snapshot erzeugt',
    $failures,
);

check($products->deleteProduct($productId), 'Produkt löschen', $failures);
check($products->findById($productId) === null, 'Produkt weg', $failures);
check(
    (int) $pdo->query('SELECT COUNT(*) FROM price_snapshots WHERE product_id = ' . $productId)->fetchColumn() === 0,
    'Preis-Snapshots weg',
    $failures,
);
check(
    (int) $pdo->query('SELECT COUNT(*) FROM own_price_snapshots WHERE product_id = ' . $productId)->fetchColumn() === 0,
    'Own-Shop-Snapshots weg',
    $failures,
);
check(
    (int) $pdo->query('SELECT COUNT(*) FROM competitors')->fetchColumn() === $competitorCountBefore,
    'Wettbewerber bleiben erhalten',
    $failures,
);

echo $failures === 0 ? "PRODUCT DELETE TESTS BESTANDEN\n" : "PRODUCT DELETE TESTS FEHLGESCHLAGEN ({$failures})\n";
exit($failures === 0 ? 0 : 1);
