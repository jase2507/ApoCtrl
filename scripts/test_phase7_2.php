<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/modules/collector/collector_factory.php';
require_once dirname(__DIR__) . '/modules/collector/MedizinfuchsParser.php';

$pdo = Database::getConnection();
Database::initializeSchema($pdo);

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

$fixturesDir = dirname(__DIR__) . '/docs/examples';
$liveFixture = $fixturesDir . '/medizinfuchs_collector_live_realistic.html';
$html = (string) file_get_contents($liveFixture);

$parser = new MedizinfuchsParser();
$offers = $parser->parse($html);
$debug = $parser->getLastParseDebug();

check(count($offers) >= 1, 'mindestens 1 Anbieter erkannt', $failures);

$variantA = null;
$variantB = null;
foreach ($offers as $offer) {
    if (($offer['competitor'] ?? '') === 'Shop Apotheke') {
        $variantA = $offer;
    }
    if (($offer['competitor'] ?? '') === 'DocMorris') {
        $variantB = $offer;
    }
    check(is_numeric($offer['price'] ?? null), 'Preis numerisch (' . ($offer['competitor'] ?? '?') . ')', $failures);
    check(is_numeric($offer['shipping_cost'] ?? null), 'Versand numerisch (' . ($offer['competitor'] ?? '?') . ')', $failures);
}

check($variantA !== null && abs($variantA['price'] - 12.20) < 0.01, 'Variante A Preis 12,20', $failures);
check($variantA !== null && abs($variantA['shipping_cost'] - 3.99) < 0.01, 'Variante A Versand 3,99', $failures);
check($variantB !== null && abs($variantB['price'] - 23.32) < 0.01, 'Variante B Preis 23,32', $failures);
check($variantB !== null && abs($variantB['shipping_cost'] - 0.0) < 0.01, 'Variante B versandkostenfrei', $failures);

check(($debug['product']['pzn'] ?? '') === '13889185', 'Produktdaten PZN (Debug)', $failures);
check(!empty($debug['product']['name']), 'Produktdaten Name (Debug)', $failures);

$testPzn = '79' . random_int(100000, 999999);
copy($liveFixture, $fixturesDir . '/medizinfuchs_collector_' . $testPzn . '.html');

$pdo->prepare(
    'INSERT INTO products (pzn, name, active, is_test, created_at, updated_at)
     VALUES (:pzn, \'Collector PH72 Live Parser\', 1, 1, datetime(\'now\'), datetime(\'now\'))'
)->execute(['pzn' => $testPzn]);
$productId = (int) $pdo->lastInsertId();

$config = require dirname(__DIR__) . '/config/config.php';
$config['collector'] = array_merge($config['collector'] ?? [], [
    'mock_mode' => true,
    'request_delay_ms' => 0,
]);
$service = createCollectorService($pdo, $config);

$beforeSnapshots = (int) $pdo->query('SELECT COUNT(*) FROM price_snapshots')->fetchColumn();
$result = $service->runForPzn($testPzn);

check($result['success'], 'Snapshot-Lauf erfolgreich', $failures);
check(($result['snapshots_created'] ?? 0) >= 1, 'Snapshot erzeugt', $failures);
check((int) $pdo->query('SELECT COUNT(*) FROM price_snapshots')->fetchColumn() > $beforeSnapshots, 'Snapshot in DB', $failures);

$seidelSnap = (int) $pdo->query(
    'SELECT COUNT(*) FROM price_snapshots ps
     JOIN competitors c ON c.id = ps.competitor_id
     WHERE ps.product_id = ' . $productId . " AND LOWER(c.name) LIKE '%apotheker seidel%'"
)->fetchColumn();
check($seidelSnap === 0, 'Eigen-Shop ohne Snapshot', $failures);

$ranked = (int) $pdo->query(
    'SELECT COUNT(*) FROM price_snapshots WHERE product_id = ' . $productId . ' AND ranking IS NOT NULL'
)->fetchColumn();
check($ranked >= 1, 'Ranking berechnet', $failures);

check(isset($result['parse_debug']['offers']) && count($result['parse_debug']['offers']) >= 1, 'parse_debug vorhanden', $failures);

$mockOffers = $parser->parse((string) file_get_contents($fixturesDir . '/medizinfuchs_collector_16609329.html'));
check(count($mockOffers) >= 3, 'Mock data-* weiterhin kompatibel', $failures);

@unlink($fixturesDir . '/medizinfuchs_collector_' . $testPzn . '.html');

echo $failures === 0 ? "PHASE 7.2 TESTS BESTANDEN\n" : "PHASE 7.2 TESTS FEHLGESCHLAGEN ({$failures})\n";
exit($failures === 0 ? 0 : 1);
