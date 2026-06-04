<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $snapshots */
/** @var array{rows: list<array<string, mixed>>, total: int, page: int, perPage: int, totalPages: int} $pagination */
/** @var bool $showTest */
$snapshotPageQuery = static function (int $page) use ($showTest): string {
    $params = ['page' => (string) $page];
    if ($showTest) {
        $params['show_test'] = '1';
    }

    return 'snapshots.php?' . http_build_query($params);
};
?>
<div class="page-header page-header-row">
    <div>
        <h1>Snapshots</h1>
        <p class="page-subtitle">
            Historische Wettbewerberpreise – <?= (int) $pagination['total'] ?> Datensätze gesamt
            <?php if (!$showTest): ?>
                <span class="text-muted">(ohne Testdaten)</span>
            <?php endif; ?>
        </p>
    </div>
</div>

<form method="get" action="snapshots.php" class="toolbar">
    <div class="toolbar-group form-group-check">
        <label class="checkbox-label">
            <input type="checkbox" name="show_test" value="1" <?= $showTest ? 'checked' : '' ?>>
            Testdaten anzeigen
        </label>
    </div>
    <button type="submit" class="btn btn-secondary">Anwenden</button>
    <?php if ($showTest): ?>
        <a href="snapshots.php" class="btn btn-secondary">Testdaten ausblenden</a>
    <?php endif; ?>
</form>

<div class="panel">
    <div class="panel-body">
        <?php if ($snapshots === []): ?>
            <p class="text-muted">Noch keine Preis-Snapshots vorhanden. Snapshots entstehen beim CSV-Import oder Shop-Sync.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Produkt</th>
                            <th>Wettbewerber</th>
                            <th>Preis</th>
                            <th>Versand</th>
                            <th>Endpreis</th>
                            <th>Ranking</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($snapshots as $row): ?>
                            <?php
                            $price = (float) ($row['price'] ?? 0);
                            $shipping = (float) ($row['shipping_cost'] ?? 0);
                            $endPrice = $price + $shipping;
                            $productLabel = trim((string) ($row['product_name'] ?? ''));
                            if ($productLabel === '') {
                                $productLabel = (string) ($row['product_pzn'] ?? '—');
                            }
                            ?>
                            <tr>
                                <td><?= e((string) ($row['captured_at'] ?? '')) ?></td>
                                <td>
                                    <a href="products.php?action=edit&amp;id=<?= (int) ($row['product_id'] ?? 0) ?>">
                                        <?= e($productLabel) ?>
                                    </a>
                                    <?php if (!empty($row['product_pzn'])): ?>
                                        <span class="text-muted">(<?= e((string) $row['product_pzn']) ?>)</span>
                                    <?php endif; ?>
                                    <?php if ((int) ($row['product_is_test'] ?? 0) === 1): ?>
                                        <span class="badge badge-test">Test</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string) ($row['competitor_name'] ?? '—')) ?></td>
                                <td><?= e(formatMoney($price)) ?></td>
                                <td><?= e(formatMoney($shipping)) ?></td>
                                <td><strong><?= e(formatMoney($endPrice)) ?></strong></td>
                                <td><?= $row['ranking'] !== null ? (int) $row['ranking'] : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($pagination['totalPages'] > 1): ?>
                <nav class="pagination" aria-label="Seitennavigation">
                    <?php if ($pagination['page'] > 1): ?>
                        <a class="btn btn-secondary btn-sm" href="<?= e($snapshotPageQuery($pagination['page'] - 1)) ?>">&larr; Zurück</a>
                    <?php endif; ?>
                    <span class="pagination-info">
                        Seite <?= (int) $pagination['page'] ?> von <?= (int) $pagination['totalPages'] ?>
                        (<?= (int) $pagination['perPage'] ?> pro Seite)
                    </span>
                    <?php if ($pagination['page'] < $pagination['totalPages']): ?>
                        <a class="btn btn-secondary btn-sm" href="<?= e($snapshotPageQuery($pagination['page'] + 1)) ?>">Weiter &rarr;</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
