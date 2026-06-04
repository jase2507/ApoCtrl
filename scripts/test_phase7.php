<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/modules/collector/collector_factory.php';
require_once dirname(__DIR__) . '/modules/rankings/RankingRepository.php';

$pdo = Database::getConnection();
$config = require dirname(__DIR__) . '/config/config.php';
$config['collector'] = array_merge($config['collector'] ?? [], ['mock_mode' => true]);

$service = createCollectorService($pdo, $config);
$collectorRepo = new CollectorRepository($pdo);
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
$emptyPzn = '88' . random_int(100000, 999999);
file_put_contents(
    $fixturesDir . '/medizinfuchs_collector_' . $emptyPzn . '.html',
    '<!DOCTYPE html><html><body></body></html>'
);

$testPzn = '77' . random_int(100000, 999999);
copy(
    $fixturesDir . '/medizinfuchs_collector_16609329.html',
    $fixturesDir . '/medizinfuchs_collector_' . $testPzn . '.html'
);
$pdo->prepare(
    'INSERT INTO products (pzn, name, active, is_test, created_at, updated_at)
     VALUES (:pzn, \'Collector Test PH7\', 1, 1, datetime(\'now\'), datetime(\'now\'))'
)->execute(['pzn' => $testPzn]);
$productOk = (int) $pdo->lastInsertId();

$pdo->prepare(
    'INSERT INTO products (pzn, name, active, is_test, created_at, updated_at)
     VALUES (:pzn, \'Collector Leer\', 1, 1, datetime(\'now\'), datetime(\'now\'))'
)->execute(['pzn' => $emptyPzn]);
$productEmpty = (int) $pdo->lastInsertId();

$beforeSnapshots = (int) $pdo->query('SELECT COUNT(*) FROM price_snapshots')->fetchColumn();

$result = $service->runForPzn($testPzn);
check($result['success'], 'Einzel-PZN erfolgreich', $failures);
check(($result['snapshots_created'] ?? 0) >= 3, 'Snapshots erzeugt', $failures);

$newCompetitor = $collectorRepo->getCompetitorByName('ApoDiscounter');
check($newCompetitor !== null, 'Wettbewerber angelegt (ApoDiscounter)', $failures);
check(($newCompetitor['type'] ?? '') !== 'own', 'Wettbewerber type=competitor', $failures);
check((int) $pdo->query('SELECT COUNT(*) FROM price_snapshots')->fetchColumn() > $beforeSnapshots, 'Snapshots in DB', $failures);

$ranked = (int) $pdo->query(
    'SELECT COUNT(*) FROM price_snapshots WHERE product_id = ' . $productOk . ' AND ranking IS NOT NULL'
)->fetchColumn();
check($ranked >= 1, 'Ranking aktualisiert', $failures);

$failResult = $service->runForPzn($emptyPzn);
check(!$failResult['success'], 'Leerer Abruf als Fehler', $failures);

$run = $service->runAll();
check(($run['products_processed'] ?? 0) >= 1, 'runAll verarbeitet Produkte', $failures);
check(isset($run['run_id']) && (int) $run['run_id'] > 0, 'Collector Run gespeichert', $failures);

$lastRun = $collectorRepo->getLastRun();
check(is_array($lastRun) && ($lastRun['status'] ?? '') !== '', 'Letzter Run abrufbar', $failures);

$parser = new MedizinfuchsParser();
$offers = $parser->parse(file_get_contents($fixturesDir . '/medizinfuchs_collector_16609329.html'));
check(count($offers) >= 3, 'Mock Parser extrahiert Angebote', $failures);

@unlink($fixturesDir . '/medizinfuchs_collector_' . $emptyPzn . '.html');
@unlink($fixturesDir . '/medizinfuchs_collector_' . $testPzn . '.html');

echo "\n" . ($failures === 0 ? 'PHASE 7 TESTS BESTANDEN' : 'PHASE 7 TESTS FEHLGESCHLAGEN') . "\n";
exit($failures > 0 ? 1 : 0);
