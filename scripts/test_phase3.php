<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/modules/imports/ImportValidator.php';
require_once dirname(__DIR__) . '/modules/imports/ImportRepository.php';
require_once dirname(__DIR__) . '/modules/imports/CsvImporter.php';

$pdo = Database::getConnection();
$repo = new ImportRepository($pdo);
$validator = new ImportValidator();
$importer = new CsvImporter($repo, $validator);

$failures = 0;

function check(bool $condition, string $label, int &$failures): void
{
    if ($condition) {
        echo "[OK] {$label}\n";
        return;
    }

    $failures++;
    echo "[FAIL] {$label}\n";
}

$pdo->exec("INSERT INTO competitors (name, active, priority, created_at, updated_at)
            VALUES ('DocMorris', 1, 0, datetime('now'), datetime('now'))");

$csv = dirname(__DIR__) . '/storage/imports/test_phase3.csv';
$data = <<<CSV
pzn;competitor;price;shipping_cost;availability
11111111;DocMorris;10.5;2.0;lieferbar
;DocMorris;5.2;1.0;lieferbar
22222222;Unbekannt;7.2;0;begrenzt
CSV;
file_put_contents($csv, $data);

$preview = $importer->buildPreview($csv, 'test_phase3.csv');
check(($preview['totalRows'] ?? 0) === 3, 'CSV-Vorschau zählt Zeilen', $failures);
check(($preview['invalidRows'] ?? 0) === 1, 'Validator markiert fehlende PZN', $failures);

$result = $importer->importPreview($preview, 1);
check(($result['imported'] ?? 0) === 1, 'Nur gültige + bekannte Wettbewerber importiert', $failures);
check(($result['errors'] ?? 0) === 2, 'Fehlerhafte Zeilen werden gezählt', $failures);

$snapshots = (int) $pdo->query('SELECT COUNT(*) FROM price_snapshots')->fetchColumn();
check($snapshots >= 1, 'Snapshot wurde erzeugt', $failures);

$createdProduct = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE pzn = '11111111'")->fetchColumn();
check($createdProduct === 1, 'Fehlendes Produkt wird automatisch erstellt', $failures);

@unlink($csv);
exit($failures > 0 ? 1 : 0);
