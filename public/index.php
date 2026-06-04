<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/modules/snapshots/SnapshotRepository.php';
require_once dirname(__DIR__) . '/modules/competitors/CompetitorRepository.php';
require_once dirname(__DIR__) . '/modules/pricing/PricingRepository.php';
require_once dirname(__DIR__) . '/modules/collector/CollectorRepository.php';

Auth::requireAuth($config['session']['timeout']);

$pdo = Database::getConnection();
$snapshotRepository = new SnapshotRepository($pdo);
$competitorRepository = new CompetitorRepository($pdo);
$pricingRepository = new PricingRepository($pdo);
$collectorRepository = new CollectorRepository($pdo);
$lastCollectorRun = $collectorRepository->getLastRun();

$dashboardStats = [
    'observed_products' => $snapshotRepository->countObservedProducts(),
    'competitors' => count($competitorRepository->findAll(false)),
    'snapshots_today' => $snapshotRepository->countToday(),
    'snapshots_total' => $snapshotRepository->countAll(),
    'average_ranking' => $snapshotRepository->averageRanking(),
    'products_needing_action' => $pricingRepository->countProductsNeedingAction(),
    'last_collector_run' => $lastCollectorRun,
];

$pageTitle = 'Dashboard';
$currentNav = 'dashboard';
$user = Auth::getUser();

renderLayout('modules/dashboard/index.php', compact(
    'pageTitle',
    'currentNav',
    'user',
    'config',
    'dashboardStats',
    'lastCollectorRun',
));
