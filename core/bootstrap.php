<?php

declare(strict_types=1);

/**
 * ApoCtrl – Bootstrap
 * Zentraler Einstiegspunkt für alle Anwendungsseiten.
 */

$configPath = dirname(__DIR__) . '/config/config.php';

require_once __DIR__ . '/setup.php';

Setup::ensureConfigExists($configPath);

$config = require $configPath;

Setup::verifyEnvironment($config);

if ($config['debug']) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

date_default_timezone_set($config['timezone']);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/login_throttle.php';
require_once __DIR__ . '/auth.php';

Auth::setConfig($config);
Auth::initSession($config);

try {
    $pdo = Database::connect($config);
    Database::initializeSchema($pdo);
    Database::seedDefaultAdmin($pdo, $config);
} catch (RuntimeException $e) {
    Setup::abort('Datenbankfehler', $e->getMessage());
}

Csrf::init($config);
