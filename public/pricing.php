<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/modules/pricing/PricingRepository.php';
require_once dirname(__DIR__) . '/modules/pricing/PricingEngine.php';

Auth::requireAuth($config['session']['timeout']);

$currentNav = 'suggestions';
$pageTitle = 'Preisvorschläge';
$user = Auth::getUser();

$pdo = Database::getConnection();
$repository = new PricingRepository($pdo);
$engine = new PricingEngine($repository);

$showTest = query('show_test', '') === '1';
$products = $repository->findProductsWithTargetRanking($showTest);

$suggestions = [];
foreach ($products as $product) {
    $suggestion = $engine->suggestPrice((int) $product['id']);
    $suggestions[] = array_merge($product, $suggestion);
}

renderLayout('modules/pricing/list.php', compact(
    'pageTitle',
    'currentNav',
    'user',
    'config',
    'suggestions',
    'showTest',
));
