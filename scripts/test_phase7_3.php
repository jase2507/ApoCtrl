<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/modules/collector/CollectorProviderInterface.php';
require_once dirname(__DIR__) . '/modules/collector/MedizinfuchsHttpClient.php';
require_once dirname(__DIR__) . '/modules/collector/MedizinfuchsUrlResolver.php';
require_once dirname(__DIR__) . '/modules/collector/MedizinfuchsProvider.php';
require_once dirname(__DIR__) . '/modules/collector/collector_factory.php';
require_once dirname(__DIR__) . '/modules/collector/MedizinfuchsCollector.php';
require_once dirname(__DIR__) . '/modules/collector/MedizinfuchsParser.php';
require_once dirname(__DIR__) . '/modules/snapshots/SnapshotRepository.php';
require_once dirname(__DIR__) . '/modules/snapshots/SnapshotService.php';
require_once dirname(__DIR__) . '/modules/rankings/RankingRepository.php';
require_once dirname(__DIR__) . '/modules/rankings/RankingEngine.php';

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

function fileUrl(string $path): string
{
    $real = realpath($path) ?: $path;

    return 'file:///' . str_replace('\\', '/', $real);
}

$fixturesDir = dirname(__DIR__) . '/docs/examples';
$cacheDir = dirname(__DIR__) . '/storage/cache/collector';
if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
    fwrite(STDERR, "Cache-Verzeichnis nicht anlegbar\n");
    exit(1);
}

$collectorRepo = new CollectorRepository($pdo);
$baseConfig = require dirname(__DIR__) . '/config/config.php';
$userAgent = 'Mozilla/5.0 ApoCtrl Phase 7.3 Test';

$productPath = $fixturesDir . '/medizinfuchs_product_16609329.html';
$productUrl = fileUrl($productPath);
$searchResolvePath = $fixturesDir . '/medizinfuchs_search_resolve_16609329.html';
$searchResolveContent = (string) file_get_contents($searchResolvePath);
$searchResolveContent = str_replace(
    'href="medizinfuchs_product_16609329.html"',
    'href="' . $productUrl . '"',
    $searchResolveContent,
);
$searchResolveContent = str_replace(
    'href="medizinfuchs_product_16609329.html"',
    'href="' . $productUrl . '"',
    $searchResolveContent,
);
$tempSearchResolve = $cacheDir . '/phase73_search_resolve_16609329.html';
file_put_contents($tempSearchResolve, $searchResolveContent);

$testHttp = new MedizinfuchsHttpClient($userAgent, 5, false, false, '');

MedizinfuchsUrlResolver::resetRateLimitClock();
$resolver = new MedizinfuchsUrlResolver(
    fileUrl($tempSearchResolve) . '?pzn={PZN}',
    0,
    $testHttp,
);

$resolved = $resolver->resolveProductUrl('16609329');
$resolveDebug = $resolver->getLastResolveDebug();
check($resolved !== null, 'PZN wird auf Suchseite gefunden', $failures);
check(($resolveDebug['pzn_found'] ?? false) === true, 'pzn_found true', $failures);
check(
    $resolved !== null && str_contains($resolved, 'medizinfuchs_product_16609329'),
    'resolved_url wird gesetzt',
    $failures,
);

$fallbackPath = $fixturesDir . '/medizinfuchs_search_fallback_99887766.html';
$fallbackSearchUrl = fileUrl($fallbackPath);
$fallbackResolver = new MedizinfuchsUrlResolver(
    $fallbackSearchUrl . '?q={PZN}',
    0,
    $testHttp,
);
$fallbackResolved = $fallbackResolver->resolveProductUrl('99887766');
check($fallbackResolved !== null, 'Suchseite als Fallback erlaubt', $failures);
check(
    $fallbackResolved === $fallbackSearchUrl . '?q=99887766'
        || str_starts_with((string) $fallbackResolved, $fallbackSearchUrl),
    'Fallback nutzt Such-URL',
    $failures,
);

$missingResolver = new MedizinfuchsUrlResolver(
    fileUrl($fixturesDir . '/medizinfuchs_search_missing.html') . '?pzn={PZN}',
    0,
    $testHttp,
);
check($missingResolver->resolveProductUrl('81111111') === null, 'Fehlende PZN blockiert', $failures);
check(
    ($missingResolver->getLastResolveDebug()['pzn_found'] ?? true) === false,
    'pzn_found false bei fehlender PZN',
    $failures,
);

MedizinfuchsProvider::resetRateLimitClock();
$liveProvider = new MedizinfuchsProvider(
    false,
    $fixturesDir,
    $cacheDir,
    fileUrl($productPath),
    5,
    0,
    15,
    $userAgent,
    false,
    false,
    $collectorRepo,
    $resolver,
    $testHttp,
);

$fetchPzn = '16609329';
@unlink($cacheDir . '/' . $fetchPzn . '.html');
try {
    $liveProvider->setRunId(993001);
    $liveProvider->fetchByPzn($fetchPzn);
    $fetchDebug = $liveProvider->getLastFetchDebug();
    check(($fetchDebug['status'] ?? '') === 'ok', 'Provider-Abruf nach Auflösung OK', $failures);
    check(!empty($fetchDebug['resolved_url']), 'Provider setzt resolved_url', $failures);
    check(!empty($fetchDebug['search_url']), 'Provider setzt search_url', $failures);
    check(!empty($fetchDebug['effective_url']), 'Provider setzt effective_url', $failures);
} catch (Throwable $e) {
    check(false, 'Provider-Abruf: ' . $e->getMessage(), $failures);
}

$logs = $collectorRepo->getLatestCollectorLogs(3);
check(
    isset($logs[0]) && !empty($logs[0]['resolved_url']) && !empty($logs[0]['source_url']),
    'collector_logs speichert resolved_url und source_url',
    $failures,
);

$cachePznA = '83' . random_int(100000, 999999);
$cachePznB = '84' . random_int(100000, 999999);
$cachePathA = $cacheDir . '/' . $cachePznA . '.html';
$cachePathB = $cacheDir . '/' . $cachePznB . '.html';
file_put_contents($cachePathA, '<html>PZN-A-' . $cachePznA . '</html>');
file_put_contents($cachePathB, '<html>PZN-B-' . $cachePznB . '</html>');
touch($cachePathA, time());
touch($cachePathB, time());

$cacheProvider = new MedizinfuchsProvider(
    false,
    $fixturesDir,
    $cacheDir,
    'https://example.invalid/{PZN}',
    5,
    0,
    15,
    $userAgent,
    false,
    false,
    $collectorRepo,
    $resolver,
    $testHttp,
);

try {
    $htmlA = $cacheProvider->fetchByPzn($cachePznA);
    $htmlB = $cacheProvider->fetchByPzn($cachePznB);
    check(str_contains($htmlA, $cachePznA), 'Cache A getrennt', $failures);
    check(str_contains($htmlB, $cachePznB), 'Cache B getrennt', $failures);
    check(
        ($cacheProvider->getLastFetchDebug()['cache_hit'] ?? false) === true,
        'Cache Hit pro PZN',
        $failures,
    );
} catch (Throwable $e) {
    check(false, 'Cache-Trennung: ' . $e->getMessage(), $failures);
}

$badPzn = '85' . random_int(100000, 999999);
$goodPzn = '86' . random_int(100000, 999999);
$goodFallbackPath = $fixturesDir . '/medizinfuchs_search_fallback_' . $goodPzn . '.html';
$goodFallbackContent = str_replace('99887766', $goodPzn, (string) file_get_contents($fallbackPath));
file_put_contents($goodFallbackPath, $goodFallbackContent);

$pdo->prepare('DELETE FROM products WHERE pzn IN (:a, :b)')->execute(['a' => $badPzn, 'b' => $goodPzn]);
$pdo->prepare(
    'INSERT INTO products (pzn, name, active, is_test, created_at, updated_at)
     VALUES (:pzn, \'PH73 Bad\', 1, 1, datetime(\'now\'), datetime(\'now\'))'
)->execute(['pzn' => $badPzn]);
$pdo->prepare(
    'INSERT INTO products (pzn, name, active, is_test, created_at, updated_at)
     VALUES (:pzn, \'PH73 Good\', 1, 1, datetime(\'now\'), datetime(\'now\'))'
)->execute(['pzn' => $goodPzn]);

$collectorConfig = array_merge($baseConfig['collector'] ?? [], [
    'mock_mode' => false,
    'request_delay_ms' => 0,
    'fetch_ajax_offers' => false,
    'medizinfuchs_search_url_template' => fileUrl($fixturesDir . '/medizinfuchs_search_missing.html') . '?pzn={PZN}',
]);
$badConfig = $baseConfig;
$badConfig['collector'] = $collectorConfig;
$badService = createCollectorService($pdo, $badConfig);

$goodCollectorConfig = array_merge($baseConfig['collector'] ?? [], [
    'mock_mode' => false,
    'request_delay_ms' => 0,
    'fetch_ajax_offers' => false,
    'medizinfuchs_search_url_template' => fileUrl($fixturesDir . '/medizinfuchs_search_fallback_{PZN}.html'),
]);
$goodConfig = $baseConfig;
$goodConfig['collector'] = $goodCollectorConfig;
$goodService = createCollectorService($pdo, $goodConfig);

$badProduct = $collectorRepo->findProductByPzn($badPzn);
$goodProduct = $collectorRepo->findProductByPzn($goodPzn);
$beforeSnapshots = (int) $pdo->query('SELECT COUNT(*) FROM price_snapshots')->fetchColumn();

$badResult = $badService->runForPzn($badPzn);
check(!$badResult['success'], 'Resolver-Fehler ohne Snapshot', $failures);
check(($badResult['snapshots_created'] ?? -1) === 0, 'Kein Snapshot bei Resolver-Fehler', $failures);
check(
    str_contains((string) ($badResult['message'] ?? ''), 'Keine Medizinfuchs-Seite'),
    'Fehlermeldung bei fehlender PZN',
    $failures,
);

@unlink($cacheDir . '/' . $goodPzn . '.html');
$goodResult = $goodService->runForPzn($goodPzn);
check($goodResult['success'], 'Gutes Produkt nach Fehler weiter sammelbar', $failures);
check(
    (int) $pdo->query('SELECT COUNT(*) FROM price_snapshots')->fetchColumn() > $beforeSnapshots,
    'Snapshot nur für gültiges Produkt',
    $failures,
);

copy(
    $fixturesDir . '/medizinfuchs_search_missing.html',
    $fixturesDir . '/medizinfuchs_search_fallback_' . $badPzn . '.html',
);
$loopSearchTemplate = fileUrl($fixturesDir . '/medizinfuchs_search_fallback_{PZN}.html');
$loopResolver = new MedizinfuchsUrlResolver($loopSearchTemplate, 0, $testHttp);
$runId = $collectorRepo->startCollectionRun();
$collector = new MedizinfuchsCollector(
    new MedizinfuchsProvider(
        false,
        $fixturesDir,
        $cacheDir,
        fileUrl($fallbackPath),
        5,
        0,
        15,
        $userAgent,
        false,
        false,
        $collectorRepo,
        $loopResolver,
        $testHttp,
    ),
    new MedizinfuchsParser(),
    $collectorRepo,
    new SnapshotService(new SnapshotRepository($pdo)),
    new RankingEngine(new RankingRepository($pdo)),
);
$collector->setRunId($runId);
$loopBad = $collector->collectProduct($badProduct ?? []);
@unlink($cacheDir . '/' . $goodPzn . '.html');
$loopGood = $collector->collectProduct($goodProduct ?? []);
$collector->setRunId(null);
$collectorRepo->finishCollectionRun($runId, [
    'products_processed' => 2,
    'snapshots_created' => (int) ($loopGood['snapshots_created'] ?? 0),
    'errors' => ($loopBad['success'] ?? true) ? 0 : 1,
    'status' => 'partial',
]);
check(!$loopBad['success'] && $loopGood['success'], 'Collector läuft bei Resolver-Fehler weiter', $failures);
@unlink($fixturesDir . '/medizinfuchs_search_fallback_' . $badPzn . '.html');
@unlink($goodFallbackPath);

@unlink($tempSearchResolve);
@unlink($cachePathA);
@unlink($cachePathB);
@unlink($cacheDir . '/' . $fetchPzn . '.html');
@unlink($cacheDir . '/' . $goodPzn . '.html');
$goodProductId = (int) ($goodProduct['id'] ?? 0);
if ($goodProductId > 0) {
    $pdo->exec('DELETE FROM price_snapshots WHERE product_id = ' . $goodProductId);
}
$pdo->prepare('DELETE FROM products WHERE pzn IN (:a, :b)')->execute(['a' => $badPzn, 'b' => $goodPzn]);

echo $failures === 0 ? "PHASE 7.3 TESTS BESTANDEN\n" : "PHASE 7.3 TESTS FEHLGESCHLAGEN ({$failures})\n";
exit($failures === 0 ? 0 : 1);
