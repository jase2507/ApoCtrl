<?php

declare(strict_types=1);

/**
 * ApoCtrl – Konfigurationsvorlage
 *
 * Installation:
 * 1. Diese Datei nach config/config.php kopieren
 * 2. Werte anpassen (insbesondere default_admin und environment)
 * 3. config/config.php wird nicht ins Repository eingecheckt
 */

return [
    'app_name' => 'ApoCtrl',
    'app_version' => '1.1.0-phase1.1',
    'debug' => false,

    // local = Entwicklung/Erstinstallation | production = Live-Betrieb
    'environment' => 'production',

    'database' => [
        'path' => dirname(__DIR__) . '/storage/database/apoctrl.sqlite',
    ],

    'session' => [
        'name' => 'APOCTRL_SESSID',
        'timeout' => 3600,
        'cookie_lifetime' => 0,
        'cookie_path' => '/',
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ],

    'csrf' => [
        'token_name' => '_csrf_token',
        'token_length' => 32,
    ],

    /**
     * Standard-Admin nur für lokale Entwicklung oder Erstinstallation.
     * enabled: false in Produktion
     * password: leer lassen in Produktion – kein automatisches Seed
     */
    'default_admin' => [
        'enabled' => false,
        'username' => 'admin',
        'password' => '',
        'role' => 'Admin',
    ],

    'timezone' => 'Europe/Berlin',
];
