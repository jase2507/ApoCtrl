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
        $fetcher = new ShopFetcher($fetchTimeout);
        $rankingRepo = new RankingRepository($pdo);
        $syncService = new ShopSyncService(
            $repository,
            new OwnShopRepository($pdo, $ownCompetitorName),
            $shopUrlValidator,
            $fetcher,
            new ShopHtmlParser(),
            new RankingEngine($rankingRepo),
        );

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
        ));
    }
}
