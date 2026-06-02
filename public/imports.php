<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/modules/imports/ImportValidator.php';
require_once dirname(__DIR__) . '/modules/imports/ImportRepository.php';
require_once dirname(__DIR__) . '/modules/imports/CsvImporter.php';

Auth::requireAuth($config['session']['timeout']);

$currentNav = 'imports';
$pageTitle = 'Importe';
$user = Auth::getUser();

$repository = new ImportRepository(Database::getConnection());
$validator = new ImportValidator();
$importer = new CsvImporter($repository, $validator);

$preview = $_SESSION['imports_preview'] ?? null;
$result = $_SESSION['imports_result'] ?? null;
unset($_SESSION['imports_result']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
    $action = post('action', '');

    if ($action === 'clear-preview') {
        unset($_SESSION['imports_preview']);
        flash('success', 'Import-Vorschau wurde zurückgesetzt.');
        redirect('imports.php');
    }

    if ($action === 'upload') {
        try {
            if (!isset($_FILES['csv_file']) || !is_array($_FILES['csv_file'])) {
                throw new RuntimeException('Bitte eine CSV-Datei auswählen.');
            }

            $file = $_FILES['csv_file'];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Datei-Upload fehlgeschlagen.');
            }

            $originalName = sanitizeCsvFilename((string) ($file['name'] ?? 'import.csv'));
            $tmpPath = (string) ($file['tmp_name'] ?? '');
            $size = (int) ($file['size'] ?? 0);

            if ($size <= 0) {
                throw new RuntimeException('Die CSV-Datei ist leer.');
            }

            if ($size > 10 * 1024 * 1024) {
                throw new RuntimeException('Die Datei ist größer als 10 MB.');
            }

            if (!isAllowedCsvUpload($originalName, $tmpPath)) {
                throw new RuntimeException('Nur CSV-Dateien (UTF-8) sind erlaubt.');
            }

            $storageDir = dirname(__DIR__) . '/storage/imports';
            if (!is_dir($storageDir) && !mkdir($storageDir, 0755, true) && !is_dir($storageDir)) {
                throw new RuntimeException('Upload-Verzeichnis ist nicht verfügbar.');
            }

            $storedPath = $storageDir . '/' . uniqid('import_', true) . '_' . $originalName;
            if (!move_uploaded_file($tmpPath, $storedPath)) {
                throw new RuntimeException('Datei konnte nicht gespeichert werden.');
            }

            $previewData = $importer->buildPreview($storedPath, $originalName);
            @unlink($storedPath);

            $_SESSION['imports_preview'] = $previewData;
            flash('success', 'Vorschau erfolgreich erstellt. Sie können jetzt importieren.');
            redirect('imports.php');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            redirect('imports.php');
        }
    }

    if ($action === 'import') {
        /** @var array|null $sessionPreview */
        $sessionPreview = $_SESSION['imports_preview'] ?? null;
        if (!is_array($sessionPreview)) {
            flash('error', 'Keine gültige Vorschau vorhanden. Bitte zuerst eine Datei hochladen.');
            redirect('imports.php');
        }

        try {
            $importResult = $importer->importPreview($sessionPreview, (int) $user['id']);
            $_SESSION['imports_result'] = $importResult;
            unset($_SESSION['imports_preview']);
            flash('success', 'Import wurde ausgeführt.');
            redirect('imports.php');
        } catch (Throwable $e) {
            flash('error', 'Import fehlgeschlagen: ' . $e->getMessage());
            redirect('imports.php');
        }
    }
}

$preview = $_SESSION['imports_preview'] ?? null;

renderLayout('modules/imports/index.php', compact(
    'pageTitle',
    'currentNav',
    'user',
    'config',
    'preview',
    'result'
));

function sanitizeCsvFilename(string $filename): string
{
    $base = basename($filename);
    $base = preg_replace('/[^a-zA-Z0-9._-]/', '_', $base) ?? 'import.csv';
    if (!str_ends_with(strtolower($base), '.csv')) {
        $base .= '.csv';
    }

    return $base;
}

function isAllowedCsvUpload(string $filename, string $tmpPath): bool
{
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($extension !== 'csv') {
        return false;
    }

    if (!is_uploaded_file($tmpPath)) {
        return false;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        return true;
    }
    $mime = finfo_file($finfo, $tmpPath) ?: '';
    finfo_close($finfo);

    $allowed = [
        'text/plain',
        'text/csv',
        'application/csv',
        'application/vnd.ms-excel',
    ];

    return in_array(strtolower($mime), $allowed, true);
}
