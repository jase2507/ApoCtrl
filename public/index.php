<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';

Auth::requireAuth($config['session']['timeout']);

$pageTitle = 'Dashboard';
$currentNav = 'dashboard';
$user = Auth::getUser();

renderLayout('modules/dashboard/index.php', compact('pageTitle', 'currentNav', 'user', 'config'));
