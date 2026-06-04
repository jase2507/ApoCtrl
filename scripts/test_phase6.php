<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/modules/pricing/PricingRepository.php';
require_once dirname(__DIR__) . '/modules/pricing/PricingEngine.php';

$pdo = Database::getConnection();
$repo = new PricingRepository($pdo);
$engine = new PricingEngine($repo);
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

$ownId = (int) $pdo->query("SELECT id FROM competitors WHERE type = 'own' LIMIT 1")->fetchColumn();
if ($ownId <= 0) {
    echo "[FAIL] Eigener Shop fehlt\n";
    exit(1);
}

$capturedAt = date('Y-m-d H:i:s');

$pdo->prepare(
    'INSERT INTO products (pzn, name, active, is_test, min_price, sale_price, target_rank, own_shipping_cost, created_at, updated_at)
     VALUES (:pzn, :name, 1, 1, :min_price, :sale_price, :target_rank, 0, datetime(\'now\'), datetime(\'now\'))'
)->execute([
    'pzn' => 'PH6-RANK1',
    'name' => 'Phase6 Rang1',
    'min_price' => 80.0,
    'sale_price' => 85.49,
    'target_rank' => 1,
]);
$productRank1 = (int) $pdo->lastInsertId();

$pdo->prepare(
    'INSERT INTO competitors (name, active, priority, is_test, created_at, updated_at)
     VALUES (:n1, 1, 0, 1, datetime(\'now\'), datetime(\'now\'))'
)->execute(['n1' => 'Phase6-Shop-A']);
$compA = (int) $pdo->lastInsertId();

$pdo->prepare(
    'INSERT INTO competitors (name, active, priority, is_test, created_at, updated_at)
     VALUES (:n2, 1, 0, 1, datetime(\'now\'), datetime(\'now\'))'
)->execute(['n2' => 'Phase6-DocMorris']);
$compB = (int) $pdo->lastInsertId();

$insertSnap = $pdo->prepare(
    'INSERT INTO price_snapshots (product_id, competitor_id, price, shipping_cost, delivery_status, ranking, captured_at, created_at)
     VALUES (:pid, :cid, :price, 0, \'lieferbar\', :ranking, :captured_at, datetime(\'now\'))'
);

$insertSnap->execute(['pid' => $productRank1, 'cid' => $compA, 'price' => 84.99, 'ranking' => 1, 'captured_at' => $capturedAt]);
$insertSnap->execute(['pid' => $productRank1, 'cid' => $compB, 'price' => 85.20, 'ranking' => 2, 'captured_at' => $capturedAt]);
$insertSnap->execute(['pid' => $productRank1, 'cid' => $ownId, 'price' => 85.49, 'ranking' => 3, 'captured_at' => $capturedAt]);

$s1 = $engine->suggestPrice($productRank1);
check(abs((float) ($s1['suggested_price'] ?? 0) - 84.98) < 0.001, 'Ziel Rang 1 → 84,98', $failures);
check(str_contains((string) ($s1['reason'] ?? ''), 'senken'), 'Ziel Rang 1 Begründung', $failures);

$pdo->prepare('UPDATE products SET target_rank = 2 WHERE id = :id')->execute(['id' => $productRank1]);
$s2 = $engine->suggestPrice($productRank1);
check(abs((float) ($s2['suggested_price'] ?? 0) - 85.19) < 0.001, 'Ziel Rang 2 → 85,19', $failures);

$pdo->prepare('UPDATE products SET min_price = 86.00 WHERE id = :id')->execute(['id' => $productRank1]);
$pdo->prepare('UPDATE products SET target_rank = 1 WHERE id = :id')->execute(['id' => $productRank1]);
$s3 = $engine->suggestPrice($productRank1);
check(abs((float) ($s3['suggested_price'] ?? 0) - 86.00) < 0.001, 'Mindestpreis begrenzt Vorschlag', $failures);
check(str_contains((string) ($s3['reason'] ?? ''), 'Mindestpreis'), 'Mindestpreis Begründung', $failures);

$pdo->prepare(
    'INSERT INTO products (pzn, name, active, is_test, sale_price, target_rank, own_shipping_cost, created_at, updated_at)
     VALUES (\'PH6-NOOWN\', \'Ohne Eigenpreis\', 1, 1, 10.00, 1, 0, datetime(\'now\'), datetime(\'now\'))'
)->execute();
$productNoOwn = (int) $pdo->lastInsertId();
$insertSnap->execute(['pid' => $productNoOwn, 'cid' => $compA, 'price' => 9.99, 'ranking' => 1, 'captured_at' => $capturedAt]);

$s4 = $engine->suggestPrice($productNoOwn);
check((string) ($s4['reason'] ?? '') === 'Kein eigener Preis vorhanden', 'Kein eigener Snapshot', $failures);

$pdo->prepare(
    'INSERT INTO products (pzn, name, active, is_test, sale_price, target_rank, own_shipping_cost, created_at, updated_at)
     VALUES (\'PH6-OK\', \'Ziel erreicht\', 1, 1, 84.50, 2, 0, datetime(\'now\'), datetime(\'now\'))'
)->execute();
$productOk = (int) $pdo->lastInsertId();
$insertSnap->execute(['pid' => $productOk, 'cid' => $compA, 'price' => 85.00, 'ranking' => 2, 'captured_at' => $capturedAt]);
$insertSnap->execute(['pid' => $productOk, 'cid' => $ownId, 'price' => 84.50, 'ranking' => 1, 'captured_at' => $capturedAt]);

$s5 = $engine->suggestPrice($productOk);
check((string) ($s5['reason'] ?? '') === 'Zielranking bereits erreicht', 'Ziel bereits erreicht', $failures);
check(abs((float) ($s5['suggested_price'] ?? 0) - 84.50) < 0.001, 'Preis unverändert bei Ziel erreicht', $failures);

echo "\n" . ($failures === 0 ? 'PHASE 6 TESTS BESTANDEN' : 'PHASE 6 TESTS FEHLGESCHLAGEN') . "\n";
exit($failures > 0 ? 1 : 0);
