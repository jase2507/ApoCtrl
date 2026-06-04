<?php

declare(strict_types=1);

/**
 * ApoCtrl – Hilfsfunktionen
 */

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][$type] = $message;
}

function getFlash(string $type): ?string
{
    if (!isset($_SESSION['_flash'][$type])) {
        return null;
    }

    $message = $_SESSION['_flash'][$type];
    unset($_SESSION['_flash'][$type]);

    return $message;
}

function getFlashes(): array
{
    $flashes = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);

    return is_array($flashes) ? $flashes : [];
}

function clearFlashes(): void
{
    unset($_SESSION['_flash']);
}

function renderFlashes(array $flashes): void
{
    if ($flashes === []) {
        return;
    }

    require dirname(__DIR__) . '/templates/partials/flashes.php';
}

function logError(string $message): void
{
    $logDir = dirname(__DIR__) . '/storage/logs';
    $logFile = $logDir . '/app-' . date('Y-m-d') . '.log';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;

    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

function render(string $template, array $data = []): void
{
    extract($data, EXTR_SKIP);
    require dirname(__DIR__) . '/templates/' . $template;
}

function renderLayout(string $contentTemplate, array $data = []): void
{
    $data['contentTemplate'] = $contentTemplate;
    render('layout.php', $data);
}

function isActiveNav(string $key, ?string $current): string
{
    return $key === $current ? 'active' : '';
}

function navItems(): array
{
    return [
        'dashboard' => ['label' => 'Dashboard', 'url' => 'index.php', 'phase' => 1],
        'products' => ['label' => 'Produkte', 'url' => 'products.php', 'phase' => 2],
        'competitors' => ['label' => 'Wettbewerber', 'url' => 'competitors.php', 'phase' => 2],
        'imports' => ['label' => 'Importe', 'url' => 'imports.php', 'phase' => 3],
        'rankings' => ['label' => 'Rankings', 'url' => 'rankings.php', 'phase' => 4],
        'snapshots' => ['label' => 'Snapshots', 'url' => 'snapshots.php', 'phase' => 5],
        'suggestions' => ['label' => 'Preisvorschläge', 'url' => '#', 'phase' => 2, 'disabled' => true],
        'alerts' => ['label' => 'Alerts', 'url' => '#', 'phase' => 2, 'disabled' => true],
        'reports' => ['label' => 'Reports', 'url' => '#', 'phase' => 2, 'disabled' => true],
        'users' => ['label' => 'Benutzer', 'url' => '#', 'phase' => 2, 'disabled' => true],
        'settings' => ['label' => 'Einstellungen', 'url' => '#', 'phase' => 2, 'disabled' => true],
    ];
}

function asset(string $path): string
{
    return 'assets/' . ltrim($path, '/');
}

function post(string $key, ?string $default = null): ?string
{
    $value = $_POST[$key] ?? $default;

    return is_string($value) ? trim($value) : $default;
}

function query(string $key, ?string $default = null): ?string
{
    $value = $_GET[$key] ?? $default;

    return is_string($value) ? trim($value) : $default;
}

function requirePostCsrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Methode nicht erlaubt.');
    }

    if (!Csrf::validateRequest()) {
        flash('error', 'Ungültiges Sicherheitstoken. Bitte versuchen Sie es erneut.');
        redirect(basename($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    }
}

function formatMoney(?float $value): string
{
    if ($value === null) {
        return '—';
    }

    return number_format($value, 2, ',', '.') . ' €';
}

