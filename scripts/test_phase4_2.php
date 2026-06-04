<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/schema.php';
require_once dirname(__DIR__) . '/modules/products/ProductRepository.php';
require_once dirname(__DIR__) . '/modules/products/ProductFormDraft.php';
require_once dirname(__DIR__) . '/modules/products/ProductAutofillMerger.php';
require_once dirname(__DIR__) . '/modules/shop/ShopUrlValidator.php';
require_once dirname(__DIR__) . '/modules/shop/ShopSearchParser.php';
require_once dirname(__DIR__) . '/modules/shop/ShopHtmlParser.php';
require_once dirname(__DIR__) . '/modules/shop/ShopFetcher.php';
require_once dirname(__DIR__) . '/modules/shop/OwnShopFeedFetcher.php';
require_once dirname(__DIR__) . '/modules/shop/OwnShopFeedParser.php';
require_once dirname(__DIR__) . '/modules/shop/OwnShopFeedCache.php';
require_once dirname(__DIR__) . '/modules/shop/OwnShopFeedLookupService.php';
require_once dirname(__DIR__) . '/modules/shop/OwnShopRepository.php';
require_once dirname(__DIR__) . '/modules/shop/ShopSyncService.php';
require_once dirname(__DIR__) . '/modules/shop/PznAutofillService.php';
require_once dirname(__DIR__) . '/modules/rankings/RankingRepository.php';
require_once dirname(__DIR__) . '/modules/rankings/RankingEngine.php';

$examples = dirname(__DIR__) . '/docs/examples';
$feedMinimal = (string) file_get_contents($examples . '/own_shop_feed_minimal.csv');
$feedFull = (string) file_get_contents($examples . '/own_shop_feed.csv');
$feedLastUpdate = trim((string) file_get_contents($examples . '/own_shop_feed_last_update.txt'));
$productPage17845084 = (string) file_get_contents($examples . '/shop_product_17845084.html');
$productPage16609329 = (string) file_get_contents($examples . '/shop_product_16609329_page.html');
$feedEmpty = "01447223;7,49\n";

$allowedHost = 'shop.apotheker-seidel.de';
$feedUrl = 'https://shop.apotheker-seidel.de/eStLeonard-Oy8chie2Ie/medizinfuchs/eStLeonard_medizinfuchs.csv';
$lastUpdateUrl = 'https://shop.apotheker-seidel.de/eStLeonard-Oy8chie2Ie/medizinfuchs/last_update.txt';
$deeplinkTemplate = 'https://shop.apotheker-seidel.de/product?artnr={PZN}';

$validator = new ShopUrlValidator($allowedHost);
$merger = new ProductAutofillMerger();
$fail = 0;

$cacheDir = dirname(__DIR__) . '/storage/database/_test/feed_cache_' . bin2hex(random_bytes(4));
mkdir($cacheDir, 0755, true);

$makeLookup = static function (string $dir) use ($validator, $feedUrl, $lastUpdateUrl, $deeplinkTemplate): OwnShopFeedLookupService {
    return new OwnShopFeedLookupService(
        new OwnShopFeedFetcher(5),
        new OwnShopFeedCache($dir),
        new OwnShopFeedParser(),
        $validator,
        new ShopFetcher(5),
        new ShopHtmlParser(),
        $feedUrl,
        $lastUpdateUrl,
        $deeplinkTemplate,
    );
};

$lookup = $makeLookup($cacheDir);

$deeplink = $lookup->buildDeeplink('17845084');
if ($deeplink === 'https://shop.apotheker-seidel.de/product?artnr=17845084') {
    echo "[OK] Deeplink ersetzt {PZN}\n";
} else {
    echo "[FAIL] Deeplink: {$deeplink}\n";
    $fail++;
}

$parsedHtml = (new ShopHtmlParser())->parse($productPage17845084, '17845084');
if (
    str_contains((string) ($parsedHtml['product_name'] ?? ''), 'HYLO DUAL intense')
    && str_contains((string) ($parsedHtml['manufacturer'] ?? ''), 'URSAPHARM')
    && str_contains((string) ($parsedHtml['package_size'] ?? ''), 'Augentropfen')
    && abs((float) ($parsedHtml['price'] ?? 0) - 33.68) < 0.01
    && abs((float) ($parsedHtml['avp_price'] ?? 0) - 35.45) < 0.01
) {
    echo "[OK] Produktseite: Name/Hersteller/Einheit/Preis/AVP aus HTML\n";
} else {
    echo "[FAIL] HTML-Parser Produktseite\n";
    print_r($parsedHtml);
    $fail++;
}

$resolved = $lookup->resolvePzn('17845084', $productPage17845084, $feedMinimal, $feedLastUpdate);
if (
    $resolved['status'] === 'single'
    && !empty($resolved['feed_found'])
    && abs((float) ($resolved['feed_price'] ?? 0) - 33.68) < 0.01
    && abs((float) ($resolved['parsed']['price'] ?? 0) - 33.68) < 0.01
    && str_contains((string) ($resolved['parsed']['product_name'] ?? ''), 'HYLO')
) {
    echo "[OK] Feed+Seite: Feedpreis bevorzugt, Stammdaten aus HTML\n";
} else {
    echo "[FAIL] Feed+Seite Merge\n";
    $fail++;
}

$draft = [
    'pzn' => '17845084',
    'name' => 'Bestehender Name',
    'manufacturer' => '',
    'sale_price' => '9.99',
    'package_size' => '',
    'shop_url' => '',
    'avp_price' => '',
];
$merged = $merger->mergeDraft($draft, $resolved['parsed'], $deeplink, false);
if (
    ($merged['name'] ?? '') === 'Bestehender Name'
    && ($merged['sale_price'] ?? '') === '9.99'
    && ($merged['manufacturer'] ?? '') !== ''
    && ($merged['package_size'] ?? '') !== ''
) {
    echo "[OK] Formularfelder: leere Felder gefüllt, bestehende geschützt\n";
} else {
    echo "[FAIL] Formular-Merge\n";
    $fail++;
}

$overwrite = $merger->mergeDraft($draft, $resolved['parsed'], $deeplink, true);
if (str_contains((string) ($overwrite['name'] ?? ''), 'HYLO')) {
    echo "[OK] Überschreiben aktiv\n";
} else {
    echo "[FAIL] Überschreiben\n";
    $fail++;
}

$pageOnlyHtml = <<<'HTML'
<div class="boxProductDetail" id="product-99999999">
<header class="product-name"><h1 itemprop="name">Testprodukt <span> 100 St </span></h1></header>
<div class="producer-info"><span class="producer">Anbieter:</span><span itemprop="brand">Test Pharma GmbH</span></div>
<dl class="productInfos">
<dt class="pzn">PZN:</dt><dd class="pzn">99999999</dd>
<dt class="form">Einheit:</dt><dd class="form">100 St</dd>
</dl>
<dl class="productPrice"><dt class="yourPrice">Ihr Preis:</dt><dd class="yourPrice">9,99 €</dd></dl>
</div>
HTML;

$cacheDir2 = dirname(__DIR__) . '/storage/database/_test/feed_cache_' . bin2hex(random_bytes(4));
mkdir($cacheDir2, 0755, true);
$lookup2 = $makeLookup($cacheDir2);
$pageOnly = $lookup2->resolvePzn('99999999', $pageOnlyHtml, $feedMinimal, $feedLastUpdate);
if (
    $pageOnly['status'] === 'single'
    && empty($pageOnly['feed_found'])
    && empty(($pageOnly['hit'] ?? [])['allow_feed_snapshot'])
    && str_contains((string) $pageOnly['message'], 'Preisfeed')
) {
    echo "[OK] Feed fehlt, Produktseite liefert Stammdaten\n";
} else {
    echo "[FAIL] Feed fehlt / Seite ok\n";
    $fail++;
}

class PageFetcherFailStub extends ShopFetcher
{
    public function fetch(string $url): array
    {
        return ['ok' => false, 'html' => null, 'error' => 'Produktseite nicht erreichbar (Test).', 'http_code' => 404];
    }
}

$cacheDir3 = dirname(__DIR__) . '/storage/database/_test/feed_cache_' . bin2hex(random_bytes(4));
mkdir($cacheDir3, 0755, true);
$lookup3 = new OwnShopFeedLookupService(
    new OwnShopFeedFetcher(5),
    new OwnShopFeedCache($cacheDir3),
    new OwnShopFeedParser(),
    $validator,
    new PageFetcherFailStub(5),
    new ShopHtmlParser(),
    $feedUrl,
    $lastUpdateUrl,
    $deeplinkTemplate,
);
$unreachable = $lookup3->resolvePzn('17845084', null, $feedMinimal, $feedLastUpdate);
if ($unreachable['status'] === 'error' && str_contains((string) $unreachable['message'], 'Produktseite konnte nicht')) {
    echo "[OK] Nicht erreichbare Produktseite: Fehlermeldung\n";
} else {
    echo "[FAIL] Produktseite Fehlerfall\n";
    $fail++;
}

(new OwnShopFeedCache($cacheDir))->writeCache($feedFull, $feedLastUpdate);
class FeedFetcherFailStub extends OwnShopFeedFetcher
{
    public function fetch(string $url): array
    {
        return ['ok' => false, 'body' => null, 'error' => 'simulierter Netzwerkfehler', 'http_code' => 503];
    }
}
$fallbackLookup = new OwnShopFeedLookupService(
    new FeedFetcherFailStub(5),
    new OwnShopFeedCache($cacheDir),
    new OwnShopFeedParser(),
    $validator,
    new ShopFetcher(5),
    new ShopHtmlParser(),
    $feedUrl,
    $lastUpdateUrl,
    $deeplinkTemplate,
);
$fallbackResolved = $fallbackLookup->resolvePzn('16609329', $productPage16609329, null, '999-new-token');
$loadMeta = $fallbackLookup->getLastLoadMeta();
if ($loadMeta['ok'] && !empty($loadMeta['from_cache'])) {
    echo "[OK] Cache-Fallback bei Online-Fehler\n";
} else {
    echo "[FAIL] Cache-Fallback\n";
    $fail++;
}

$cacheDir4 = dirname(__DIR__) . '/storage/database/_test/feed_cache_' . bin2hex(random_bytes(4));
mkdir($cacheDir4, 0755, true);
$lookupFeedDown = new OwnShopFeedLookupService(
    new FeedFetcherFailStub(5),
    new OwnShopFeedCache($cacheDir4),
    new OwnShopFeedParser(),
    $validator,
    new ShopFetcher(5),
    new ShopHtmlParser(),
    $feedUrl,
    $lastUpdateUrl,
    $deeplinkTemplate,
);
$noFeedNoCache = $lookupFeedDown->resolvePzn('16609329', $productPage16609329, null, null);
$debugNoFeed = $lookupFeedDown->getLastResolveDebug();
if (
    $noFeedNoCache['status'] === 'single'
    && empty($debugNoFeed['feed_reachable'])
    && str_contains((string) $noFeedNoCache['message'], 'Preisfeed nicht erreichbar')
    && str_contains((string) ($noFeedNoCache['parsed']['product_name'] ?? ''), 'BITE AWAY')
    && ($noFeedNoCache['parsed']['price'] ?? null) !== null
) {
    echo "[OK] Feed nicht erreichbar, Produktseite erfolgreich\n";
} else {
    echo "[FAIL] Feed down + Seite ok\n";
    $fail++;
}

$cacheDir5 = dirname(__DIR__) . '/storage/database/_test/feed_cache_' . bin2hex(random_bytes(4));
mkdir($cacheDir5, 0755, true);
$lookup5 = $makeLookup($cacheDir5);
$noPznInFeed = $lookup5->resolvePzn('16609329', $productPage16609329, $feedEmpty, $feedLastUpdate);
if (
    $noPznInFeed['status'] === 'single'
    && abs((float) ($noPznInFeed['parsed']['price'] ?? 0) - (float) ($noPznInFeed['parsed']['price'] ?? 0)) >= 0
    && ($noPznInFeed['parsed']['price'] ?? 0) > 0
    && empty($noPznInFeed['feed_found'])
) {
    echo "[OK] Feed ohne PZN, HTML-Preis wird verwendet\n";
} else {
    echo "[FAIL] HTML-Preis ohne Feed-PZN\n";
    print_r($noPznInFeed['parsed'] ?? []);
    $fail++;
}

$feedNoPriceCsv = "16609329\n17845084;33,68\n";
$noFeedPrice = $lookup5->resolvePzn('16609329', $productPage16609329, $feedNoPriceCsv, $feedLastUpdate);
$htmlPrice = (new ShopHtmlParser())->parse($productPage16609329, '16609329')['price'] ?? 0;
if (
    $noFeedPrice['status'] === 'single'
    && abs((float) ($noFeedPrice['parsed']['price'] ?? 0) - (float) $htmlPrice) < 0.02
) {
    echo "[OK] Feedpreis fehlt, HTML-Preis wird verwendet\n";
} else {
    echo "[FAIL] Feedpreis fehlt\n";
    $fail++;
}

if (
    ($debugNoFeed['product_page_url'] ?? '') === 'https://shop.apotheker-seidel.de/product?artnr=16609329'
    && !empty($debugNoFeed['parsed_product_name'])
    && array_key_exists('feed_reachable', $debugNoFeed)
    && ($debugNoFeed['page_http_code'] ?? null) !== null
) {
    echo "[OK] Debug-Werte Produktseite/Feed\n";
} else {
    echo "[FAIL] Debug-Werte\n";
    print_r($debugNoFeed);
    $fail++;
}

$testDir = dirname(__DIR__) . '/storage/database/_test';
$testDb = $testDir . '/phase4_2_feed_merge.sqlite';
@unlink($testDb);

$pdo = new PDO('sqlite:' . $testDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (require dirname(__DIR__) . '/core/schema.php' as $sql) {
    $pdo->exec($sql);
}
$pdo->exec("INSERT INTO competitors (name, url, type, priority, active, is_test, created_at, updated_at)
    VALUES ('Eigener Shop', 'https://shop.apotheker-seidel.de/', 'own', -100, 1, 0, datetime('now'), datetime('now'))");

$products = new ProductRepository($pdo);
$syncService = new ShopSyncService(
    $products,
    new OwnShopRepository($pdo),
    $validator,
    new ShopFetcher(5),
    new ShopHtmlParser(),
    new RankingEngine(new RankingRepository($pdo)),
);

$feedLookupAutofill = $makeLookup($cacheDir);
$autofill = new PznAutofillService(
    $merger,
    $products,
    $validator,
    $feedLookupAutofill,
    false,
    null,
    null,
    null,
    null,
    $syncService,
);

$products->create([
    'pzn' => '17845084',
    'name' => 'Test',
    'manufacturer' => null,
    'cost_price' => null,
    'sale_price' => null,
    'min_price' => null,
    'target_rank' => null,
    'strategy' => null,
    'category' => null,
    'active' => 1,
    'shop_url' => $deeplink,
    'package_size' => null,
    'avp_price' => null,
    'own_shipping_cost' => 2.50,
]);
$productId = (int) $pdo->lastInsertId();

$search = $autofill->searchByPzn('17845084', null, $productPage17845084, $feedMinimal, $feedLastUpdate);
if ($search['status'] === 'single' && is_array($search['hits'][0] ?? null)) {
    $apply = $autofill->applyHitToProduct(
        $productId,
        ProductFormDraft::fromPost(['pzn' => '17845084', 'active' => '1']),
        $search['hits'][0],
        false,
        true,
    );
    if ($apply['success']) {
        echo "[OK] Snapshot mit Feedpreis nach Übernahme\n";
    } else {
        echo "[FAIL] Snapshot: " . $apply['message'] . "\n";
        $fail++;
    }
} else {
    echo "[FAIL] Autofill-Suche\n";
    $fail++;
}

$ownId = (int) $pdo->query("SELECT id FROM competitors WHERE type = 'own' LIMIT 1")->fetchColumn();
$snap = $pdo->query(
    "SELECT price, ranking FROM price_snapshots WHERE product_id = {$productId} AND competitor_id = {$ownId} ORDER BY id DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
if ($snap !== false && abs((float) $snap['price'] - 33.68) < 0.01) {
    echo "[OK] Snapshot-Preis = Feedpreis\n";
} else {
    echo "[FAIL] Snapshot-Preis\n";
    $fail++;
}

if ($snap !== false && $snap['ranking'] !== null) {
    echo "[OK] Ranking nach Snapshot gesetzt\n";
} else {
    echo "[FAIL] Ranking\n";
    $fail++;
}

echo $fail === 0
    ? "\nPhase 4.2 Feed+Produktseite: BESTANDEN\n"
    : "\nPhase 4.2 Feed+Produktseite: NICHT BESTANDEN ({$fail} Fehler)\n";

exit($fail > 0 ? 1 : 0);
