<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/modules/products/ProductRepository.php';
require_once dirname(__DIR__) . '/modules/products/ProductValidator.php';
require_once dirname(__DIR__) . '/modules/competitors/CompetitorRepository.php';
require_once dirname(__DIR__) . '/modules/competitors/CompetitorValidator.php';

$pdo = Database::getConnection();
$products = new ProductRepository($pdo);
$productVal = new ProductValidator($products);
$compVal = new CompetitorValidator();

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

exit($fail > 0 ? 1 : 0);
