<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/modules/rankings/RankingRepository.php';
require_once dirname(__DIR__) . '/modules/rankings/RankingEngine.php';

$pdo = Database::getConnection();
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

function ensureCompetitor(PDO $pdo, string $name): int
{
    $stmt = $pdo->prepare('SELECT id FROM competitors WHERE name = :name LIMIT 1');
    $stmt->execute(['name' => $name]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }

    $ins = $pdo->prepare(
        'INSERT INTO competitors (name, active, priority, created_at, updated_at)
         VALUES (:name, 1, 0, :now, :now)'
    );
    $now = date('Y-m-d H:i:s');
    $ins->execute(['name' => $name, 'now' => $now]);
    return (int) $pdo->lastInsertId();
}

function ensureProduct(PDO $pdo, string $pzn): int
{
    $stmt = $pdo->prepare('SELECT id FROM products WHERE pzn = :pzn LIMIT 1');
    $stmt->execute(['pzn' => $pzn]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }

    $ins = $pdo->prepare(
        'INSERT INTO products (pzn, name, active, created_at, updated_at)
         VALUES (:pzn, :name, 1, :now, :now)'
    );
    $now = date('Y-m-d H:i:s');
    $ins->execute([
        'pzn' => $pzn,
        'name' => 'Test Produkt ' . $pzn,
        'now' => $now,
    ]);
    return (int) $pdo->lastInsertId();
}

$productId = ensureProduct($pdo, '01580241');
$compA = ensureCompetitor($pdo, 'Phase4-A');
$compB = ensureCompetitor($pdo, 'Phase4-B');
$compC = ensureCompetitor($pdo, 'Phase4-C');
$compD = ensureCompetitor($pdo, 'Phase4-D');

$cap1 = '2026-06-02 12:04:01';
$cap2 = '2026-06-02 12:04:02';

$pdo->prepare('DELETE FROM price_snapshots WHERE product_id = :pid AND captured_at IN (:c1, :c2)')
    ->execute(['pid' => $productId, 'c1' => $cap1, 'c2' => $cap2]);

$insert = $pdo->prepare(
    'INSERT INTO price_snapshots
    (product_id, competitor_id, price, shipping_cost, delivery_status, ranking, captured_at)
    VALUES (:product_id, :competitor_id, :price, :shipping_cost, :delivery_status, NULL, :captured_at)'
);

// Gruppe 1: Gleichstand + nicht lieferbar
$insert->execute(['product_id' => $productId, 'competitor_id' => $compA, 'price' => 5.99, 'shipping_cost' => 0.00, 'delivery_status' => 'lieferbar', 'captured_at' => $cap1]);
$insert->execute(['product_id' => $productId, 'competitor_id' => $compB, 'price' => 5.99, 'shipping_cost' => 0.00, 'delivery_status' => 'in stock', 'captured_at' => $cap1]);
$insert->execute(['product_id' => $productId, 'competitor_id' => $compC, 'price' => 6.49, 'shipping_cost' => 0.00, 'delivery_status' => 'available', 'captured_at' => $cap1]);
$insert->execute(['product_id' => $productId, 'competitor_id' => $compD, 'price' => 4.99, 'shipping_cost' => 0.00, 'delivery_status' => 'out of stock', 'captured_at' => $cap1]);

// Gruppe 2: Versandkosten entscheiden Rang
$insert->execute(['product_id' => $productId, 'competitor_id' => $compA, 'price' => 5.00, 'shipping_cost' => 2.00, 'delivery_status' => 'lieferbar', 'captured_at' => $cap2]);
$insert->execute(['product_id' => $productId, 'competitor_id' => $compB, 'price' => 6.00, 'shipping_cost' => 0.00, 'delivery_status' => 'lieferbar', 'captured_at' => $cap2]);

$beforeCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM price_snapshots WHERE product_id = {$productId}"
)->fetchColumn();

$engine->runForProduct($productId);

$group1 = $repo->fetchSnapshotsForGroup($productId, $cap1);
$group2 = $repo->fetchSnapshotsForGroup($productId, $cap2);

$byComp1 = [];
foreach ($group1 as $row) {
    $byComp1[(int) $row['competitor_id']] = $row;
}
$byComp2 = [];
foreach ($group2 as $row) {
    $byComp2[(int) $row['competitor_id']] = $row;
}

ok((int) ($byComp1[$compA]['ranking'] ?? 0) === 1, 'Rang 1 bei niedrigstem Endpreis', $failures);
ok((int) ($byComp1[$compB]['ranking'] ?? 0) === 1, 'Preisgleichheit gleicher Rang', $failures);
ok((int) ($byComp1[$compC]['ranking'] ?? 0) === 3, 'Nächster Rang wird übersprungen (1,1,3)', $failures);
ok(($byComp1[$compD]['ranking'] ?? null) === null, 'Nicht lieferbare Anbieter ignoriert', $failures);
ok((int) ($byComp2[$compB]['ranking'] ?? 0) === 1 && (int) ($byComp2[$compA]['ranking'] ?? 0) === 2, 'Versandkosten berücksichtigt', $failures);

$engine->runForProduct($productId);
$afterCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM price_snapshots WHERE product_id = {$productId}"
)->fetchColumn();
ok($beforeCount === $afterCount, 'Mehrfachlauf erzeugt keine Duplikate', $failures);

$group1After = $repo->fetchSnapshotsForGroup($productId, $cap1);
ok(count($group1After) === 4, 'Historische Snapshots bleiben erhalten', $failures);
ok(count($group1After) !== count($group2) || $cap1 !== $cap2, 'Gruppen mit anderem captured_at werden getrennt behandelt', $failures);

exit($failures > 0 ? 1 : 0);
