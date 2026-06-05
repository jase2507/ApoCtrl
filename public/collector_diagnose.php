<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/modules/collector/collector_http.php';

Auth::requireAuth($config['session']['timeout']);

$currentNav = 'collector';
$pageTitle = 'Collector HTTP-Diagnose';
$user = Auth::getUser();

$pzn = trim((string) (query('pzn', '16609329') ?? '16609329'));
$debugFilePath = dirname(__DIR__) . '/storage/debug/medizinfuchs_last_response.html';
$debugFileExists = is_file($debugFilePath);
$savedPreview = $debugFileExists ? (string) file_get_contents($debugFilePath) : '';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
    $action = post('action', '');
    if ($action === 'probe') {
        $pzn = trim((string) post('pzn', '16609329'));
        $url = buildMedizinfuchsSearchUrl($config, $pzn);
        $probeConfig = $config;
        $probeConfig['collector'] = is_array($config['collector'] ?? null) ? $config['collector'] : [];
        $probeConfig['collector']['debug'] = true;
        $httpClient = createMedizinfuchsHttpClient($probeConfig);
        $response = $httpClient->fetch($url);
        $meta = MedizinfuchsHttpClient::toDebugMeta($response);
        $result = array_merge([
            'url' => $url,
            'error' => $response['error'] ?? null,
        ], $meta);
    }
}

renderLayout('modules/collector/diagnose.php', compact(
    'pageTitle',
    'currentNav',
    'user',
    'config',
    'pzn',
    'result',
    'debugFilePath',
    'debugFileExists',
    'savedPreview',
));
