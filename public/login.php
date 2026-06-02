<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';

Auth::requireGuest();

$error = null;
$flashes = getFlashes();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (LoginThrottle::isLocked()) {
        $minutes = (int) ceil(LoginThrottle::remainingSeconds() / 60);
        $error = 'Zu viele Fehlversuche. Bitte warten Sie ' . max(1, $minutes) . ' Minute(n).';
    } elseif (!Csrf::validateRequest()) {
        $error = 'Ungültiges Sicherheitstoken. Bitte versuchen Sie es erneut.';
    } else {
        $username = post('username', '');
        $password = post('password', '');

        if ($username === '' || $password === '') {
            $error = 'Bitte Benutzername und Passwort eingeben.';
        } elseif (Auth::login($username, $password)) {
            redirect('index.php');
        } else {
            if (LoginThrottle::isLocked()) {
                $minutes = (int) ceil(LoginThrottle::remainingSeconds() / 60);
                $error = 'Zu viele Fehlversuche. Bitte warten Sie ' . max(1, $minutes) . ' Minute(n).';
            } else {
                $error = 'Benutzername oder Passwort ist ungültig.';
            }
        }
    }
}

$pageTitle = 'Anmelden';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> – <?= e($config['app_name']) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1><?= e($config['app_name']) ?></h1>
                <p>Preis- und Marktanalyse für Apotheken</p>
            </div>

            <?php renderFlashes($flashes); ?>

            <?php if ($error !== null): ?>
                <div class="alert alert-error" role="alert">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="login.php" class="login-form">
                <?= Csrf::field() ?>

                <div class="form-group">
                    <label for="username">Benutzername</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        value="<?= e(post('username', '')) ?>"
                        autocomplete="username"
                        required
                        autofocus
                        <?= LoginThrottle::isLocked() ? 'disabled' : '' ?>
                    >
                </div>

                <div class="form-group">
                    <label for="password">Passwort</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        autocomplete="current-password"
                        required
                        <?= LoginThrottle::isLocked() ? 'disabled' : '' ?>
                    >
                </div>

                <button
                    type="submit"
                    class="btn btn-primary btn-block"
                    <?= LoginThrottle::isLocked() ? 'disabled' : '' ?>
                >
                    Anmelden
                </button>
            </form>

            <p class="login-footer">
                Version <?= e($config['app_version']) ?>
            </p>
        </div>
    </div>
</body>
</html>
