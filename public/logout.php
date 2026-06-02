<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';

if (Auth::isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validateRequest()) {
        flash('error', 'Ungültiges Sicherheitstoken.');
        redirect('index.php');
    }

    Auth::logout();
}

redirect('login.php');
