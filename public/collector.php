<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/modules/collector/collector_factory.php';

Auth::requireAuth($config['session']['timeout']);

$currentNav = 'collector';
$pageTitle = 'Datenerfassung';
$user = Auth::getUser();

$pdo = Database::getConnection();
$service = createCollectorService($pdo, $config);
$collectorConfig = is_array($config['collector'] ?? null) ? $config['collector'] : [];
$mockMode = filter_var($collectorConfig['mock_mode'] ?? true, FILTER_VALIDATE_BOOL);
$collectorDebug = filter_var($collectorConfig['debug'] ?? false, FILTER_VALIDATE_BOOL);

$singleResult = $_SESSION['collector_single_result'] ?? null;
unset($_SESSION['collector_single_result']);

$runSummary = $_SESSION['collector_run_summary'] ?? null;
unset($_SESSION['collector_run_summary']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
    $action = post('action', '');

    if ($action === 'run-all') {
        try {
            $runSummary = $service->runAll();
            Auth::logAudit(
                (int) $user['id'],
                'collector_run_all',
                sprintf(
                    'Collector: Produkte=%d, Snapshots=%d, Fehler=%d, Status=%s',
                    $runSummary['products_processed'],
                    $runSummary['snapshots_created'],
                    $runSummary['errors'],
                    $runSummary['status']
                )
            );
            $_SESSION['collector_run_summary'] = $runSummary;
            flash('success', 'Gesamter Collector-Lauf abgeschlossen.');
        } catch (Throwable $e) {
            flash('error', 'Collector-Lauf fehlgeschlagen: ' . $e->getMessage());
        }
        redirect('collector.php');
    }

    if ($action === 'run-pzn') {
        $pzn = trim((string) post('pzn', ''));
        if ($pzn === '') {
            flash('error', 'Bitte eine PZN eingeben.');
            redirect('collector.php');
        }

        try {
            $singleResult = $service->runForPzn($pzn);
            Auth::logAudit(
                (int) $user['id'],
                'collector_run_pzn',
                'Collector PZN ' . $pzn . ': ' . ($singleResult['message'] ?? '')
            );
            $_SESSION['collector_single_result'] = $singleResult;
            flash(
                $singleResult['success'] ? 'success' : 'error',
                (string) ($singleResult['message'] ?? 'Collector beendet.'),
            );
        } catch (Throwable $e) {
            flash('error', 'Collector fehlgeschlagen: ' . $e->getMessage());
        }
        redirect('collector.php');
    }
}

$lastRun = $service->getLastRun();
$recentRuns = $service->getRecentRuns(15);
$latestLogs = $service->getLatestCollectorLogs(25);

renderLayout('modules/collector/list.php', compact(
    'pageTitle',
    'currentNav',
    'user',
    'config',
    'lastRun',
    'recentRuns',
    'latestLogs',
    'mockMode',
    'collectorDebug',
    'singleResult',
    'runSummary',
));
