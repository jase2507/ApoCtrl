<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $suggestions */
/** @var bool $showTest */
?>
<div class="page-header page-header-row">
    <div>
        <h1>Preisvorschläge</h1>
        <p class="page-subtitle">
            Automatische Preisempfehlung auf Basis des letzten Ranking-Stands
            <?php if (!$showTest): ?>
                <span class="text-muted">(ohne Testdaten)</span>
            <?php endif; ?>
        </p>
    </div>
</div>

<form method="get" action="pricing.php" class="toolbar">
    <div class="toolbar-group form-group-check">
        <label class="checkbox-label">
            <input type="checkbox" name="show_test" value="1" <?= $showTest ? 'checked' : '' ?>>
            Testdaten anzeigen
        </label>
    </div>
    <button type="submit" class="btn btn-secondary">Anwenden</button>
    <?php if ($showTest): ?>
        <a href="pricing.php" class="btn btn-secondary">Testdaten ausblenden</a>
    <?php endif; ?>
</form>

<div class="panel">
    <div class="panel-body">
        <?php if ($suggestions === []): ?>
            <p class="text-muted">Keine aktiven Produkte mit Ziel-Ranking gefunden.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>PZN</th>
                            <th>Produkt</th>
                            <th>Aktuell</th>
                            <th>Ziel-Rang</th>
                            <th>Ist-Rang</th>
                            <th>Vorschlag</th>
                            <th>Grund</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($suggestions as $row): ?>
                            <tr>
                                <td>
                                    <code><?= e((string) ($row['pzn'] ?? '—')) ?></code>
                                    <?php if ((int) ($row['is_test'] ?? 0) === 1): ?>
                                        <span class="badge badge-test">Test</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="products.php?action=edit&amp;id=<?= (int) ($row['product_id'] ?? $row['id'] ?? 0) ?>">
                                        <?= e((string) ($row['product_name'] ?? $row['name'] ?? '—')) ?>
                                    </a>
                                </td>
                                <td><?= e(formatMoney(isset($row['current_price']) ? (float) $row['current_price'] : null)) ?></td>
                                <td><?= $row['target_rank'] !== null ? (int) $row['target_rank'] : '—' ?></td>
                                <td><?= $row['current_rank'] !== null ? (int) $row['current_rank'] : '—' ?></td>
                                <td><strong><?= e(formatMoney(isset($row['suggested_price']) ? (float) $row['suggested_price'] : null)) ?></strong></td>
                                <td><?= e((string) ($row['reason'] ?? '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
