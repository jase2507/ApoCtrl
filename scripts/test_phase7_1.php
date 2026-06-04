<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/modules/collector/collector_factory.php';

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
$cacheDir = dirname(__DIR__) . '/storage/cache/collector';
if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
    fwrite(STDERR, "Cache-Verzeichnis nicht anlegbar\n");
    exit(1);
}

$collectorRepo = new CollectorRepository($pdo);
$baseConfig = require dirname(__DIR__) . '/config/config.php';

function makeLiveProvider(
    PDO $pdo,
    array $baseConfig,
    string $urlTemplate,
    int $delayMs = 1000,
): MedizinfuchsProvider {
    return new MedizinfuchsProvider(
        false,
        dirname(__DIR__) . '/docs/examples',
        dirname(__DIR__) . '/storage/cache/collector',
        $urlTemplate,
        5,
        $delayMs,
        15,
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ApoCtrl Collector Test',
        false,
        false,
        new CollectorRepository($pdo),
    );
}

// --- Cache Hit ---
MedizinfuchsProvider::resetRateLimitClock();
$cachePzn = '71' . random_int(100000, 999999);
$cachePath = $cacheDir . '/' . $cachePzn . '.html';
copy($fixturesDir . '/medizinfuchs_collector_16609329.html', $cachePath);
touch($cachePath, time());

$cacheProvider = makeLiveProvider($pdo, $baseConfig, 'https://example.invalid/{PZN}');
try {
    $cacheProvider->setRunId(999001);
    $cacheProvider->fetchByPzn($cachePzn);
    $debug = $cacheProvider->getLastFetchDebug();
    check(($debug['cache_hit'] ?? false) === true, 'Cache Hit', $failures);
    check(($debug['status'] ?? '') === 'cache_hit', 'Cache Hit Status', $failures);
} catch (Throwable $e) {
    check(false, 'Cache Hit: ' . $e->getMessage(), $failures);
}

$logs = $collectorRepo->getLatestCollectorLogs(5);
check(
    isset($logs[0]) && ($logs[0]['status'] ?? '') === 'cache_hit' && ($logs[0]['pzn'] ?? '') === $cachePzn,
    'Log-Eintrag Cache Hit',
    $failures,
);

// --- Cache Miss (kein gültiger Cache → HTTP-Abruf) ---
MedizinfuchsProvider::resetRateLimitClock();
$missPzn = '72' . random_int(100000, 999999);
@unlink($cacheDir . '/' . $missPzn . '.html');
$fixtureFile = $fixturesDir . '/medizinfuchs_collector_16609329.html';
$fixtureUrl = 'file:///' . str_replace('\\', '/', realpath($fixtureFile) ?: $fixtureFile) . '?pzn={PZN}';
$missProvider = makeLiveProvider($pdo, $baseConfig, $fixtureUrl, 0);
$missOk = false;
try {
    $missProvider->fetchByPzn($missPzn);
    $missDebug = $missProvider->getLastFetchDebug();
    $missOk = ($missDebug['cache_hit'] ?? true) === false && ($missDebug['status'] ?? '') === 'ok';
} catch (Throwable) {
    $missOk = false;
}
check($missOk, 'Cache Miss mit Abruf', $failures);
check(is_file($cacheDir . '/' . $missPzn . '.html'), 'Cache Miss schreibt Cache-Datei', $failures);

// --- Fehlerbehandlung (Collector bricht nicht ab) ---
$errorPzn = '73' . random_int(100000, 999999);
$pdo->prepare(
    'INSERT INTO products (pzn, name, active, is_test, created_at, updated_at)
     VALUES (:pzn, \'PH71 Error\', 1, 1, datetime(\'now\'), datetime(\'now\'))'
)->execute(['pzn' => $errorPzn]);

$errorConfig = $baseConfig;
$errorConfig['collector'] = array_merge($baseConfig['collector'] ?? [], [
    'mock_mode' => false,
    'medizinfuchs_url_template' => 'http://127.0.0.1:9/{PZN}',
    'request_delay_ms' => 0,
    'timeout' => 2,
]);
$errorService = createCollectorService($pdo, $errorConfig);
$errorResult = $errorService->runForPzn($errorPzn);
check(!$errorResult['success'], 'Fehlerhafter Abruf markiert als Fehler', $failures);
check(isset($errorResult['run_id']), 'Run trotz Fehler gespeichert', $failures);

$lastLogs = $collectorRepo->getLatestCollectorLogs(3);
$hasErrorLog = false;
foreach ($lastLogs as $log) {
    if (($log['pzn'] ?? '') === $errorPzn && ($log['status'] ?? '') === 'error') {
        $hasErrorLog = true;
        break;
    }
}
check($hasErrorLog, 'Fehlerbehandlung Log status=error', $failures);

// --- Delay ---
MedizinfuchsProvider::resetRateLimitClock();
$delayProvider = makeLiveProvider($pdo, $baseConfig, 'https://example.invalid/{PZN}', 800);
$delayPzn = '74' . random_int(100000, 999999);
file_put_contents($cacheDir . '/' . $delayPzn . '.html', '<html></html>');
touch($cacheDir . '/' . $delayPzn . '.html', time());
$t0 = microtime(true);
try {
    $delayProvider->fetchByPzn($delayPzn);
} catch (Throwable) {
    // ignore
}
try {
    $delayProvider->fetchByPzn($delayPzn);
} catch (Throwable) {
    // ignore
}
$elapsedMs = (microtime(true) - $t0) * 1000;
check($elapsedMs >= 700, 'Request-Delay zwischen Abrufen', $failures);

// --- Mock-Modus ---
$mockConfig = $baseConfig;
$mockConfig['collector'] = array_merge($baseConfig['collector'] ?? [], ['mock_mode' => true]);
$mockService = createCollectorService($pdo, $mockConfig);
$mockPzn = '75' . random_int(100000, 999999);
copy(
    $fixturesDir . '/medizinfuchs_collector_16609329.html',
    $fixturesDir . '/medizinfuchs_collector_' . $mockPzn . '.html'
);
$pdo->prepare(
    'INSERT INTO products (pzn, name, active, is_test, created_at, updated_at)
     VALUES (:pzn, \'PH71 Mock\', 1, 1, datetime(\'now\'), datetime(\'now\'))'
)->execute(['pzn' => $mockPzn]);
$mockResult = $mockService->runForPzn($mockPzn);
check($mockResult['success'], 'Mock-Modus erfolgreich', $failures);

// --- Live-Modus (Cache) ---
$liveConfig = $baseConfig;
$liveConfig['collector'] = array_merge($baseConfig['collector'] ?? [], [
    'mock_mode' => false,
    'request_delay_ms' => 0,
    'medizinfuchs_url_template' => 'https://example.invalid/{PZN}',
]);
$livePzn = '76' . random_int(100000, 999999);
copy($fixturesDir . '/medizinfuchs_collector_16609329.html', $cacheDir . '/' . $livePzn . '.html');
touch($cacheDir . '/' . $livePzn . '.html', time());
$pdo->prepare(
    'INSERT INTO products (pzn, name, active, is_test, created_at, updated_at)
     VALUES (:pzn, \'PH71 Live Cache\', 1, 1, datetime(\'now\'), datetime(\'now\'))'
)->execute(['pzn' => $livePzn]);
$liveService = createCollectorService($pdo, $liveConfig);
$liveResult = $liveService->runForPzn($livePzn);
check($liveResult['success'], 'Live-Modus mit Cache (kein Netz nötig)', $failures);

// --- Eigen-Shop-Filter ---
$ownPzn = '78' . random_int(100000, 999999);
$ownFixture = $fixturesDir . '/medizinfuchs_collector_' . $ownPzn . '.html';
file_put_contents(
    $ownFixture,
    '<!DOCTYPE html><html><body>'
    . '<div class="mf-offer" data-competitor="Apotheker Seidel" data-price="10,00" data-shipping="0" data-status="ok"></div>'
    . '<div class="mf-offer" data-competitor="Fremd Apo PH71" data-price="11,00" data-shipping="0" data-status="ok"></div>'
    . '</body></html>'
);
$pdo->prepare(
    'INSERT INTO products (pzn, name, active, is_test, created_at, updated_at)
     VALUES (:pzn, \'PH71 Own Filter\', 1, 1, datetime(\'now\'), datetime(\'now\'))'
)->execute(['pzn' => $ownPzn]);
$ownConfig = $baseConfig;
$ownConfig['collector'] = array_merge($baseConfig['collector'] ?? [], ['mock_mode' => true, 'request_delay_ms' => 0]);
$ownService = createCollectorService($pdo, $ownConfig);
$beforeOwn = (int) $pdo->query('SELECT COUNT(*) FROM price_snapshots')->fetchColumn();
$ownResult = $ownService->runForPzn($ownPzn);
check($ownResult['success'], 'Eigen-Shop-Filter Lauf OK', $failures);
$ownProductId = (int) $pdo->query(
    "SELECT id FROM products WHERE pzn = " . $pdo->quote($ownPzn)
)->fetchColumn();
$seidelSnap = (int) $pdo->query(
    'SELECT COUNT(*) FROM price_snapshots ps
     JOIN competitors c ON c.id = ps.competitor_id
     WHERE ps.product_id = ' . $ownProductId . " AND LOWER(c.name) LIKE '%apotheker seidel%'"
)->fetchColumn();
check($seidelSnap === 0, 'Eigen-Shop-Filter: kein Apotheker-Seidel-Snapshot', $failures);
$fremdSnap = (int) $pdo->query(
    'SELECT COUNT(*) FROM price_snapshots WHERE product_id = ' . $ownProductId
)->fetchColumn();
check($fremdSnap >= 1, 'Eigen-Shop-Filter: Fremdanbieter gespeichert', $failures);

@unlink($cachePath);
@unlink($cacheDir . '/' . $missPzn . '.html');
@unlink($cacheDir . '/' . $delayPzn . '.html');
@unlink($cacheDir . '/' . $livePzn . '.html');
@unlink($ownFixture);
@unlink($fixturesDir . '/medizinfuchs_collector_' . $mockPzn . '.html');

echo $failures === 0 ? "PHASE 7.1 TESTS BESTANDEN\n" : "PHASE 7.1 TESTS FEHLGESCHLAGEN ({$failures})\n";
exit($failures === 0 ? 0 : 1);
