<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shop/ShopSearchUrlBuilder.php';
require_once dirname(__DIR__) . '/shop/ShopSearchParser.php';
require_once dirname(__DIR__) . '/shop/OwnShopFeedFetcher.php';
require_once dirname(__DIR__) . '/shop/OwnShopFeedParser.php';
require_once dirname(__DIR__) . '/shop/OwnShopFeedCache.php';
require_once dirname(__DIR__) . '/shop/OwnShopFeedLookupService.php';
require_once dirname(__DIR__) . '/shop/PznAutofillService.php';
require_once dirname(__DIR__) . '/shop/ShopSyncService.php';
require_once dirname(__DIR__) . '/rankings/RankingRepository.php';
require_once dirname(__DIR__) . '/rankings/RankingEngine.php';
require_once __DIR__ . '/ProductAutofillMerger.php';

if (!function_exists('createShopSyncService')) {
    function createShopSyncService(
        PDO $pdo,
        ProductRepository $repository,
        ShopUrlValidator $shopUrlValidator,
        int $fetchTimeout,
        string $ownCompetitorName,
    ): ShopSyncService {
        return new ShopSyncService(
            $repository,
            new OwnShopRepository($pdo, $ownCompetitorName),
            $shopUrlValidator,
            new ShopFetcher($fetchTimeout),
            new ShopHtmlParser(),
            new RankingEngine(new RankingRepository($pdo)),
        );
    }
}

if (!function_exists('productSavedDataToParsed')) {
    /**
     * @param array<string, mixed> $data
     * @return array{
     *   product_name:?string,
     *   manufacturer:?string,
     *   package_size:?string,
     *   pzn:?string,
     *   price:?float,
     *   avp_price:?float,
     *   delivery_status:?string
     * }
     */
    function productSavedDataToParsed(array $data): array
    {
        $salePrice = $data['sale_price'] ?? null;

        return [
            'product_name' => isset($data['name']) ? (string) $data['name'] : null,
            'manufacturer' => isset($data['manufacturer']) ? (string) $data['manufacturer'] : null,
            'package_size' => isset($data['package_size']) ? (string) $data['package_size'] : null,
            'pzn' => isset($data['pzn']) ? (string) $data['pzn'] : null,
            'price' => $salePrice !== null && $salePrice !== '' ? (float) $salePrice : null,
            'avp_price' => isset($data['avp_price']) && $data['avp_price'] !== null && $data['avp_price'] !== ''
                ? (float) $data['avp_price']
                : null,
            'delivery_status' => 'lieferbar',
        ];
    }
}

if (!function_exists('bootstrapOwnShopSnapshotAfterSave')) {
    /**
     * Eigenen-Shop-Snapshot + Ranking nach dem Anlegen (PZN-Autofill ohne product_id).
     *
     * @param array<string, mixed> $savedData Ergebnis von ProductValidator / create()
     * @return array{attempted:bool, success:bool, message:string}
     */
    function bootstrapOwnShopSnapshotAfterSave(
        int $productId,
        array $savedData,
        ShopSyncService $shopSync,
    ): array {
        if ((int) ($savedData['active'] ?? 0) !== 1) {
            return ['attempted' => false, 'success' => false, 'message' => ''];
        }

        $parsed = productSavedDataToParsed($savedData);
        if ($parsed['price'] === null) {
            return ['attempted' => false, 'success' => false, 'message' => ''];
        }

        $sync = $shopSync->syncProductFromParsed($productId, $parsed);

        return [
            'attempted' => true,
            'success' => (bool) ($sync['success'] ?? false),
            'message' => (string) ($sync['message'] ?? ''),
        ];
    }
}

if (!function_exists('createPznAutofillService')) {
    function createPznAutofillService(
        PDO $pdo,
        ProductRepository $repository,
        ProductAutofillMerger $merger,
        ShopUrlValidator $shopUrlValidator,
        string $baseUrl,
        string $searchUrlTemplate,
        int $fetchTimeout,
        string $ownCompetitorName,
        bool $debugAutofill = false,
        ?string $feedUrl = null,
        ?string $feedLastUpdateUrl = null,
        ?string $deeplinkTemplate = null,
        bool $htmlSearchFallback = false,
        string $storageRoot = '',
    ): PznAutofillService {
        $syncService = createShopSyncService(
            $pdo,
            $repository,
            $shopUrlValidator,
            $fetchTimeout,
            $ownCompetitorName,
        );
        $fetcher = new ShopFetcher($fetchTimeout);

        $feedLookup = null;
        if ($feedUrl !== null && $feedUrl !== '' && $feedLastUpdateUrl !== null && $feedLastUpdateUrl !== '') {
            $importsDir = $storageRoot !== ''
                ? rtrim($storageRoot, '/\\') . '/imports'
                : dirname(__DIR__, 2) . '/storage/imports';

            $feedLookup = new OwnShopFeedLookupService(
                new OwnShopFeedFetcher($fetchTimeout),
                new OwnShopFeedCache($importsDir),
                new OwnShopFeedParser(),
                $shopUrlValidator,
                $fetcher,
                new ShopHtmlParser(),
                $feedUrl,
                $feedLastUpdateUrl,
                $deeplinkTemplate !== null && $deeplinkTemplate !== ''
                    ? $deeplinkTemplate
                    : 'https://shop.apotheker-seidel.de/product?artnr={PZN}',
            );
        }

        $htmlBuilder = $htmlSearchFallback ? new ShopSearchUrlBuilder($shopUrlValidator, $searchUrlTemplate) : null;
        $htmlParser = $htmlSearchFallback ? new ShopSearchParser($shopUrlValidator, $baseUrl) : null;

        return new PznAutofillService(
            $merger,
            $repository,
            $shopUrlValidator,
            $feedLookup,
            $htmlSearchFallback,
            $htmlBuilder,
            $htmlParser,
            $htmlSearchFallback ? $fetcher : null,
            $htmlSearchFallback ? new ShopHtmlParser() : null,
            $syncService,
            $debugAutofill,
        );
    }
}

if (!function_exists('renderProductForm')) {
    /**
     * @param array{status:string,message:string,hits:list<array<string,mixed>>} $pznAutofill
     */
    function renderProductForm(
        string $pageTitle,
        string $currentNav,
        array $user,
        array $config,
        ?array $product,
        array $errors,
        string $formAction,
        bool $isEdit,
        array $pznAutofill,
        array $priceHistory = [],
        ?array $priceSuggestion = null,
    ): void {
        renderLayout('modules/products/form.php', compact(
            'pageTitle',
            'currentNav',
            'user',
            'config',
            'product',
            'errors',
            'formAction',
            'isEdit',
            'pznAutofill',
            'priceHistory',
            'priceSuggestion',
        ));
    }
}
