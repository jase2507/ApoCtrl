<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/modules/rankings/RankingRepository.php';
require_once dirname(__DIR__) . '/modules/rankings/RankingEngine.php';

function logError(string $message): void
{
    // Testscript: no-op logger to keep RankingEngine independent from bootstrap.
}

$testDir = dirname(__DIR__) . '/storage/database/_test';
if (!is_dir($testDir)) {
    mkdir($testDir, 0755, true);
}
$testDb = $testDir . '/phase4_test.sqlite';
@unlink($testDb);

$pdo = new PDO('sqlite:' . $testDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');

$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, pzn TEXT UNIQUE, name TEXT)');
$pdo->exec('CREATE TABLE competitors (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, url TEXT)');
$pdo->exec('CREATE TABLE price_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    competitor_id INTEGER NOT NULL,
    price REAL,
    shipping_cost REAL,
    delivery_status TEXT,
    ranking INTEGER,
    captured_at DATETIME NOT NULL
)');

$repo = new RankingRepository($pdo);
$engine = new RankingEngine($repo);
$failures = 0;

function ok(bool $condition, string $label, int &$failures): void
{
    if ($condition) {
        echo "[OK] {$label}\n";
        return;
    }
    $failures++;
    echo "[FAIL] {$label}\n";
}

function addCompetitor(PDO $pdo, string $name, string $url = ''): int
{
    $stmt = $pdo->prepare('INSERT INTO competitors (name, url) VALUES (:name, :url)');
    $stmt->execute(['name' => $name, 'url' => $url !== '' ? $url : null]);
    return (int) $pdo->lastInsertId();
}

function addProduct(PDO $pdo, string $pzn): int
{
    $stmt = $pdo->prepare('INSERT INTO products (pzn, name) VALUES (:pzn, :name)');
    $stmt->execute(['pzn' => $pzn, 'name' => 'Produkt ' . $pzn]);
    return (int) $pdo->lastInsertId();
}

function addSnapshot(PDO $pdo, int $productId, int $competitorId, float $price, float $shipping, string $status, string $capturedAt): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO price_snapshots (product_id, competitor_id, price, shipping_cost, delivery_status, ranking, captured_at)
         VALUES (:product_id, :competitor_id, :price, :shipping_cost, :delivery_status, NULL, :captured_at)'
    );
    $stmt->execute([
        'product_id' => $productId,
        'competitor_id' => $competitorId,
        'price' => $price,
        'shipping_cost' => $shipping,
        'delivery_status' => $status,
        'captured_at' => $capturedAt,
    ]);
}

$doc = addCompetitor($pdo, 'DocMorris', 'https://docmorris.de');
$shop = addCompetitor($pdo, 'Shop Apotheke', 'https://shop-apotheke.com');
$medi = addCompetitor($pdo, 'Medizinfuchs', 'https://medizinfuchs.de');

$pznA = addProduct($pdo, '00012345');
$pznB = addProduct($pdo, '12345678');
$pznC = addProduct($pdo, '99999999');

$groupCurrent = '2026-06-02 12:10:00';
$groupOld = '2026-06-01 08:00:00';

// 00012345
addSnapshot($pdo, $pznA, $doc, 12.99, 3.99, 'lieferbar', $groupCurrent);   // 16.98
addSnapshot($pdo, $pznA, $shop, 11.99, 3.99, 'lieferbar', $groupCurrent);  // 15.98
addSnapshot($pdo, $pznA, $medi, 10.99, 0.00, 'lieferbar', $groupCurrent);   // 10.99

// 12345678
addSnapshot($pdo, $pznB, $doc, 19.99, 3.99, 'lieferbar', $groupCurrent);    // 23.98
addSnapshot($pdo, $pznB, $shop, 19.99, 3.99, 'available', $groupCurrent);   // 23.98
addSnapshot($pdo, $pznB, $medi, 21.99, 0.00, 'sofort verfügbar', $groupCurrent); // 21.99

// 99999999
addSnapshot($pdo, $pznC, $medi, 8.99, 0.00, 'lieferbar', $groupCurrent);    // 8.99
addSnapshot($pdo, $pznC, $shop, 9.49, 0.00, 'in stock', $groupCurrent);     // 9.49
addSnapshot($pdo, $pznC, $doc, 8.99, 3.99, 'lieferbar', $groupCurrent);      // 12.98

// alte Gruppe zur Historien-/Gruppenprüfung
addSnapshot($pdo, $pznC, $doc, 7.99, 1.00, 'lieferbar', $groupOld);
addSnapshot($pdo, $pznC, $shop, 6.99, 0.50, 'out of stock', $groupOld);

$before = (int) $pdo->query('SELECT COUNT(*) FROM price_snapshots')->fetchColumn();
$engine->runAll();
$engine->runAll();
$after = (int) $pdo->query('SELECT COUNT(*) FROM price_snapshots')->fetchColumn();
ok($before === $after, 'Mehrfachlauf erzeugt keine Duplikate', $failures);

function rankingFor(PDO $pdo, string $pzn, string $competitor, string $capturedAt): ?int
{
    $stmt = $pdo->prepare(
        'SELECT ps.ranking
         FROM price_snapshots ps
         JOIN products p ON p.id = ps.product_id
         JOIN competitors c ON c.id = ps.competitor_id
         WHERE p.pzn = :pzn AND c.name = :name AND ps.captured_at = :captured_at
         LIMIT 1'
    );
    $stmt->execute(['pzn' => $pzn, 'name' => $competitor, 'captured_at' => $capturedAt]);
    $rank = $stmt->fetchColumn();
    if ($rank === false || $rank === null) {
        return null;
    }
    return (int) $rank;
}

ok(rankingFor($pdo, '00012345', 'Medizinfuchs', $groupCurrent) === 1, '00012345 Medizinfuchs Rang 1', $failures);
ok(rankingFor($pdo, '00012345', 'Shop Apotheke', $groupCurrent) === 2, '00012345 Shop Apotheke Rang 2', $failures);
ok(rankingFor($pdo, '00012345', 'DocMorris', $groupCurrent) === 3, '00012345 DocMorris Rang 3', $failures);

ok(rankingFor($pdo, '12345678', 'Medizinfuchs', $groupCurrent) === 1, '12345678 Medizinfuchs Rang 1', $failures);
ok(rankingFor($pdo, '12345678', 'DocMorris', $groupCurrent) === 2, '12345678 DocMorris Rang 2', $failures);
ok(rankingFor($pdo, '12345678', 'Shop Apotheke', $groupCurrent) === 2, '12345678 Shop Apotheke Rang 2', $failures);

ok(rankingFor($pdo, '99999999', 'Medizinfuchs', $groupCurrent) === 1, '99999999 Medizinfuchs Rang 1', $failures);
ok(rankingFor($pdo, '99999999', 'Shop Apotheke', $groupCurrent) === 2, '99999999 Shop Apotheke Rang 2', $failures);
ok(rankingFor($pdo, '99999999', 'DocMorris', $groupCurrent) === 3, '99999999 DocMorris Rang 3', $failures);

ok(rankingFor($pdo, '99999999', 'DocMorris', $groupOld) === 1, 'Alte captured_at-Gruppe getrennt gerankt', $failures);
ok(rankingFor($pdo, '99999999', 'Shop Apotheke', $groupOld) === null, 'Nicht lieferbar in alter Gruppe ignoriert', $failures);

exit($failures > 0 ? 1 : 0);
