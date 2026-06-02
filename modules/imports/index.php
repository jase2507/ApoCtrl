<?php

declare(strict_types=1);

/** @var array<string, mixed>|null $preview */
/** @var array<string, mixed>|null $result */
?>
<div class="page-header">
    <h1>CSV-Import</h1>
    <p class="page-subtitle">Upload, Vorschau, Validierung und Snapshot-Import in einem Ablauf.</p>
</div>

<div class="panel">
    <div class="panel-header">
        <h2>1) Datei hochladen</h2>
    </div>
    <div class="panel-body">
        <form method="post" action="imports.php" enctype="multipart/form-data" class="entity-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="upload">
            <div class="form-group">
                <label for="csv_file">CSV-Datei (max. 10 MB, UTF-8, Trennzeichen ; oder ,)</label>
                <input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Vorschau erstellen</button>
            </div>
        </form>
    </div>
</div>

<?php if (is_array($preview)): ?>
    <?php
    $headers = $preview['headers'] ?? [];
    $rows = $preview['rows'] ?? [];
    $knownColumns = ['pzn', 'competitor', 'price', 'shipping_cost', 'availability'];
    ?>
    <div class="panel">
        <div class="panel-header">
            <h2>2) Vorschau &amp; Mapping</h2>
        </div>
        <div class="panel-body">
            <p class="text-muted">
                Datei: <strong><?= e((string) ($preview['filename'] ?? '')) ?></strong> |
                Zeilen: <strong><?= (int) ($preview['totalRows'] ?? 0) ?></strong> |
                gültig: <strong><?= (int) ($preview['validRows'] ?? 0) ?></strong> |
                fehlerhaft: <strong><?= (int) ($preview['invalidRows'] ?? 0) ?></strong>
            </p>

            <table class="data-table">
                <thead>
                    <tr>
                        <th>CSV-Spalte</th>
                        <th>Zuordnung</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($headers as $header): ?>
                        <?php $normalized = strtolower(trim((string) $header)); ?>
                        <tr>
                            <td><code><?= e((string) $header) ?></code></td>
                            <td>
                                <?= in_array($normalized, $knownColumns, true) ? e($normalized) : 'Ignoriert' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h3 style="margin-top:1rem;">3) Validierungsergebnisse (erste 100 Zeilen)</h3>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Zeile</th>
                            <th>PZN</th>
                            <th>Wettbewerber</th>
                            <th>Preis</th>
                            <th>Versand</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows === []): ?>
                            <tr>
                                <td colspan="6" class="text-muted">Keine Datenzeilen gefunden.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach (array_slice($rows, 0, 100) as $row): ?>
                                <?php $errors = $row['errors'] ?? []; ?>
                                <tr class="<?= $errors !== [] ? 'row-inactive' : '' ?>">
                                    <td><?= (int) ($row['line'] ?? 0) ?></td>
                                    <td><?= e((string) ($row['data']['pzn'] ?? '')) ?></td>
                                    <td><?= e((string) ($row['data']['competitor'] ?? '')) ?></td>
                                    <td><?= e((string) ($row['data']['price'] ?? '')) ?></td>
                                    <td><?= e((string) ($row['data']['shipping_cost'] ?? '0')) ?></td>
                                    <td>
                                        <?php if ($errors === []): ?>
                                            <span class="badge badge-active">Gültig</span>
                                        <?php else: ?>
                                            <span class="badge badge-inactive"><?= e(implode('; ', $errors)) ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="form-actions">
                <form method="post" action="imports.php" class="inline-form">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="import">
                    <button type="submit" class="btn btn-primary">5) Import starten</button>
                </form>
                <form method="post" action="imports.php" class="inline-form">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="clear-preview">
                    <button type="submit" class="btn btn-secondary">Vorschau verwerfen</button>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (is_array($result)): ?>
    <div class="panel">
        <div class="panel-header">
            <h2>6) Import-Ergebnis</h2>
        </div>
        <div class="panel-body">
            <ul class="status-list">
                <li>Import-Log ID: <strong><?= (int) ($result['importLogId'] ?? 0) ?></strong></li>
                <li>Datei: <strong><?= e((string) ($result['filename'] ?? '')) ?></strong></li>
                <li>Datensätze gesamt: <strong><?= (int) ($result['total'] ?? 0) ?></strong></li>
                <li>Snapshots importiert: <strong><?= (int) ($result['imported'] ?? 0) ?></strong></li>
                <li>Fehler: <strong><?= (int) ($result['errors'] ?? 0) ?></strong></li>
                <li>Automatisch angelegte Produkte: <strong><?= (int) ($result['createdProducts'] ?? 0) ?></strong></li>
            </ul>

            <?php $errorRows = $result['errorRows'] ?? []; ?>
            <?php if ($errorRows !== []): ?>
                <h3 style="margin-top:1rem;">Übersprungene Zeilen</h3>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Zeile</th>
                                <th>Fehler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($errorRows as $item): ?>
                                <tr>
                                    <td><?= (int) ($item['line'] ?? 0) ?></td>
                                    <td><?= e((string) ($item['message'] ?? 'Unbekannter Fehler')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
