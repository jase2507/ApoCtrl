<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/modules/collector/MedizinfuchsHttpClient.php';
require_once dirname(__DIR__) . '/modules/collector/collector_http.php';

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
$debugDir = dirname(__DIR__) . '/storage/debug';
$debugFile = $debugDir . '/medizinfuchs_last_response_test.html';
if (!is_dir($debugDir) && !mkdir($debugDir, 0755, true) && !is_dir($debugDir)) {
    fwrite(STDERR, "Debug-Verzeichnis nicht anlegbar\n");
    exit(1);
}

$fixtureHtml = <<<'HTML'
<!DOCTYPE html><html><head><title>medizinfuchs bite away</title></head>
<body><p>PZN: 16609329</p><p>bite away Insektenstich Heiler</p></body></html>
HTML;
$fixturePath = $debugDir . '/http_debug_fixture.html';
file_put_contents($fixturePath, $fixtureHtml);

check(
    str_contains(MedizinfuchsHttpClient::DEFAULT_USER_AGENT, 'Chrome/137.0'),
    'Browser-User-Agent Standard',
    $failures,
);

$headers = MedizinfuchsHttpClient::defaultRequestHeaders();
check(in_array('Accept: text/html', $headers, true), 'Accept text/html', $failures);
check(in_array('Accept-Language: de-DE,de;q=0.9', $headers, true), 'Accept-Language de-DE', $failures);

$client = new MedizinfuchsHttpClient(
    MedizinfuchsHttpClient::DEFAULT_USER_AGENT,
    5,
    false,
    true,
    $debugFile,
);
$response = $client->fetch(fileUrl($fixturePath));
$meta = MedizinfuchsHttpClient::toDebugMeta($response);

check(($response['http_code'] ?? null) === 200 || ($response['html'] ?? '') !== '', 'HTTP-Abruf Fixture', $failures);
check(($meta['response_length'] ?? 0) > 0, 'Response-Länge gesetzt', $failures);
check(is_file($debugFile), 'Debug-Snapshot gespeichert', $failures);
check(strlen((string) file_get_contents($debugFile)) <= 1000, 'Snapshot max. 1000 Zeichen', $failures);
check(str_contains((string) file_get_contents($debugFile), '16609329'), 'Snapshot enthält PZN', $failures);

$checks = MedizinfuchsHttpClient::checkContentMarkers($fixtureHtml);
check($checks['16609329'] === true, 'Marker 16609329', $failures);
check($checks['bite away'] === true, 'Marker bite away', $failures);
check($checks['medizinfuchs'] === true, 'Marker medizinfuchs', $failures);

if (function_exists('curl_init')) {
    check(($response['transport'] ?? '') === 'curl', 'curl bevorzugt', $failures);
} else {
    check(($response['transport'] ?? '') === 'stream', 'Stream-Fallback', $failures);
}

$config = require dirname(__DIR__) . '/config/config.php';
$config['collector'] = array_merge($config['collector'] ?? [], ['debug' => true]);
$factoryClient = createMedizinfuchsHttpClient($config);
check(
    $factoryClient->getUserAgent() !== '',
    'Factory-HTTP-Client',
    $failures,
);
check(
    buildMedizinfuchsSearchUrl($config, '16609329') !== '',
    'Such-URL Builder',
    $failures,
);

@unlink($debugFile);
@unlink($fixturePath);

echo $failures === 0 ? "COLLECTOR HTTP DEBUG TESTS BESTANDEN\n" : "COLLECTOR HTTP DEBUG TESTS FEHLGESCHLAGEN ({$failures})\n";
exit($failures === 0 ? 0 : 1);
