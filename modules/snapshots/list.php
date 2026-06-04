<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $snapshots */
/** @var array{rows: list<array<string, mixed>>, total: int, page: int, perPage: int, totalPages: int} $pagination */
?>
<div class="page-header page-header-row">
    <div>
        <h1>Snapshots</h1>
        <p class="page-subtitle">
            Historische Wettbewerberpreise – <?= (int) $pagination['total'] ?> Datensätze gesamt
        </p>
    </div>
</div>

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
                        <a class="btn btn-secondary btn-sm" href="snapshots.php?page=<?= $pagination['page'] - 1 ?>">&larr; Zurück</a>
                    <?php endif; ?>
                    <span class="pagination-info">
                        Seite <?= (int) $pagination['page'] ?> von <?= (int) $pagination['totalPages'] ?>
                        (<?= (int) $pagination['perPage'] ?> pro Seite)
                    </span>
                    <?php if ($pagination['page'] < $pagination['totalPages']): ?>
                        <a class="btn btn-secondary btn-sm" href="snapshots.php?page=<?= $pagination['page'] + 1 ?>">Weiter &rarr;</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
