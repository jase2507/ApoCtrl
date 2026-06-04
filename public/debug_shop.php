<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/modules/shop/ShopUrlValidator.php';
require_once dirname(__DIR__) . '/modules/shop/ShopHtmlParser.php';
require_once dirname(__DIR__) . '/modules/shop/ShopFetcher.php';

Auth::requireAuth($config['session']['timeout']);

/**
 * @return array{ok:bool, length:int, last_error:?string, preview:string}
 */
function debug_shop_direct_fetch(string $url, int $timeoutSeconds = 15): array
{
    $empty = [
        'ok' => false,
        'length' => 0,
        'last_error' => null,
        'preview' => '',
    ];

    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
        $empty['last_error'] = 'allow_url_fopen ist deaktiviert';

        return $empty;
    }

    $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
    if ($scheme === 'https' && !in_array('https', stream_get_wrappers(), true)) {
        $empty['last_error'] = 'HTTPS-Stream-Wrapper nicht verfügbar (openssl-Extension prüfen)';

        return $empty;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeoutSeconds,
            'header' => "User-Agent: Mozilla/5.0 ApoCtrl-Debug\r\n"
                . "Accept: text/html,text/plain,*/*\r\n"
                . "Connection: close\r\n",
            'follow_location' => 1,
            'max_redirects' => 5,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $previousTimeout = ini_get('default_socket_timeout');
    ini_set('default_socket_timeout', (string) $timeoutSeconds);

    error_clear_last();
    $body = @file_get_contents($url, false, $context);

    if ($previousTimeout !== false) {
        ini_set('default_socket_timeout', (string) $previousTimeout);
    }

    $phpError = error_get_last();
    $lastError = is_array($phpError) && isset($phpError['message']) ? (string) $phpError['message'] : null;

    if (!is_string($body) || $body === '') {
        $empty['last_error'] = $lastError;

        return $empty;
    }

    return [
        'ok' => true,
        'length' => strlen($body),
        'last_error' => $lastError,
        'preview' => mb_substr($body, 0, 200),
    ];
}

$shopConfig = is_array($config['shop'] ?? null) ? $config['shop'] : [];
$allowedHost = (string) ($shopConfig['allowed_host'] ?? 'shop.apotheker-seidel.de');
$deeplinkTemplate = (string) ($shopConfig['deeplink_template'] ?? 'https://shop.apotheker-seidel.de/product?artnr={PZN}');
$fetchTimeout = (int) ($shopConfig['fetch_timeout'] ?? 15);
$allowInsecureSsl = filter_var($shopConfig['fetch_insecure_ssl'] ?? false, FILTER_VALIDATE_BOOL);

$pznRaw = trim((string) ($_GET['pzn'] ?? '16609329'));
$pzn = ShopHtmlParser::normalizePzn($pznRaw);
$url = str_replace('{PZN}', rawurlencode($pzn), $deeplinkTemplate);

$validator = new ShopUrlValidator($allowedHost);
$urlError = $validator->validateOrError($url);

$wrappers = stream_get_wrappers();
$httpsWrapperPresent = in_array('https', $wrappers, true);
$curlLoaded = extension_loaded('curl');
$curlInitExists = function_exists('curl_init');
$opensslLoaded = extension_loaded('openssl');
$allowUrlFopenRaw = ini_get('allow_url_fopen');
$allowUrlFopen = filter_var($allowUrlFopenRaw, FILTER_VALIDATE_BOOL);
$disableFunctions = (string) (ini_get('disable_functions') ?: '');
$dnsResolved = gethostbyname('shop.apotheker-seidel.de');
$parsedProductUrl = parse_url($url);
$phpIniPath = php_ini_loaded_file();
$phpBinary = defined('PHP_BINARY') ? (string) PHP_BINARY : '(PHP_BINARY nicht definiert)';

$directTestUrls = [
    'A' => 'https://www.google.de',
    'B' => 'https://shop.apotheker-seidel.de',
    'C' => 'https://shop.apotheker-seidel.de/product?artnr=16609329',
    'D' => 'https://shop.apotheker-seidel.de/eStLeonard-Oy8chie2Ie/medizinfuchs/eStLeonard_medizinfuchs.csv',
];
$directTestResults = [];
foreach ($directTestUrls as $label => $testUrl) {
    $directTestResults[$label] = array_merge(
        ['url' => $testUrl],
        debug_shop_direct_fetch($testUrl, $fetchTimeout),
    );
}

header('Content-Type: text/html; charset=UTF-8');

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Shop-Abruf Diagnose</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 1.5rem; max-width: 1100px; }
        pre { background: #f4f4f4; padding: 1rem; overflow: auto; white-space: pre-wrap; word-break: break-word; font-size: 0.9rem; }
        dl { display: grid; grid-template-columns: 14rem 1fr; gap: 0.35rem 1rem; }
        dt { font-weight: 600; }
        .error { color: #b00020; }
        .ok { color: #0a6b0a; }
        .hint { background: #fff8e1; border: 1px solid #e6c200; padding: 0.75rem 1rem; margin: 1rem 0; }
        form { margin-bottom: 1.5rem; }
        input[type=text] { width: 12rem; padding: 0.35rem; }
        table { width: 100%; border-collapse: collapse; margin: 0.5rem 0 1.5rem; font-size: 0.9rem; }
        th, td { border: 1px solid #ccc; padding: 0.4rem 0.5rem; text-align: left; vertical-align: top; }
        th { background: #eee; }
        h2 { margin-top: 2rem; border-bottom: 1px solid #ddd; padding-bottom: 0.25rem; }
        section { margin-bottom: 1.5rem; }
    </style>
</head>
<body>
    <h1>Shop-Abruf Diagnose</h1>
    <p>Prüft PHP-Netzwerk, direkte HTTPS-Abrufe und den ShopFetcher (ohne Produktformular).</p>

    <form method="get" action="debug_shop.php">
        <label>PZN <input type="text" name="pzn" value="<?= htmlspecialchars($pzn, ENT_QUOTES, 'UTF-8') ?>" maxlength="20"></label>
        <button type="submit">Abrufen</button>
        <a href="products.php">Zurück zu Produkten</a>
    </form>

    <h2>Netzwerk-Selbsttest (PHP-Umgebung)</h2>
    <dl>
        <dt>PHP-Version</dt><dd><?= htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') ?></dd>
        <dt>PHP_BINARY</dt><dd><code><?= htmlspecialchars($phpBinary, ENT_QUOTES, 'UTF-8') ?></code></dd>
        <dt>php.ini</dt><dd><code><?= htmlspecialchars($phpIniPath !== false ? (string) $phpIniPath : '(nicht geladen)', ENT_QUOTES, 'UTF-8') ?></code></dd>
        <dt>allow_url_fopen</dt><dd><code><?= htmlspecialchars((string) $allowUrlFopenRaw, ENT_QUOTES, 'UTF-8') ?></code> → <?= $allowUrlFopen ? '<span class="ok">aktiv</span>' : '<span class="error">deaktiviert</span>' ?></dd>
        <dt>disable_functions</dt><dd><code><?= htmlspecialchars($disableFunctions !== '' ? $disableFunctions : '(leer)', ENT_QUOTES, 'UTF-8') ?></code></dd>
        <dt>Stream-Wrapper</dt><dd><code><?= htmlspecialchars(implode(', ', $wrappers), ENT_QUOTES, 'UTF-8') ?></code></dd>
        <dt>https-Wrapper</dt><dd class="<?= $httpsWrapperPresent ? 'ok' : 'error' ?>"><?= $httpsWrapperPresent ? 'ja' : 'nein' ?></dd>
        <dt>extension_loaded(curl)</dt><dd class="<?= $curlLoaded ? 'ok' : 'error' ?>"><?= $curlLoaded ? 'ja' : 'nein' ?></dd>
        <dt>function_exists(curl_init)</dt><dd class="<?= $curlInitExists ? 'ok' : 'error' ?>"><?= $curlInitExists ? 'ja' : 'nein' ?></dd>
        <dt>extension_loaded(openssl)</dt><dd class="<?= $opensslLoaded ? 'ok' : 'error' ?>"><?= $opensslLoaded ? 'ja' : 'nein' ?></dd>
        <dt>DNS shop.apotheker-seidel.de</dt><dd><code><?= htmlspecialchars($dnsResolved, ENT_QUOTES, 'UTF-8') ?></code><?= $dnsResolved === 'shop.apotheker-seidel.de' ? ' <span class="error">(nicht aufgelöst)</span>' : '' ?></dd>
        <dt>parse_url (Produkt-URL)</dt><dd><pre><?= htmlspecialchars(json_encode($parsedProductUrl, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'null', ENT_QUOTES, 'UTF-8') ?></pre></dd>
        <dt>Original-URL (Config)</dt><dd><code><?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?></code></dd>
        <dt>fetch_insecure_ssl</dt><dd><?= $allowInsecureSsl ? '<span class="error">aktiv (unsicher, nur Test)</span>' : 'nein (Standard)' ?></dd>
    </dl>

    <h2>Direkte Abruf-Tests (file_get_contents + Stream-Context)</h2>
    <p>User-Agent: <code>Mozilla/5.0 ApoCtrl-Debug</code>, Timeout: <?= (int) $fetchTimeout ?>s, SSL-Verifikation: an</p>
    <table>
        <thead>
            <tr>
                <th>Test</th>
                <th>URL</th>
                <th>Erfolg</th>
                <th>Länge</th>
                <th>error_get_last()</th>
                <th>Erste 200 Zeichen</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($directTestResults as $label => $row): ?>
            <tr>
                <td><?= htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') ?></td>
                <td><code><?= htmlspecialchars((string) $row['url'], ENT_QUOTES, 'UTF-8') ?></code></td>
                <td class="<?= !empty($row['ok']) ? 'ok' : 'error' ?>"><?= !empty($row['ok']) ? 'ja' : 'nein' ?></td>
                <td><?= (int) ($row['length'] ?? 0) ?></td>
                <td><?= htmlspecialchars((string) ($row['last_error'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><pre><?= htmlspecialchars((string) ($row['preview'] ?? '') !== '' ? (string) $row['preview'] : '(leer)', ENT_QUOTES, 'UTF-8') ?></pre></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

<?php if ($urlError !== null): ?>
    <p class="error">ShopUrlValidator: <?= htmlspecialchars($urlError, ENT_QUOTES, 'UTF-8') ?></p>
<?php else: ?>
    <?php
    $fetcher = new ShopFetcher($fetchTimeout, $allowInsecureSsl);
    $result = $fetcher->fetch($url);
    $html = is_string($result['html'] ?? null) ? (string) $result['html'] : '';
    $preview = $html !== '' ? mb_substr($html, 0, 500) : '';
    $showCurlHint = !$curlInitExists && $html === '';
    ?>
    <h2>ShopFetcher-Abruf (Produktseite)</h2>
    <?php if ($showCurlHint): ?>
        <p class="hint">cURL ist nicht installiert. Für stabile HTTPS-Abrufe sollte php-curl aktiviert werden.</p>
    <?php endif; ?>
    <?php if (!empty($result['hint'])): ?>
        <p class="hint"><?= htmlspecialchars((string) $result['hint'], ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <dl>
        <dt>URL (an Fetcher)</dt><dd><code><?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?></code></dd>
        <dt>Status</dt><dd class="<?= !empty($result['ok']) ? 'ok' : 'error' ?>"><?= !empty($result['ok']) ? 'OK' : 'Fehler' ?></dd>
        <dt>HTTP-Code</dt><dd><?= htmlspecialchars((string) ($result['http_code'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></dd>
        <dt>Response-Länge</dt><dd><?= (int) ($result['content_length'] ?? 0) ?> Bytes</dd>
        <dt>Effektive URL</dt><dd><code><?= htmlspecialchars((string) ($result['effective_url'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></code></dd>
        <dt>Transport</dt><dd><?= htmlspecialchars((string) ($result['transport'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></dd>
        <dt>cURL installiert</dt><dd><?= !empty($result['curl_available']) ? 'ja' : 'nein' ?></dd>
        <dt>allow_url_fopen</dt><dd><?= !empty($result['allow_url_fopen']) ? 'ja' : 'nein' ?></dd>
        <dt>https-Wrapper (Fetcher)</dt><dd><?= !empty($result['https_wrapper']) ? 'ja' : 'nein' ?></dd>
        <dt>openssl (Fetcher)</dt><dd><?= !empty($result['openssl_loaded']) ? 'ja' : 'nein' ?></dd>
        <dt>cURL errno</dt><dd><?= htmlspecialchars((string) ($result['curl_errno'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></dd>
        <dt>cURL Fehler</dt><dd><?= htmlspecialchars((string) ($result['curl_error'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></dd>
        <dt>PHP last_error</dt><dd><?= htmlspecialchars((string) ($result['last_error'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></dd>
        <dt>Meldung</dt><dd><?= htmlspecialchars((string) ($result['error'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></dd>
    </dl>

    <?php if ($html !== ''): ?>
        <?php try {
            $parsed = (new ShopHtmlParser())->parse($html, $pzn);
            ?>
            <h2>Parser-Vorschau</h2>
            <dl>
                <dt>Produktname</dt><dd><?= htmlspecialchars((string) ($parsed['product_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></dd>
                <dt>Hersteller</dt><dd><?= htmlspecialchars((string) ($parsed['manufacturer'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></dd>
                <dt>Einheit</dt><dd><?= htmlspecialchars((string) ($parsed['package_size'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></dd>
                <dt>Preis</dt><dd><?= isset($parsed['price']) ? htmlspecialchars((string) $parsed['price'], ENT_QUOTES, 'UTF-8') : '—' ?></dd>
                <dt>AVP</dt><dd><?= isset($parsed['avp_price']) ? htmlspecialchars((string) $parsed['avp_price'], ENT_QUOTES, 'UTF-8') : '—' ?></dd>
            </dl>
        <?php } catch (Throwable $e) { ?>
            <p class="error">Parser: <?= htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') ?></p>
        <?php } ?>
    <?php endif; ?>

    <h2>HTML (erste 500 Zeichen, ShopFetcher)</h2>
    <pre><?= htmlspecialchars($preview !== '' ? $preview : '(leer)', ENT_QUOTES, 'UTF-8') ?></pre>
<?php endif; ?>
</body>
</html>
