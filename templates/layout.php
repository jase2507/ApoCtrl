<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'ApoCtrl') ?> – <?= e($config['app_name']) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head>
<body class="admin-layout">
    <aside class="sidebar">
        <div class="sidebar-brand">
            <span class="brand-name"><?= e($config['app_name']) ?></span>
            <span class="brand-version">v<?= e($config['app_version']) ?></span>
        </div>

        <nav class="sidebar-nav">
            <ul>
                <?php foreach (navItems() as $key => $item): ?>
                    <li>
                        <?php if (!empty($item['disabled'])): ?>
                            <span class="nav-link nav-disabled" title="Wird in Phase <?= (int) $item['phase'] ?> implementiert">
                                <?= e($item['label']) ?>
                            </span>
                        <?php else: ?>
                            <a
                                href="<?= e($item['url']) ?>"
                                class="nav-link <?= isActiveNav($key, $currentNav ?? '') ?>"
                            >
                                <?= e($item['label']) ?>
                            </a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
    </aside>

    <div class="main-wrapper">
        <header class="topbar">
            <div class="topbar-left">
                <span class="topbar-title"><?= e($pageTitle ?? '') ?></span>
            </div>
            <div class="topbar-right">
                <?php $currentUser = Auth::getUser(); ?>
                <?php if ($currentUser !== null): ?>
                    <span class="user-info">
                        <?= e($currentUser['username']) ?>
                        <span class="user-role">(<?= e($currentUser['role']) ?>)</span>
                    </span>
                    <form method="post" action="logout.php" class="logout-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn-secondary btn-sm">Abmelden</button>
                    </form>
                <?php endif; ?>
            </div>
        </header>

        <main class="main-content">
            <?php
            $flashes = getFlashes();
            renderFlashes($flashes);

            if (Auth::hasDefaultAdminCredentials()):
            ?>
                <div class="alert alert-warning" role="alert">
                    <strong>Sicherheitswarnung:</strong>
                    Der Benutzer „admin“ verwendet noch das Standard-Passwort (z. B. admin123).
                    Bitte ändern Sie das Passwort umgehend.
                </div>
            <?php endif; ?>

            <?php require dirname(__DIR__) . '/' . $contentTemplate; ?>
        </main>

        <footer class="footer">
            <span>&copy; <?= date('Y') ?> <?= e($config['app_name']) ?></span>
        </footer>
    </div>
</body>
</html>
