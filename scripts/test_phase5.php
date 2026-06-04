<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/modules/snapshots/SnapshotRepository.php';
require_once dirname(__DIR__) . '/modules/snapshots/SnapshotService.php';
require_once dirname(__DIR__) . '/modules/imports/ImportValidator.php';
require_once dirname(__DIR__) . '/modules/imports/ImportRepository.php';
require_once dirname(__DIR__) . '/modules/imports/CsvImporter.php';
require_once dirname(__DIR__) . '/modules/rankings/RankingRepository.php';
require_once dirname(__DIR__) . '/modules/rankings/RankingEngine.php';
require_once dirname(__DIR__) . '/modules/products/ProductRepository.php';
require_once dirname(__DIR__) . '/modules/shop/ShopFetcher.php';
require_once dirname(__DIR__) . '/modules/shop/ShopHtmlParser.php';
require_once dirname(__DIR__) . '/modules/shop/OwnShopRepository.php';
require_once dirname(__DIR__) . '/modules/products/product_http.php';
require_once dirname(__DIR__) . '/modules/shop/ShopUrlValidator.php';

$pdo = Database::getConnection();
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

$snapshotRepo = new SnapshotRepository($pdo);
$service = new SnapshotService($snapshotRepo);

$competitorName = 'Phase5-Shop-' . bin2hex(random_bytes(3));
$stmt = $pdo->prepare(
    'INSERT INTO competitors (name, active, priority, is_test, created_at, updated_at)
     VALUES (:name, 1, 0, 0, datetime(\'now\'), datetime(\'now\'))'
);
$stmt->execute(['name' => $competitorName]);
$competitorId = (int) $pdo->lastInsertId();

$testPzn = '55' . random_int(100000, 999999);
$pdo->prepare(
    'INSERT INTO products (pzn, name, active, created_at, updated_at)
     VALUES (:pzn, \'Phase5 Produkt\', 1, datetime(\'now\'), datetime(\'now\'))'
)->execute(['pzn' => $testPzn]);
$productId = (int) $pdo->lastInsertId();

$beforeCount = $snapshotRepo->countAll();

$id1 = $service->captureSnapshot($productId, $competitorId, 10.0, 2.0, 'lieferbar');
$id2 = $service->captureSnapshot($productId, $competitorId, 9.5, 2.0, 'lieferbar');

check($id1 > 0 && $id2 > 0 && $id1 !== $id2, 'Jeder captureSnapshot erzeugt neuen Datensatz', $failures);
check($snapshotRepo->countAll() === $beforeCount + 2, 'countAll steigt um 2', $failures);

$byProduct = $snapshotRepo->findByProduct($productId, 10);
check(count($byProduct) >= 2, 'findByProduct liefert Snapshots', $failures);
check(
    (float) ($byProduct[0]['price'] ?? 0) === 9.5 || (float) ($byProduct[0]['price'] ?? 0) === 10.0,
    'findByProduct sortiert (neueste zuerst)',
    $failures,
);

$indexes = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'price_snapshots'")
    ->fetchAll(PDO::FETCH_COLUMN);
check(
    in_array('idx_snapshots_product', $indexes, true) && in_array('idx_snapshots_capture', $indexes, true),
    'Snapshot-Indexe vorhanden',
    $failures,
);

$importRepo = new ImportRepository($pdo, $service);
$validator = new ImportValidator();
$importer = new CsvImporter($importRepo, $validator);

$csv = dirname(__DIR__) . '/storage/imports/test_phase5.csv';
file_put_contents($csv, <<<CSV
pzn;competitor;price;shipping_cost;availability
{$testPzn};{$competitorName};8.99;1.50;lieferbar
CSV);

$preview = $importer->buildPreview($csv, 'test_phase5.csv');
$result = $importer->importPreview($preview, 1);

$rankingEngine = new RankingEngine(new RankingRepository($pdo));
$rankingEngine->runForProduct($productId);

$afterImport = $snapshotRepo->findByProduct($productId);
check(($result['imported'] ?? 0) === 1, 'Import erzeugt Snapshot über SnapshotService', $failures);
check(count($afterImport) >= 3, 'Import fügt weiteren Snapshot hinzu (kein Überschreiben)', $failures);

$ranked = (int) $pdo->query(
    'SELECT COUNT(*) FROM price_snapshots WHERE product_id = ' . $productId . ' AND ranking IS NOT NULL'
)->fetchColumn();
check($ranked >= 1, 'Ranking nach Import gesetzt', $failures);

$pagination = $snapshotRepo->findPaginated(1, 50);
check(isset($pagination['rows'], $pagination['total'], $pagination['totalPages']), 'findPaginated Struktur', $failures);

@unlink($csv);

$productRepo = new ProductRepository($pdo);
$shopSync = createShopSyncService(
    $pdo,
    $productRepo,
    new ShopUrlValidator('shop.apotheker-seidel.de'),
    5,
    'Eigener Shop',
);
$storePzn = '66' . random_int(100000, 999999);
$storeId = $productRepo->create([
    'pzn' => $storePzn,
    'name' => 'Neu mit Shoppreis',
    'manufacturer' => 'Test',
    'cost_price' => null,
    'sale_price' => 14.99,
    'min_price' => null,
    'target_rank' => null,
    'strategy' => null,
    'category' => null,
    'active' => 1,
    'shop_url' => 'https://shop.apotheker-seidel.de/product?artnr=' . $storePzn,
    'package_size' => '1 St',
    'avp_price' => 16.99,
    'own_shipping_cost' => 0,
]);
$bootstrap = bootstrapOwnShopSnapshotAfterSave($storeId, [
    'pzn' => $storePzn,
    'name' => 'Neu mit Shoppreis',
    'sale_price' => 14.99,
    'avp_price' => 16.99,
    'active' => 1,
    'own_shipping_cost' => 0,
], $shopSync);
check($bootstrap['attempted'] && $bootstrap['success'], 'Nach Produkt-Anlegen: Snapshot + Ranking', $failures);

$ownId = (int) $pdo->query("SELECT id FROM competitors WHERE type = 'own' LIMIT 1")->fetchColumn();
$ownSnapCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM price_snapshots WHERE product_id = ' . $storeId . ' AND competitor_id = ' . $ownId
)->fetchColumn();
check($ownSnapCount >= 1, 'Eigen-Shop-Snapshot nach Anlegen in DB', $failures);

echo "\nPhase 5 Snapshots: " . ($failures === 0 ? 'BESTANDEN' : 'FEHLGESCHLAGEN') . "\n";
exit($failures > 0 ? 1 : 0);
