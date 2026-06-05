<?php

declare(strict_types=1);

/** @var array{test_products:int,price_snapshots:int,own_price_snapshots:int} $stats */
/** @var list<array<string, mixed>> $testProducts */
?>
<div class="page-header">
    <h1>Wartung</h1>
    <p class="page-subtitle">Testdaten prüfen und kontrolliert bereinigen</p>
</div>

<section class="panel">
    <div class="panel-header">
        <h2>Testdaten-Übersicht</h2>
    </div>
    <div class="panel-body">
        <p class="text-muted">
            Es werden nur Produkte mit <code>is_test = 1</code> sowie deren Snapshots entfernt.
            Echte Produkte, Wettbewerber, Benutzer, Audit-Logs und Collector-Runs bleiben unverändert.
        </p>

        <div class="kpi-grid">
            <div class="kpi-card">
                <span class="kpi-label">Testprodukte</span>
                <span class="kpi-value"><?= (int) $stats['test_products'] ?></span>
            </div>
            <div class="kpi-card">
                <span class="kpi-label">Preis-Snapshots</span>
                <span class="kpi-value"><?= (int) $stats['price_snapshots'] ?></span>
            </div>
            <div class="kpi-card">
                <span class="kpi-label">Own-Shop-Snapshots</span>
                <span class="kpi-value"><?= (int) $stats['own_price_snapshots'] ?></span>
            </div>
        </div>
    </div>
</section>

<section class="panel">
    <div class="panel-header">
        <h2>Testprodukte</h2>
    </div>
    <div class="panel-body">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>PZN</th>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Erstellt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($testProducts === []): ?>
                        <tr>
                            <td colspan="5" class="text-muted">Keine Testprodukte vorhanden.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($testProducts as $product): ?>
                            <tr class="row-inactive">
                                <td><?= (int) ($product['id'] ?? 0) ?></td>
                                <td><?= e((string) ($product['pzn'] ?? '—')) ?></td>
                                <td><?= e((string) ($product['name'] ?? '—')) ?></td>
                                <td>
                                    <?php if ((int) ($product['active'] ?? 0) === 1): ?>
                                        <span class="badge badge-active">Aktiv</span>
                                    <?php else: ?>
                                        <span class="badge badge-inactive">Inaktiv</span>
                                    <?php endif; ?>
                                    <span class="badge badge-test">Test</span>
                                </td>
                                <td><?= e((string) ($product['created_at'] ?? '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <form method="post" action="maintenance.php" class="form-actions" style="margin-top:1rem;">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="cleanup-testdata">
            <button
                type="submit"
                class="btn btn-secondary"
                <?= (int) $stats['test_products'] === 0 ? 'disabled' : '' ?>
                onclick="return confirm('Alle Testprodukte inklusive Snapshots wirklich löschen?');"
            >
                Testdaten bereinigen
            </button>
        </form>
    </div>
</section>
