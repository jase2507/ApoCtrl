<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/modules/rankings/RankingRepository.php';
require_once dirname(__DIR__) . '/modules/rankings/RankingEngine.php';

Auth::requireAuth($config['session']['timeout']);

$currentNav = 'rankings';
$pageTitle = 'Rankings';
$user = Auth::getUser();

$repository = new RankingRepository(Database::getConnection());
$engine = new RankingEngine($repository);

$runSummary = null;
$filter = query('q', '') ?? '';
$showTest = query('show_test', '') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
    $action = post('action', '');

    if ($action === 'run-all') {
        $runSummary = $engine->runAll();
        Auth::logAudit(
            (int) $user['id'],
            'ranking_run_all',
            'Rankinglauf (alle Produkte): Gruppen=' . $runSummary['groups'] . ', ranked=' . $runSummary['ranked'] . ', ignored=' . $runSummary['ignored']
        );
        flash('success', 'Rankinglauf für alle Produkte abgeschlossen.');
    } elseif ($action === 'run-product') {
        $pzn = trim((string) post('pzn', ''));
        if ($pzn === '') {
            flash('error', 'Bitte eine PZN für den Einzel-Lauf angeben.');
            redirect('rankings.php');
        }

        $product = $repository->findProductByPzn($pzn);
        if ($product === null) {
            flash('error', 'Produkt mit PZN ' . $pzn . ' nicht gefunden.');
            redirect('rankings.php');
        }

        $runSummary = $engine->runForProduct((int) $product['id']);
        Auth::logAudit(
            (int) $user['id'],
            'ranking_run_product',
            'Rankinglauf Produkt ' . $pzn . ': Gruppen=' . $runSummary['groups'] . ', ranked=' . $runSummary['ranked'] . ', ignored=' . $runSummary['ignored']
        );
        flash('success', 'Rankinglauf für Produkt ' . $pzn . ' abgeschlossen.');
    }
}

$latestRows = $repository->fetchLatestRankingRows($filter !== '' ? $filter : null, $showTest);
$products = $repository->listProducts($showTest);

renderLayout('modules/rankings/index.php', compact(
    'pageTitle',
    'currentNav',
    'user',
    'config',
    'runSummary',
    'latestRows',
    'products',
    'filter',
    'showTest',
));
