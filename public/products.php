<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/modules/products/ProductRepository.php';
require_once dirname(__DIR__) . '/modules/products/ProductValidator.php';

Auth::requireAuth($config['session']['timeout']);

$pdo = Database::getConnection();
$repository = new ProductRepository($pdo);
$validator = new ProductValidator($repository);

$action = query('action', 'list') ?? 'list';
$currentNav = 'products';
$user = Auth::getUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
    $postAction = post('action', '');

    if ($postAction === 'store') {
        $result = $validator->validate($_POST);
        if ($result['errors'] !== []) {
            $pageTitle = 'Produkt anlegen';
            $errors = $result['errors'];
            $product = null;
            $isEdit = false;
            $formAction = 'store';
            renderLayout('modules/products/form.php', compact(
                'pageTitle', 'currentNav', 'user', 'config', 'product', 'errors', 'formAction', 'isEdit'
            ));
            exit;
        }

        $id = $repository->create($result['data']);
        Auth::logAudit(
            $user['id'],
            'product_create',
            'Produkt angelegt: ID ' . $id . ', PZN ' . $result['data']['pzn']
        );
        flash('success', 'Produkt wurde erfolgreich angelegt.');
        redirect('products.php');
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
            $pageTitle = 'Produkt bearbeiten';
            $errors = $result['errors'];
            $product = array_merge($existing, $_POST);
            $product['id'] = $id;
            $isEdit = true;
            $formAction = 'update';
            renderLayout('modules/products/form.php', compact(
                'pageTitle', 'currentNav', 'user', 'config', 'product', 'errors', 'formAction', 'isEdit'
            ));
            exit;
        }

        $repository->update($id, $result['data']);
        Auth::logAudit(
            $user['id'],
            'product_update',
            'Produkt aktualisiert: ID ' . $id . ', PZN ' . $result['data']['pzn']
        );
        flash('success', 'Produkt wurde gespeichert.');
        redirect('products.php');
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
    'create' => (function () use ($config, $currentNav, $user): void {
        $pageTitle = 'Produkt anlegen';
        $product = null;
        $errors = [];
        $formAction = 'store';
        $isEdit = false;
        renderLayout('modules/products/form.php', compact(
            'pageTitle', 'currentNav', 'user', 'config', 'product', 'errors', 'formAction', 'isEdit'
        ));
    })(),

    'edit' => (function () use ($repository, $config, $currentNav, $user): void {
        $id = (int) (query('id', '0') ?? '0');
        $product = $repository->findById($id);

        if ($product === null) {
            flash('error', 'Produkt nicht gefunden.');
            redirect('products.php');
        }

        $pageTitle = 'Produkt bearbeiten';
        $errors = [];
        $formAction = 'update';
        $isEdit = true;
        renderLayout('modules/products/form.php', compact(
            'pageTitle', 'currentNav', 'user', 'config', 'product', 'errors', 'formAction', 'isEdit'
        ));
    })(),

    default => (function () use ($repository, $config, $currentNav, $user): void {
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
};
