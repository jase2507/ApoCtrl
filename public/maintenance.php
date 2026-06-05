<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/modules/maintenance/MaintenanceRepository.php';
require_once dirname(__DIR__) . '/modules/maintenance/MaintenanceService.php';

Auth::requireAuth($config['session']['timeout']);
Auth::requireAdmin();

$pdo = Database::getConnection();
$service = new MaintenanceService(new MaintenanceRepository($pdo));

$currentNav = 'maintenance';
$user = Auth::getUser();
$actorId = (int) ($user['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
    $postAction = post('action', '');

    if ($postAction === 'cleanup-testdata') {
        $result = $service->cleanupTestData();

        if ($result === null) {
            flash('error', 'Keine Berechtigung für diese Aktion.');
            redirect('maintenance.php');
        }

        Auth::logAudit(
            $actorId,
            'maintenance_testdata_cleanup',
            'products_deleted=' . $result['products_deleted']
            . ', snapshots_deleted=' . $result['snapshots_deleted']
            . ', own_snapshots_deleted=' . $result['own_snapshots_deleted'],
        );

        flash(
            'success',
            'Testdaten bereinigt: '
            . $result['products_deleted'] . ' Produkt(e), '
            . $result['snapshots_deleted'] . ' Preis-Snapshot(s), '
            . $result['own_snapshots_deleted'] . ' Own-Shop-Snapshot(s).',
        );
        redirect('maintenance.php');
    }

    flash('error', 'Unbekannte Aktion.');
    redirect('maintenance.php');
}

renderLayout('modules/maintenance/index.php', [
    'pageTitle' => 'Wartung',
    'currentNav' => $currentNav,
    'user' => $user,
    'config' => $config,
    'stats' => $service->getTestDataStats(),
    'testProducts' => $service->findTestProducts(),
]);
