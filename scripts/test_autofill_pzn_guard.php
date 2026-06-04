<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/modules/products/ProductFormDraft.php';
require_once dirname(__DIR__) . '/modules/products/ProductAutofillMerger.php';
require_once dirname(__DIR__) . '/modules/shop/ShopUrlValidator.php';
require_once dirname(__DIR__) . '/modules/shop/ShopHtmlParser.php';
require_once dirname(__DIR__) . '/modules/shop/PznMatchGuard.php';
require_once dirname(__DIR__) . '/modules/shop/OwnShopFeedFetcher.php';
require_once dirname(__DIR__) . '/modules/shop/OwnShopFeedParser.php';
require_once dirname(__DIR__) . '/modules/shop/OwnShopFeedCache.php';
require_once dirname(__DIR__) . '/modules/shop/OwnShopProductPageCache.php';
require_once dirname(__DIR__) . '/modules/shop/OwnShopFeedLookupService.php';
require_once dirname(__DIR__) . '/modules/shop/ShopFetcher.php';
require_once dirname(__DIR__) . '/modules/products/ProductRepository.php';
require_once dirname(__DIR__) . '/modules/shop/OwnShopRepository.php';
require_once dirname(__DIR__) . '/modules/shop/ShopSyncService.php';
require_once dirname(__DIR__) . '/modules/rankings/RankingRepository.php';
require_once dirname(__DIR__) . '/modules/rankings/RankingEngine.php';
require_once dirname(__DIR__) . '/modules/shop/PznAutofillService.php';
require_once dirname(__DIR__) . '/modules/products/product_http.php';

$pdo = Database::getConnection();
$examples = dirname(__DIR__) . '/docs/examples';
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

$product166 = (string) file_get_contents($examples . '/shop_product_16609329_page.html');
$product178 = (string) file_get_contents($examples . '/shop_product_17845084.html');
$feedMinimal = (string) file_get_contents($examples . '/own_shop_feed_minimal.csv');
$feedLastUpdate = trim((string) file_get_contents($examples . '/own_shop_feed_last_update.txt'));

$cacheRoot = dirname(__DIR__) . '/storage/database/_test/pzn_guard_' . bin2hex(random_bytes(3));
mkdir($cacheRoot . '/imports', 0755, true);
mkdir($cacheRoot . '/shop_products', 0755, true);

$lookup = new OwnShopFeedLookupService(
    new OwnShopFeedFetcher(5),
    new OwnShopFeedCache($cacheRoot . '/imports'),
    new OwnShopFeedParser(),
    new ShopUrlValidator('shop.apotheker-seidel.de'),
    new ShopFetcher(5),
    new ShopHtmlParser(),
    'https://shop.apotheker-seidel.de/feed.csv',
    'https://shop.apotheker-seidel.de/last_update.txt',
    'https://shop.apotheker-seidel.de/product?artnr={PZN}',
    new OwnShopProductPageCache($cacheRoot . '/shop_products'),
);

// 1) PZN 16609329 → BITE AWAY
$r166 = $lookup->resolvePzn('16609329', $product166, $feedMinimal, $feedLastUpdate);
check(
    $r166['status'] === 'single'
    && str_contains((string) ($r166['parsed']['product_name'] ?? ''), 'BITE AWAY'),
    'PZN 16609329 → BITE AWAY',
    $failures,
);

// 2) PZN 17845084 → HYLO DUAL
$r178 = $lookup->resolvePzn('17845084', $product178, $feedMinimal, $feedLastUpdate);
check(
    $r178['status'] === 'single'
    && str_contains((string) ($r178['parsed']['product_name'] ?? ''), 'HYLO DUAL'),
    'PZN 17845084 → HYLO DUAL',
    $failures,
);

// 3) Falsche PZN im Parser → blockiert
$blocked = false;
try {
    (new ShopHtmlParser())->parse($product166, '17845084');
} catch (RuntimeException $e) {
    $blocked = str_contains($e->getMessage(), 'PZN-Abgleich')
        || str_contains($e->getMessage(), 'nicht gefunden');
}
check($blocked, 'Falsche PZN im HTML blockiert Übernahme', $failures);

$resolvedWrong = $lookup->resolvePzn('17845084', $product166, $feedMinimal, $feedLastUpdate);
check($resolvedWrong['status'] === 'error', 'Resolve mit falschem HTML → Fehler', $failures);

// 4) Suche A, dann B – kein Name von A
$merger = new ProductAutofillMerger();
$draftA = ProductFormDraft::clearShopAutofillFields([
    'pzn' => '16609329',
    'name' => 'Sollte nicht bleiben',
    'manufacturer' => 'Alt GmbH',
    'sale_price' => '1.00',
]);
$mergedA = $merger->mergeDraft($draftA, $r166['parsed'], $r166['product_url'], true);
check(str_contains((string) ($mergedA['name'] ?? ''), 'BITE AWAY'), 'PZN A übernommen', $failures);

$draftB = ProductFormDraft::clearShopAutofillFields([
    'pzn' => '17845084',
    'name' => (string) ($mergedA['name'] ?? ''),
    'manufacturer' => (string) ($mergedA['manufacturer'] ?? ''),
]);
$mergedB = $merger->mergeDraft($draftB, $r178['parsed'], $r178['product_url'], true);
check(
    !str_contains((string) ($mergedB['name'] ?? ''), 'BITE AWAY')
    && str_contains((string) ($mergedB['name'] ?? ''), 'HYLO'),
    'PZN B ohne Daten von PZN A',
    $failures,
);

// 5) Blockierte Übernahme → kein Snapshot
$repo = new ProductRepository($pdo);
$mergerSvc = new ProductAutofillMerger();
$shopUrlValidator = new ShopUrlValidator('shop.apotheker-seidel.de');
$autofill = createPznAutofillService(
    $pdo,
    $repo,
    $mergerSvc,
    $shopUrlValidator,
    'https://shop.apotheker-seidel.de/',
    'https://shop.apotheker-seidel.de/renderProductSummary?pzn={PZN}',
    5,
    'Eigener Shop',
    false,
    'https://shop.apotheker-seidel.de/feed.csv',
    'https://shop.apotheker-seidel.de/last_update.txt',
    'https://shop.apotheker-seidel.de/product?artnr={PZN}',
    false,
    $cacheRoot,
);

$testPzn = '81' . random_int(100000, 999999);
$pdo->prepare(
    'INSERT INTO products (pzn, name, active, is_test, created_at, updated_at)
     VALUES (:pzn, \'PZN Guard Test\', 1, 1, datetime(\'now\'), datetime(\'now\'))'
)->execute(['pzn' => $testPzn]);
$productId = (int) $pdo->lastInsertId();
$beforeSnaps = (int) $pdo->query('SELECT COUNT(*) FROM price_snapshots WHERE product_id = ' . $productId)->fetchColumn();

$badHit = [
    'pzn' => $testPzn,
    'name' => 'Falsch',
    'url' => 'https://shop.apotheker-seidel.de/product?artnr=' . $testPzn,
    'parsed' => [
        'product_name' => 'BITE AWAY neo Stichheiler',
        'manufacturer' => 'X',
        'package_size' => '1 St',
        'pzn' => '16609329',
        'price' => 10.0,
        'avp_price' => null,
        'delivery_status' => 'lieferbar',
    ],
    'allow_feed_snapshot' => true,
];

$applyResult = $autofill->applyHitToProduct($productId, ['pzn' => $testPzn], $badHit, true, true);
$applyFailed = !($applyResult['success'] ?? false);
$afterSnaps = (int) $pdo->query('SELECT COUNT(*) FROM price_snapshots WHERE product_id = ' . $productId)->fetchColumn();
check($applyFailed, 'Blockierte Übernahme wirft Fehler', $failures);
check($afterSnaps === $beforeSnaps, 'Kein Snapshot bei blockierter Übernahme', $failures);

// Produktseiten-Cache pro PZN
$pageCache = new OwnShopProductPageCache($cacheRoot . '/shop_products');
$pageCache->write('16609329', $product166);
$pageCache->write('17845084', $product178);
check($pageCache->read('16609329') !== $pageCache->read('17845084'), 'Produktseiten-Cache getrennt pro PZN', $failures);

echo $failures === 0 ? "PZN GUARD TESTS BESTANDEN\n" : "PZN GUARD TESTS FEHLGESCHLAGEN ({$failures})\n";
exit($failures === 0 ? 0 : 1);
