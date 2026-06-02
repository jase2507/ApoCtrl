<?php

declare(strict_types=1);

/**
 * ApoCtrl – Umgebungs- und Setup-Prüfungen (Phase 1.1)
 */

class Setup
{
    public static function ensureConfigExists(string $configPath): void
    {
        if (file_exists($configPath)) {
            return;
        }

        self::fail(
            'Konfigurationsdatei nicht gefunden.',
            'Bitte kopieren Sie config/config.example.php nach config/config.php und passen Sie die Werte an.'
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function verifyEnvironment(array $config): void
    {
        if (PHP_VERSION_ID < 80000) {
            self::fail(
                'PHP 8.0 oder höher ist erforderlich.',
                'Aktuelle Version: ' . PHP_VERSION
            );
        }

        if (!extension_loaded('pdo')) {
            self::fail('PHP-Erweiterung „pdo“ ist nicht installiert.', 'Bitte aktivieren Sie PDO auf Ihrem Server.');
        }

        if (!extension_loaded('pdo_sqlite')) {
            self::fail(
                'PHP-Erweiterung „pdo_sqlite“ ist nicht installiert.',
                'SQLite (PDO) wird für ApoCtrl benötigt. Bitte auf Ihrem Hoster aktivieren.'
            );
        }

        $paths = [
            'storage/database' => dirname(__DIR__) . '/storage/database',
            'storage/logs' => dirname(__DIR__) . '/storage/logs',
        ];

        foreach ($paths as $label => $path) {
            self::ensureWritableDirectory($path, $label);
        }

        if (!empty($config['database']['path'])) {
            $dbDir = dirname((string) $config['database']['path']);
            if ($dbDir !== '' && !is_dir($dbDir)) {
                self::ensureWritableDirectory(dirname(__DIR__) . '/storage/database', 'storage/database');
            }
        }
    }

    private static function ensureWritableDirectory(string $path, string $label): void
    {
        if (!is_dir($path)) {
            if (!@mkdir($path, 0755, true) && !is_dir($path)) {
                self::fail(
                    "Verzeichnis „{$label}“ konnte nicht erstellt werden.",
                    "Pfad: {$path}\nBitte legen Sie das Verzeichnis manuell an und setzen Sie Schreibrechte (z. B. 755 oder 775)."
                );
            }
        }

        if (!is_writable($path)) {
            self::fail(
                "Verzeichnis „{$label}“ ist nicht beschreibbar.",
                "Pfad: {$path}\nBitte Schreibrechte für den Webserver setzen (IONOS/Strato: oft 755 oder 775)."
            );
        }
    }

    public static function abort(string $title, string $details): never
    {
        self::fail($title, $details);
    }

    private static function fail(string $title, string $details): never
    {
        http_response_code(503);

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "ApoCtrl Setup-Fehler: {$title}\n{$details}\n");
            exit(1);
        }

        $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $detailsEsc = htmlspecialchars($details, ENT_QUOTES, 'UTF-8');

        echo <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ApoCtrl – Setup-Fehler</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 560px; margin: 3rem auto; padding: 0 1rem; color: #1e293b; }
        h1 { font-size: 1.25rem; color: #dc2626; }
        pre { background: #f1f5f9; padding: 1rem; border-radius: 8px; white-space: pre-wrap; font-size: 0.875rem; }
    </style>
</head>
<body>
    <h1>{$titleEsc}</h1>
    <pre>{$detailsEsc}</pre>
</body>
</html>
HTML;
        exit(1);
    }
}
