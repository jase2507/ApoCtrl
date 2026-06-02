<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/modules/products/ProductRepository.php';
require_once dirname(__DIR__) . '/modules/products/ProductValidator.php';
require_once dirname(__DIR__) . '/modules/competitors/CompetitorRepository.php';
require_once dirname(__DIR__) . '/modules/competitors/CompetitorValidator.php';

$testDir = dirname(__DIR__) . '/storage/database/_test';
if (!is_dir($testDir)) {
    mkdir($testDir, 0755, true);
}
$testDb = $testDir . '/phase2_test.sqlite';
@unlink($testDb);

$pdo = new PDO('sqlite:' . $testDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('CREATE TABLE products (
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
    active INTEGER,
    created_at DATETIME,
    updated_at DATETIME
)');
$pdo->exec('CREATE TABLE competitors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT,
    url TEXT,
    priority INTEGER,
    active INTEGER,
    notes TEXT,
    created_at DATETIME,
    updated_at DATETIME
)');
$pdo->exec('CREATE TABLE price_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    competitor_id INTEGER NOT NULL,
    price REAL,
    shipping_cost REAL,
    delivery_status TEXT,
    ranking INTEGER,
    captured_at DATETIME
)');

$products = new ProductRepository($pdo);
$productVal = new ProductValidator($products);
$competitorRepo = new CompetitorRepository($pdo);
$compVal = new CompetitorValidator($competitorRepo);

$fail = 0;

$r1 = $productVal->validate(['pzn' => '', 'name' => 'Test']);
if ($r1['errors'] === []) {
    echo "[FAIL] PZN Pflicht\n";
    $fail++;
} else {
    echo "[OK] PZN Pflicht\n";
}

$r2 = $productVal->validate([
    'pzn' => '99999999',
    'name' => 'Test',
    'min_price' => '10',
    'sale_price' => '5',
]);
if (!str_contains(implode(' ', $r2['errors']), 'Mindestpreis')) {
    echo "[FAIL] min_price > sale_price\n";
    $fail++;
} else {
    echo "[OK] min_price Validierung\n";
}

$r3 = $compVal->validate(['name' => '', 'url' => '']);
if ($r3['errors'] === []) {
    echo "[FAIL] Name Pflicht\n";
    $fail++;
} else {
    echo "[OK] Name Pflicht Wettbewerber\n";
}

$r4 = $compVal->validate(['name' => 'Test', 'url' => 'keine-url']);
if ($r4['errors'] === []) {
    echo "[FAIL] URL ungültig\n";
    $fail++;
} else {
    echo "[OK] URL Validierung\n";
}

$cols = $pdo->query('PRAGMA table_info(competitors)')->fetchAll();
$hasNotes = false;
foreach ($cols as $c) {
    if (($c['name'] ?? '') === 'notes') {
        $hasNotes = true;
    }
}
echo $hasNotes ? "[OK] competitors.notes Spalte\n" : "[FAIL] competitors.notes fehlt\n";
if (!$hasNotes) {
    $fail++;
}

// Delete behavior tests
$now = date('Y-m-d H:i:s');
$createCompetitor = $pdo->prepare(
    'INSERT INTO competitors (name, url, priority, active, notes, created_at, updated_at)
     VALUES (:name, NULL, 0, 1, NULL, :created_at, :updated_at)'
);

$createCompetitor->execute([
    'name' => 'DeleteMe-' . uniqid(),
    'created_at' => $now,
    'updated_at' => $now,
]);
$deleteCandidateId = (int) $pdo->lastInsertId();
$canDelete = !$competitorRepo->hasReferences($deleteCandidateId);
$deleted = $competitorRepo->deleteById($deleteCandidateId);
if ($canDelete && $deleted) {
    echo "[OK] Unbenutzter Wettbewerber kann gelöscht werden\n";
} else {
    echo "[FAIL] Unbenutzter Wettbewerber-Löschung fehlgeschlagen\n";
    $fail++;
}

$createCompetitor->execute([
    'name' => 'UsedComp-' . uniqid(),
    'created_at' => $now,
    'updated_at' => $now,
]);
$usedCompetitorId = (int) $pdo->lastInsertId();
$productStmt = $pdo->prepare(
    'INSERT INTO products (pzn, name, active, created_at, updated_at)
     VALUES (:pzn, :name, 1, :created_at, :updated_at)'
);
$productStmt->execute([
    'pzn' => 'T' . random_int(1000000, 9999999),
    'name' => 'Ref Product',
    'created_at' => $now,
    'updated_at' => $now,
]);
$productId = (int) $pdo->lastInsertId();
$snapshotStmt = $pdo->prepare(
    'INSERT INTO price_snapshots (product_id, competitor_id, price, shipping_cost, delivery_status, ranking, captured_at)
     VALUES (:product_id, :competitor_id, 1.0, 0.0, :delivery_status, NULL, :captured_at)'
);
$snapshotStmt->execute([
    'product_id' => $productId,
    'competitor_id' => $usedCompetitorId,
    'delivery_status' => 'lieferbar',
    'captured_at' => $now,
]);

if ($competitorRepo->hasReferences($usedCompetitorId)) {
    echo "[OK] Verwendeter Wettbewerber wird als referenziert erkannt\n";
} else {
    echo "[FAIL] Referenzprüfung für Wettbewerber fehlgeschlagen\n";
    $fail++;
}

exit($fail > 0 ? 1 : 0);
