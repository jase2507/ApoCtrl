<?php

declare(strict_types=1);

/**
 * Phase 1.1 – automatische Tests (CLI)
 */

$root = dirname(__DIR__);
$passed = 0;
$failed = 0;

function assertTrue(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "[OK] {$label}\n";
    } else {
        $failed++;
        echo "[FAIL] {$label}\n";
    }
}

// ── 1. requireAuth: nicht eingeloggt → keine Flash ──
$_SESSION = [];
require $root . '/core/setup.php';
$config = require $root . '/config/config.php';
Setup::verifyEnvironment($config);
require_once $root . '/core/database.php';
require_once $root . '/core/csrf.php';
require_once $root . '/core/helpers.php';
require_once $root . '/core/login_throttle.php';
require_once $root . '/core/auth.php';
Auth::setConfig($config);
Auth::initSession($config);

try {
    $pdo = Database::connect($config);
    Database::initializeSchema($pdo);
} catch (RuntimeException $e) {
    echo "DB setup failed: {$e->getMessage()}\n";
    exit(1);
}

clearFlashes();
$timedOut = Auth::isSessionTimedOut($config['session']['timeout']);
assertTrue(!Auth::isLoggedIn(), 'Nicht eingeloggt');
assertTrue(!$timedOut, 'Kein Timeout wenn nicht eingeloggt');

flash('error', 'Sollte nicht erscheinen');
clearFlashes();
assertTrue(empty($_SESSION['_flash'] ?? []), 'clearFlashes entfernt Meldungen');

// ── 2. Login + keine hängende Flash ──
Auth::login('admin', 'admin123');
assertTrue(Auth::isLoggedIn(), 'Login erfolgreich');
assertTrue(empty($_SESSION['_flash'] ?? []), 'Keine Flash nach Login');

// ── 3. Session-Timeout simulieren ──
$_SESSION['auth_last_activity'] = time() - $config['session']['timeout'] - 100;
assertTrue(Auth::isSessionTimedOut($config['session']['timeout']), 'Session als abgelaufen erkannt');

// ── 4. Rate-Limit ──
LoginThrottle::clear();
for ($i = 0; $i < 5; $i++) {
    LoginThrottle::recordFailure();
}
assertTrue(LoginThrottle::isLocked(), 'Rate-Limit nach 5 Fehlversuchen');
LoginThrottle::clear();
assertTrue(!LoginThrottle::isLocked(), 'Rate-Limit nach clear aufgehoben');

// ── 5. Default-Admin-Erkennung ──
$hasDefault = Auth::hasDefaultAdminCredentials();
assertTrue($hasDefault, 'Erkennt admin123 als Standard-Passwort');

// ── 6. PHP / Extensions ──
assertTrue(PHP_VERSION_ID >= 80000, 'PHP >= 8.0');
assertTrue(extension_loaded('pdo_sqlite'), 'pdo_sqlite geladen');

echo "\n--- Ergebnis: {$passed} bestanden, {$failed} fehlgeschlagen ---\n";
exit($failed > 0 ? 1 : 0);
