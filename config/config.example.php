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

    'shop' => [
        'allowed_host' => 'shop.apotheker-seidel.de',
        'base_url' => 'https://shop.apotheker-seidel.de/',
        'feed_url' => 'https://shop.apotheker-seidel.de/eStLeonard-Oy8chie2Ie/medizinfuchs/eStLeonard_medizinfuchs.csv',
        'feed_last_update_url' => 'https://shop.apotheker-seidel.de/eStLeonard-Oy8chie2Ie/medizinfuchs/last_update.txt',
        'deeplink_template' => 'https://shop.apotheker-seidel.de/product?artnr={PZN}',
        'html_search_fallback' => false,
        'search_url' => 'https://shop.apotheker-seidel.de/renderProductSummary?pzn={PZN}',
        'debug_autofill' => false,
        'fetch_timeout' => 15,
        'own_competitor_name' => 'Eigener Shop',
    ],

    'collector' => [
        // true = lokale HTML-Fixtures unter docs/examples/medizinfuchs_collector_{PZN}.html
        // false = HTTP-Abruf (URL-Template anpassen; Live-HTML kann vom Mock-Parser abweichen)
        'mock_mode' => false,
        'request_delay_ms' => 1000,
        'cache_ttl_minutes' => 15,
        'timeout' => 15,
        'fetch_timeout' => 15,
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ApoCtrl Collector',
        // Fallback-Produkt-URL (wenn kein UrlResolver aktiv, z. B. Mock)
        'medizinfuchs_url_template' => 'https://www.medizinfuchs.de/preisvergleich/produkt-pzn-{PZN}.html',
        // Phase 7.3: PZN-Suche → Produktseite auflösen
        'medizinfuchs_search_url_template' => 'https://www.medizinfuchs.de/?params[search]={PZN}&params[search_cat]=1',
        'fetch_ajax_offers' => true,
        'allow_insecure_ssl' => false,
        'debug' => false,
    ],
];
