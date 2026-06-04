<?php

declare(strict_types=1);

/** @var array<string,mixed>|null $runSummary */
/** @var list<array<string,mixed>> $latestRows */
/** @var string $filter */
?>
<div class="page-header page-header-row">
    <div>
        <h1>Rankings</h1>
        <p class="page-subtitle">Endpreis = Preis + Versand, je Produkt und Importgruppe (`captured_at`).</p>
    </div>
</div>

<div class="panel">
    <div class="panel-header">
        <h2>Rankinglauf starten</h2>
    </div>
    <div class="panel-body">
        <div class="form-actions" style="margin-top:0;padding-top:0;border-top:none;">
            <form method="post" action="rankings.php" class="inline-form">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="run-all">
                <button type="submit" class="btn btn-primary">Rankings berechnen</button>
            </form>
            <form method="post" action="rankings.php" class="inline-form" style="display:flex;gap:.5rem;align-items:center;">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="run-product">
                <input type="text" name="pzn" placeholder="PZN für Einzel-Lauf" class="toolbar-input">
                <button type="submit" class="btn btn-secondary">Ein Produkt berechnen</button>
            </form>
        </div>
    </div>
</div>

<?php if (is_array($runSummary)): ?>
    <div class="panel">
        <div class="panel-header">
            <h2>Ergebnisübersicht</h2>
        </div>
        <div class="panel-body">
            <ul class="status-list">
                <li>Gruppen verarbeitet: <strong><?= (int) ($runSummary['groups'] ?? 0) ?></strong></li>
                <li>Snapshots gesamt: <strong><?= (int) ($runSummary['rows'] ?? 0) ?></strong></li>
                <li>Gerankt: <strong><?= (int) ($runSummary['ranked'] ?? 0) ?></strong></li>
                <li>Ignoriert (nicht lieferbar): <strong><?= (int) ($runSummary['ignored'] ?? 0) ?></strong></li>
                <li>Fehler: <strong><?= (int) ($runSummary['errors'] ?? 0) ?></strong></li>
            </ul>
        </div>
    </div>
<?php endif; ?>

<form method="get" action="rankings.php" class="toolbar">
    <div class="toolbar-group">
        <label for="q">Filter nach Produkt/PZN</label>
        <input type="search" id="q" name="q" value="<?= e($filter) ?>" placeholder="PZN oder Produktname...">
    </div>
    <button type="submit" class="btn btn-secondary">Filtern</button>
    <?php if ($filter !== ''): ?>
        <a href="rankings.php" class="btn btn-secondary">Zurücksetzen</a>
    <?php endif; ?>
</form>

<div class="panel">
    <div class="panel-header">
        <h2>Letzte Rankings je Produkt</h2>
    </div>
    <div class="panel-body">
        <?php if ($latestRows === []): ?>
            <p class="text-muted">Keine Snapshots vorhanden. Bitte zuerst Importe durchführen.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>PZN</th>
                            <th>Produktname</th>
                            <th>Wettbewerber</th>
                            <th>Preis</th>
                            <th>Versand</th>
                            <th>Endpreis</th>
                            <th>Rang</th>
                            <th>Erfasst am</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($latestRows as $row): ?>
                            <?php
                            $price = (float) ($row['price'] ?? 0);
                            $shipping = (float) ($row['shipping_cost'] ?? 0);
                            $end = $price + $shipping;
                            $isOwnShop = ($row['competitor_type'] ?? '') === 'own';
                            ?>
                            <tr class="<?= $isOwnShop ? 'row-own-shop' : '' ?>">
                                <td><code><?= e((string) ($row['pzn'] ?? '')) ?></code></td>
                                <td><?= e((string) ($row['product_name'] ?? '')) ?></td>
                                <td>
                                    <?= e((string) ($row['competitor_name'] ?? '')) ?>
                                    <?php if ($isOwnShop): ?>
                                        <span class="badge badge-own-shop">Eigener Shop</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e(formatMoney($price)) ?></td>
                                <td><?= e(formatMoney($shipping)) ?></td>
                                <td><strong><?= e(formatMoney($end)) ?></strong></td>
                                <td>
                                    <?php if ($row['ranking'] === null): ?>
                                        <span class="badge badge-inactive">—</span>
                                    <?php else: ?>
                                        <span class="badge badge-active"><?= (int) $row['ranking'] ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string) ($row['captured_at'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
