<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/modules/snapshots/SnapshotRepository.php';

Auth::requireAuth($config['session']['timeout']);

$currentNav = 'snapshots';
$pageTitle = 'Snapshots';
$user = Auth::getUser();

$repository = new SnapshotRepository(Database::getConnection());
$showTest = query('show_test', '') === '1';
$page = max(1, (int) (query('page', '1') ?? '1'));
$pagination = $repository->findPaginated($page, 50, $showTest);
$snapshots = $pagination['rows'];

renderLayout('modules/snapshots/list.php', compact(
    'pageTitle',
    'currentNav',
    'user',
    'config',
    'snapshots',
    'pagination',
    'showTest',
));
