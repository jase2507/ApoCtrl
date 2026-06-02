<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/modules/competitors/CompetitorRepository.php';
require_once dirname(__DIR__) . '/modules/competitors/CompetitorValidator.php';

Auth::requireAuth($config['session']['timeout']);

$pdo = Database::getConnection();
$repository = new CompetitorRepository($pdo);
$validator = new CompetitorValidator($repository);

$action = query('action', 'list') ?? 'list';
$currentNav = 'competitors';
$user = Auth::getUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
    $postAction = post('action', '');

    if ($postAction === 'store') {
        $result = $validator->validate($_POST);
        if ($result['errors'] !== []) {
            $pageTitle = 'Wettbewerber anlegen';
            $errors = $result['errors'];
            $competitor = null;
            $isEdit = false;
            $formAction = 'store';
            renderLayout('modules/competitors/form.php', compact(
                'pageTitle', 'currentNav', 'user', 'config', 'competitor', 'errors', 'formAction', 'isEdit'
            ));
            exit;
        }

        $id = $repository->create($result['data']);
        Auth::logAudit(
            $user['id'],
            'competitor_create',
            'Wettbewerber angelegt: ID ' . $id . ', Name ' . $result['data']['name']
        );
        flash('success', 'Wettbewerber wurde erfolgreich angelegt.');
        redirect('competitors.php');
    }

    if ($postAction === 'update') {
        $id = (int) post('id', '0');
        $existing = $repository->findById($id);

        if ($existing === null) {
            flash('error', 'Wettbewerber nicht gefunden.');
            redirect('competitors.php');
        }

        $result = $validator->validate($_POST, $id);
        if ($result['errors'] !== []) {
            $pageTitle = 'Wettbewerber bearbeiten';
            $errors = $result['errors'];
            $competitor = array_merge($existing, $_POST);
            $competitor['id'] = $id;
            $isEdit = true;
            $formAction = 'update';
            renderLayout('modules/competitors/form.php', compact(
                'pageTitle', 'currentNav', 'user', 'config', 'competitor', 'errors', 'formAction', 'isEdit'
            ));
            exit;
        }

        $wasActive = (int) $existing['active'];
        $repository->update($id, $result['data']);

        Auth::logAudit(
            $user['id'],
            'competitor_update',
            'Wettbewerber aktualisiert: ID ' . $id . ', Name ' . $result['data']['name']
        );

        if ($wasActive !== (int) $result['data']['active']) {
            Auth::logAudit(
                $user['id'],
                'competitor_status_change',
                'Status geändert: ID ' . $id . ' → ' . ($result['data']['active'] ? 'aktiv' : 'inaktiv')
            );
        }

        flash('success', 'Wettbewerber wurde gespeichert.');
        redirect('competitors.php');
    }

    if ($postAction === 'set-status') {
        $id = (int) post('id', '0');
        $active = (int) post('active', '0') === 1 ? 1 : 0;
        $existing = $repository->findById($id);

        if ($existing === null) {
            flash('error', 'Wettbewerber nicht gefunden.');
            redirect('competitors.php');
        }

        $repository->setActive($id, $active);
        Auth::logAudit(
            $user['id'],
            'competitor_status_change',
            'Status geändert: ID ' . $id . ', Name ' . $existing['name'] . ' → ' . ($active ? 'aktiv' : 'inaktiv')
        );
        flash('success', $active === 1 ? 'Wettbewerber wurde aktiviert.' : 'Wettbewerber wurde deaktiviert.');
        redirect('competitors.php');
    }

    if ($postAction === 'delete') {
        $id = (int) post('id', '0');
        $existing = $repository->findById($id);

        if ($existing === null) {
            flash('error', 'Wettbewerber nicht gefunden.');
            redirect('competitors.php');
        }

        if ($repository->hasReferences($id)) {
            Auth::logAudit(
                $user['id'],
                'competitor_delete_blocked',
                'Löschung blockiert: ID ' . $id . ', Name ' . $existing['name'] . ' (Referenzen vorhanden)'
            );
            flash('error', 'Wettbewerber wird bereits verwendet und kann nicht gelöscht werden. Bitte deaktivieren.');
            redirect('competitors.php');
        }

        $repository->deleteById($id);
        Auth::logAudit(
            $user['id'],
            'competitor_deleted',
            'Wettbewerber gelöscht: ID ' . $id . ', Name ' . $existing['name']
        );
        flash('success', 'Wettbewerber wurde gelöscht.');
        redirect('competitors.php');
    }

    if ($postAction === 'cleanup-competitors') {
        try {
            $pdo->beginTransaction();
            $stats = $repository->cleanupTestDataAndDuplicates();
            $pdo->commit();

            Auth::logAudit(
                $user['id'],
                'competitor_cleanup',
                'Bereinigung: Phase4 gelöscht=' . $stats['deleted_phase4']
                . ', Phase4 deaktiviert=' . ($stats['deactivated_phase4'] ?? 0)
                . ', DocMorris zusammengeführt=' . $stats['merged_docmorris']
                . ', behaltene DocMorris-ID=' . ($stats['kept_docmorris_id'] ?? 'NULL')
            );

            flash(
                'success',
                'Bereinigung abgeschlossen. Gelöschte Phase4-Einträge: '
                . $stats['deleted_phase4']
                . ', deaktivierte Phase4-Einträge: '
                . ($stats['deactivated_phase4'] ?? 0)
                . ', zusammengeführte DocMorris-Duplikate: '
                . $stats['merged_docmorris']
                . '.'
            );
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            logError('Wettbewerber-Bereinigung fehlgeschlagen: ' . $e->getMessage());
            flash('error', 'Bereinigung fehlgeschlagen: ' . $e->getMessage());
        }
        redirect('competitors.php');
    }

    flash('error', 'Unbekannte Aktion.');
    redirect('competitors.php');
}

match ($action) {
    'create' => (function () use ($config, $currentNav, $user): void {
        $pageTitle = 'Wettbewerber anlegen';
        $competitor = null;
        $errors = [];
        $formAction = 'store';
        $isEdit = false;
        renderLayout('modules/competitors/form.php', compact(
            'pageTitle', 'currentNav', 'user', 'config', 'competitor', 'errors', 'formAction', 'isEdit'
        ));
    })(),

    'edit' => (function () use ($repository, $config, $currentNav, $user): void {
        $id = (int) (query('id', '0') ?? '0');
        $competitor = $repository->findById($id);

        if ($competitor === null) {
            flash('error', 'Wettbewerber nicht gefunden.');
            redirect('competitors.php');
        }

        $pageTitle = 'Wettbewerber bearbeiten';
        $errors = [];
        $formAction = 'update';
        $isEdit = true;
        renderLayout('modules/competitors/form.php', compact(
            'pageTitle', 'currentNav', 'user', 'config', 'competitor', 'errors', 'formAction', 'isEdit'
        ));
    })(),

    default => (function () use ($repository, $config, $currentNav, $user): void {
        $competitors = $repository->findAll();
        $duplicates = $repository->findDuplicates();
        $pageTitle = 'Wettbewerber';

        renderLayout('modules/competitors/list.php', compact(
            'pageTitle', 'currentNav', 'user', 'config', 'competitors', 'duplicates'
        ));
    })(),
};
