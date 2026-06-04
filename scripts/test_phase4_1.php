<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/schema.php';
require_once dirname(__DIR__) . '/modules/products/ProductRepository.php';
require_once dirname(__DIR__) . '/modules/shop/ShopUrlValidator.php';
require_once dirname(__DIR__) . '/modules/shop/ShopHtmlParser.php';
require_once dirname(__DIR__) . '/modules/shop/OwnShopRepository.php';
require_once dirname(__DIR__) . '/modules/shop/ShopSyncService.php';
require_once dirname(__DIR__) . '/modules/shop/ShopFetcher.php';
require_once dirname(__DIR__) . '/modules/rankings/RankingRepository.php';
require_once dirname(__DIR__) . '/modules/rankings/RankingEngine.php';

$testDir = dirname(__DIR__) . '/storage/database/_test';
if (!is_dir($testDir)) {
    mkdir($testDir, 0755, true);
}
$testDb = $testDir . '/phase4_1_test.sqlite';
@unlink($testDb);

$pdo = new PDO('sqlite:' . $testDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');

foreach (require dirname(__DIR__) . '/core/schema.php' as $sql) {
    $pdo->exec($sql);
}

$pdo->exec("INSERT INTO competitors (name, url, type, priority, active, is_test, created_at, updated_at)
    VALUES ('Eigener Shop', 'https://shop.apotheker-seidel.de/', 'own', -100, 1, 0, datetime('now'), datetime('now'))");
$pdo->exec("INSERT INTO competitors (name, url, type, priority, active, is_test, created_at, updated_at)
    VALUES ('Medizinfuchs', 'https://example.com', NULL, 10, 1, 0, datetime('now'), datetime('now'))");

$fail = 0;
$validator = new ShopUrlValidator('shop.apotheker-seidel.de');
$parser = new ShopHtmlParser();
$fixtureHtml = (string) file_get_contents(dirname(__DIR__) . '/docs/examples/shop_sync_fixture.html');

if ($validator->isAllowed('https://shop.apotheker-seidel.de/produkt/01580241')) {
    echo "[OK] gültige Shop-URL wird akzeptiert\n";
} else {
    echo "[FAIL] gültige Shop-URL\n";
    $fail++;
}

if (!$validator->isAllowed('https://evil.example.com/p/1')) {
    echo "[OK] fremde URL wird abgelehnt\n";
} else {
    echo "[FAIL] fremde URL sollte abgelehnt werden\n";
    $fail++;
}

try {
    $parsed = $parser->parse($fixtureHtml, '01580241');
    if (abs((float) ($parsed['price'] ?? 0) - 8.75) < 0.001) {
        echo "[OK] Preis wird extrahiert\n";
    } else {
        echo "[FAIL] Preis extrahiert: " . ($parsed['price'] ?? 'null') . "\n";
        $fail++;
    }
} catch (Throwable $e) {
    echo "[FAIL] Parser: " . $e->getMessage() . "\n";
    $fail++;
}

$products = new ProductRepository($pdo);
$rankingRepo = new RankingRepository($pdo);
$sync = new ShopSyncService(
    $products,
    new OwnShopRepository($pdo),
    $validator,
    new ShopFetcher(5),
    $parser,
    new RankingEngine($rankingRepo),
);

$now = date('Y-m-d H:i:s');
$products->create([
    'pzn' => '01580241',
    'name' => 'Manuell gepflegter Name',
    'manufacturer' => 'Bestehend AG',
    'cost_price' => null,
    'sale_price' => 9.99,
    'min_price' => null,
    'target_rank' => null,
    'strategy' => null,
    'category' => null,
    'active' => 1,
    'shop_url' => 'https://shop.apotheker-seidel.de/test/01580241',
    'package_size' => '30 St',
    'avp_price' => 11.50,
    'own_shipping_cost' => 2.50,
]);
$productId = (int) $pdo->lastInsertId();

$before = $products->findById($productId);
$syncResult = $sync->syncProduct($productId, $fixtureHtml);
$after = $products->findById($productId);

if ($syncResult['success']) {
    echo "[OK] Shop-Sync erfolgreich\n";
} else {
    echo "[FAIL] Shop-Sync: " . $syncResult['message'] . "\n";
    $fail++;
}

if (($after['name'] ?? '') === 'Manuell gepflegter Name') {
    echo "[OK] gepflegter Produktname bleibt erhalten\n";
} else {
    echo "[FAIL] Produktname wurde überschrieben\n";
    $fail++;
}

if (($after['package_size'] ?? '') === '30 St') {
    echo "[OK] gepflegte Packungsgröße bleibt erhalten\n";
} else {
    echo "[FAIL] Packungsgröße wurde überschrieben\n";
    $fail++;
}

if (abs((float) ($after['sale_price'] ?? 0) - 9.99) < 0.001) {
    echo "[OK] gepflegter Verkaufspreis bleibt erhalten\n";
} else {
    echo "[FAIL] Verkaufspreis wurde überschrieben\n";
    $fail++;
}

if (($after['shop_sync_status'] ?? '') === 'ok') {
    echo "[OK] Sync-Status OK gespeichert\n";
} else {
    echo "[FAIL] Sync-Status\n";
    $fail++;
}

$ownId = (int) $pdo->query("SELECT id FROM competitors WHERE type = 'own' LIMIT 1")->fetchColumn();
$snap = $pdo->prepare(
    'SELECT COUNT(*) FROM price_snapshots WHERE product_id = :pid AND competitor_id = :cid'
);
$snap->execute(['pid' => $productId, 'cid' => $ownId]);
if ((int) $snap->fetchColumn() === 1) {
    echo "[OK] eigener Snapshot wurde erzeugt\n";
} else {
    echo "[FAIL] eigener Snapshot fehlt\n";
    $fail++;
}

$competitorId = (int) $pdo->query("SELECT id FROM competitors WHERE name = 'Medizinfuchs' LIMIT 1")->fetchColumn();
$pdo->prepare(
    'INSERT INTO price_snapshots (product_id, competitor_id, price, shipping_cost, delivery_status, ranking, captured_at)
     VALUES (:pid, :cid, 10.50, 0, :status, NULL, :captured)'
)->execute([
    'pid' => $productId,
    'cid' => $competitorId,
    'status' => 'lieferbar',
    'captured' => $now,
]);

$engine = new RankingEngine($rankingRepo);
$engine->runForProduct($productId);

$rankRows = $pdo->prepare(
    'SELECT c.name, c.type, ps.ranking FROM price_snapshots ps
     INNER JOIN competitors c ON c.id = ps.competitor_id
     WHERE ps.product_id = :pid ORDER BY ps.captured_at DESC'
);
$rankRows->execute(['pid' => $productId]);
$rows = $rankRows->fetchAll(PDO::FETCH_ASSOC);
$ownRanked = false;
foreach ($rows as $row) {
    if (($row['type'] ?? '') === 'own' && $row['ranking'] !== null) {
        $ownRanked = true;
        break;
    }
}
if ($ownRanked) {
    echo "[OK] eigener Shop erscheint im Ranking\n";
} else {
    echo "[FAIL] eigener Shop nicht gerankt\n";
    $fail++;
}

$products->create([
    'pzn' => '99990001',
    'name' => 'Fehlerprodukt',
    'manufacturer' => null,
    'cost_price' => null,
    'sale_price' => null,
    'min_price' => null,
    'target_rank' => null,
    'strategy' => null,
    'category' => null,
    'active' => 1,
    'shop_url' => 'https://shop.apotheker-seidel.de/fehler',
    'package_size' => null,
    'avp_price' => null,
    'own_shipping_cost' => 0,
]);
$errorProductId = (int) $pdo->lastInsertId();
$beforeName = (string) ($products->findById($errorProductId)['name'] ?? '');
$badSync = $sync->syncProduct($errorProductId, '<html><body>Kein Produktblock</body></html>');
$afterError = $products->findById($errorProductId);

if (!$badSync['success'] && ($afterError['shop_sync_status'] ?? '') === 'error' && ($afterError['shop_sync_error'] ?? '') !== '') {
    echo "[OK] Fehler wird gespeichert\n";
} else {
    echo "[FAIL] Fehlerspeicherung\n";
    $fail++;
}

if (($afterError['name'] ?? '') === $beforeName) {
    echo "[OK] bei Fehler bleiben Stammdaten unverändert\n";
} else {
    echo "[FAIL] Stammdaten bei Fehler geändert\n";
    $fail++;
}

$ownCount = (int) $pdo->query("SELECT COUNT(*) FROM competitors WHERE type = 'own'")->fetchColumn();
if ($ownCount === 1) {
    echo "[OK] nur ein type=own Anbieter\n";
} else {
    echo "[FAIL] type=own Anzahl: {$ownCount}\n";
    $fail++;
}

exit($fail > 0 ? 1 : 0);
