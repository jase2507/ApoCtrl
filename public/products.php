<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/modules/products/ProductRepository.php';
require_once dirname(__DIR__) . '/modules/products/ProductValidator.php';
require_once dirname(__DIR__) . '/modules/products/ProductFormDraft.php';
require_once dirname(__DIR__) . '/modules/products/ProductAutofillMerger.php';
require_once dirname(__DIR__) . '/modules/shop/ShopUrlValidator.php';
require_once dirname(__DIR__) . '/modules/shop/ShopFetcher.php';
require_once dirname(__DIR__) . '/modules/shop/ShopHtmlParser.php';
require_once dirname(__DIR__) . '/modules/shop/OwnShopRepository.php';
require_once dirname(__DIR__) . '/modules/shop/ShopSyncService.php';
require_once dirname(__DIR__) . '/modules/rankings/RankingRepository.php';
require_once dirname(__DIR__) . '/modules/rankings/RankingEngine.php';
require_once dirname(__DIR__) . '/modules/products/product_http.php';
require_once dirname(__DIR__) . '/modules/snapshots/SnapshotRepository.php';

Auth::requireAuth($config['session']['timeout']);

$pdo = Database::getConnection();
$repository = new ProductRepository($pdo);

$shopConfig = $config['shop'] ?? [];
$allowedHost = (string) ($shopConfig['allowed_host'] ?? 'shop.apotheker-seidel.de');
$baseUrl = (string) ($shopConfig['base_url'] ?? 'https://shop.apotheker-seidel.de/');
$fetchTimeout = (int) ($shopConfig['fetch_timeout'] ?? 15);
$ownCompetitorName = (string) ($shopConfig['own_competitor_name'] ?? 'Eigener Shop');
$searchUrlTemplate = (string) ($shopConfig['search_url'] ?? 'https://shop.apotheker-seidel.de/renderProductSummary?pzn={PZN}');
$feedUrl = (string) ($shopConfig['feed_url'] ?? '');
$feedLastUpdateUrl = (string) ($shopConfig['feed_last_update_url'] ?? '');
$deeplinkTemplate = (string) ($shopConfig['deeplink_template'] ?? 'https://shop.apotheker-seidel.de/product?artnr={PZN}');
$htmlSearchFallback = !empty($shopConfig['html_search_fallback']);
$debugAutofill = !empty($shopConfig['debug_autofill']);
$storageRoot = dirname(__DIR__) . '/storage';

$shopUrlValidator = new ShopUrlValidator($allowedHost);
$validator = new ProductValidator($repository, $shopUrlValidator);
$merger = new ProductAutofillMerger();

$action = trim((string) (query('action', '') ?? ''));
if ($action === '') {
    $action = 'list';
}
$currentNav = 'products';
$user = Auth::getUser();

$pznAutofillEmpty = ['status' => '', 'message' => '', 'hits' => []];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
    $postAction = post('action', '');

    if ($postAction === 'store') {
        $result = $validator->validate($_POST);
        if ($result['errors'] !== []) {
            renderProductForm(
                'Produkt anlegen',
                $currentNav,
                $user,
                $config,
                ProductFormDraft::toFormProduct(ProductFormDraft::fromPost($_POST)),
                $result['errors'],
                'store',
                false,
                $pznAutofillEmpty,
            );
            exit;
        }

        $id = $repository->create($result['data']);
        Auth::logAudit(
            $user['id'],
            'product_create',
            'Produkt angelegt: ID ' . $id . ', PZN ' . $result['data']['pzn']
        );

        $shopSync = createShopSyncService(
            $pdo,
            $repository,
            $shopUrlValidator,
            $fetchTimeout,
            $ownCompetitorName,
        );
        $snapshotBootstrap = bootstrapOwnShopSnapshotAfterSave($id, $result['data'], $shopSync);

        if ($snapshotBootstrap['attempted']) {
            Auth::logAudit(
                $user['id'],
                $snapshotBootstrap['success'] ? 'product_snapshot_created' : 'product_snapshot_failed',
                'Nach Anlegen ID ' . $id . ': ' . $snapshotBootstrap['message'],
            );
        }

        $flashMessage = 'Produkt wurde erfolgreich angelegt.';
        if ($snapshotBootstrap['attempted'] && $snapshotBootstrap['success']) {
            $flashMessage .= ' Eigen-Shop-Snapshot erzeugt und Ranking berechnet.';
        } elseif ($snapshotBootstrap['attempted'] && !$snapshotBootstrap['success']) {
            $flashMessage .= ' Hinweis: Snapshot/Ranking – ' . $snapshotBootstrap['message'];
        }

        flash('success', $flashMessage);
        redirect('products.php?action=edit&id=' . $id);
    }

    if ($postAction === 'update') {
        $id = (int) post('id', '0');
        $existing = $repository->findById($id);

        if ($existing === null) {
            flash('error', 'Produkt nicht gefunden.');
            redirect('products.php');
        }

        $result = $validator->validate($_POST, $id);
        if ($result['errors'] !== []) {
            renderProductForm(
                'Produkt bearbeiten',
                $currentNav,
                $user,
                $config,
                ProductFormDraft::toFormProduct(ProductFormDraft::fromPost($_POST), $existing),
                $result['errors'],
                'update',
                true,
                $pznAutofillEmpty,
            );
            exit;
        }

        $repository->update($id, $result['data']);
        Auth::logAudit(
            $user['id'],
            'product_update',
            'Produkt aktualisiert: ID ' . $id . ', PZN ' . $result['data']['pzn']
        );
        flash('success', 'Produkt wurde gespeichert.');
        redirect('products.php?action=edit&id=' . $id);
    }

    if ($postAction === 'pzn-search' || $postAction === 'pzn-apply') {
        $draft = ProductFormDraft::fromPost($_POST);
        $overwrite = !empty($_POST['autofill_overwrite']);
        $runSync = !empty($_POST['autofill_run_sync']);
        $productId = (int) post('id', '0');
        $existing = $productId > 0 ? $repository->findById($productId) : null;
        $isEdit = $existing !== null;
        $formAction = $isEdit ? 'update' : 'store';
        $pageTitle = $isEdit ? 'Produkt bearbeiten' : 'Produkt anlegen';

        $autofillService = createPznAutofillService(
            $pdo,
            $repository,
            $merger,
            $shopUrlValidator,
            $baseUrl,
            $searchUrlTemplate,
            $fetchTimeout,
            $ownCompetitorName,
            $debugAutofill,
            $feedUrl !== '' ? $feedUrl : null,
            $feedLastUpdateUrl !== '' ? $feedLastUpdateUrl : null,
            $deeplinkTemplate,
            $htmlSearchFallback,
            $storageRoot,
        );

        $pznAutofill = ['status' => '', 'message' => '', 'hits' => []];
        $product = ProductFormDraft::toFormProduct($draft, $existing);

        if ($postAction === 'pzn-search') {
            $search = $autofillService->searchByPzn($draft['pzn']);
            $pznAutofill = [
                'status' => $search['status'],
                'message' => $search['message'],
                'hits' => $search['hits'],
                'debug' => $search['debug'] ?? null,
                'parsed' => $search['parsed'] ?? null,
            ];

            if ($productId > 0 && $search['status'] === 'error') {
                $repository->recordShopSyncError($productId, $search['message']);
            }

            if ($search['status'] === 'single' && is_array($search['parsed']) && $search['hits'] !== []) {
                if ($productId > 0) {
                    $apply = $autofillService->applyHitToProduct(
                        $productId,
                        $draft,
                        $search['hits'][0],
                        $overwrite,
                        $runSync,
                    );
                    $refreshed = $repository->findById($productId);
                    $product = ProductFormDraft::toFormProduct($apply['draft'], $refreshed ?? $existing);
                    $pznAutofill['message'] = $apply['message'];
                    $pznAutofill['status'] = $apply['success'] ? 'applied' : 'error';
                } else {
                    $shopUrl = (string) ($search['shop_url'] ?? $search['hits'][0]['url']);
                    $product = ProductFormDraft::toFormProduct(
                        $merger->mergeDraft($draft, $search['parsed'], $shopUrl, $overwrite),
                        $existing,
                    );
                    $pznAutofill['status'] = 'applied';
                    $pznAutofill['message'] = 'Ein Treffer – Shopdaten wurden in leere Felder übernommen.';
                }
            }
        }

        if ($postAction === 'pzn-apply') {
            $hitUrl = trim((string) post('hit_url', ''));
            $hit = [
                'pzn' => ShopHtmlParser::normalizePzn($draft['pzn']),
                'name' => trim((string) post('hit_name', '')),
                'price' => post('hit_price', '') !== '' ? (float) post('hit_price', '') : null,
                'url' => $hitUrl,
            ];

            try {
                if ($productId > 0) {
                    $apply = $autofillService->applyHitToProduct(
                        $productId,
                        $draft,
                        $hit,
                        $overwrite,
                        $runSync,
                    );
                    $product = ProductFormDraft::toFormProduct(
                        $apply['draft'],
                        $repository->findById($productId),
                    );
                    $pznAutofill = [
                        'status' => $apply['success'] ? 'applied' : 'error',
                        'message' => $apply['message'],
                        'hits' => [],
                    ];
                } else {
                    $result = $autofillService->applyHitToDraft($draft, $hit, $overwrite);
                    $product = ProductFormDraft::toFormProduct($result['draft'], $existing);
                    $pznAutofill = [
                        'status' => 'applied',
                        'message' => $result['message'],
                        'hits' => [],
                    ];
                }
            } catch (Throwable $e) {
                if ($productId > 0) {
                    $repository->recordShopSyncError($productId, $e->getMessage());
                }
                $pznAutofill = [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'hits' => [],
                ];
            }
        }

        renderProductForm(
            $pageTitle,
            $currentNav,
            $user,
            $config,
            $product,
            [],
            $formAction,
            $isEdit,
            $pznAutofill,
        );
        exit;
    }

    if ($postAction === 'shop-sync') {
        $id = (int) post('id', '0');
        $existing = $repository->findById($id);

        if ($existing === null) {
            flash('error', 'Produkt nicht gefunden.');
            redirect('products.php');
        }

        $shopSync = createShopSyncService(
            $pdo,
            $repository,
            $shopUrlValidator,
            $fetchTimeout,
            $ownCompetitorName,
        );

        $syncResult = $shopSync->syncProduct($id);

        if ($syncResult['success']) {
            Auth::logAudit(
                $user['id'],
                'shop_sync_ok',
                'Shop-Sync OK: Produkt ID ' . $id . ', PZN ' . ($existing['pzn'] ?? '')
            );
            flash('success', $syncResult['message']);
        } else {
            Auth::logAudit(
                $user['id'],
                'shop_sync_error',
                'Shop-Sync Fehler: Produkt ID ' . $id . ' – ' . $syncResult['message']
            );
            flash('error', $syncResult['message']);
        }

        redirect('products.php?action=edit&id=' . $id);
    }

    if ($postAction === 'deactivate') {
        $id = (int) post('id', '0');
        $existing = $repository->findById($id);

        if ($existing === null) {
            flash('error', 'Produkt nicht gefunden.');
            redirect('products.php');
        }

        $repository->setActive($id, 0);
        Auth::logAudit(
            $user['id'],
            'product_deactivate',
            'Produkt deaktiviert: ID ' . $id . ', PZN ' . $existing['pzn']
        );
        flash('success', 'Produkt wurde deaktiviert.');
        redirect('products.php');
    }

    flash('error', 'Unbekannte Aktion.');
    redirect('products.php');
}

match ($action) {
    'list' => (function () use ($repository, $config, $currentNav, $user): void {
        $search = query('q', '') ?? '';
        $activeFilter = query('filter', 'all') ?? 'all';

        if (!in_array($activeFilter, ['all', 'active', 'inactive'], true)) {
            $activeFilter = 'all';
        }

        $products = $repository->findAll($search !== '' ? $search : null, $activeFilter);
        $pageTitle = 'Produkte';

        renderLayout('modules/products/list.php', compact(
            'pageTitle', 'currentNav', 'user', 'config', 'products', 'search', 'activeFilter'
        ));
    })(),

    'create' => (function () use ($config, $currentNav, $user, $pznAutofillEmpty): void {
        renderProductForm(
            'Produkt anlegen',
            $currentNav,
            $user,
            $config,
            null,
            [],
            'store',
            false,
            $pznAutofillEmpty,
        );
    })(),

    'edit' => (function () use ($repository, $pdo, $config, $currentNav, $user, $pznAutofillEmpty): void {
        $id = (int) (query('id', '0') ?? '0');
        $product = $repository->findById($id);

        if ($product === null) {
            flash('error', 'Produkt nicht gefunden.');
            redirect('products.php');
        }

        $snapshotRepository = new SnapshotRepository($pdo);
        $priceHistory = $snapshotRepository->findByProduct($id, 100);

        renderProductForm(
            'Produkt bearbeiten',
            $currentNav,
            $user,
            $config,
            $product,
            [],
            'update',
            true,
            $pznAutofillEmpty,
            $priceHistory,
        );
    })(),

    default => (function (): void {
        flash('error', 'Unbekannte Aktion.');
        redirect('products.php');
    })(),
};
