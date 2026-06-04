<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $products */
/** @var string $search */
/** @var string $activeFilter */
/** @var bool $showTest */
?>
<div class="page-header page-header-row">
    <div>
        <h1>Produkte</h1>
        <p class="page-subtitle">
            <?= count($products) ?> Produkt(e) gefunden
            <?php if (!$showTest): ?>
                <span class="text-muted">(ohne Testdaten)</span>
            <?php endif; ?>
        </p>
    </div>
    <a href="products.php?action=create" class="btn btn-primary">Neues Produkt</a>
</div>

<form method="get" action="products.php" class="toolbar">
    <input type="hidden" name="action" value="list">
    <div class="toolbar-group">
        <label for="q">Suche</label>
        <input
            type="search"
            id="q"
            name="q"
            value="<?= e($search) ?>"
            placeholder="PZN, Name oder Hersteller…"
        >
    </div>
    <div class="toolbar-group">
        <label for="filter">Status</label>
        <select id="filter" name="filter">
            <option value="all" <?= $activeFilter === 'all' ? 'selected' : '' ?>>Alle</option>
            <option value="active" <?= $activeFilter === 'active' ? 'selected' : '' ?>>Aktiv</option>
            <option value="inactive" <?= $activeFilter === 'inactive' ? 'selected' : '' ?>>Inaktiv</option>
        </select>
    </div>
    <div class="toolbar-group form-group-check">
        <label class="checkbox-label">
            <input type="checkbox" name="show_test" value="1" <?= $showTest ? 'checked' : '' ?>>
            Testdaten anzeigen
        </label>
    </div>
    <button type="submit" class="btn btn-secondary">Filtern</button>
    <?php if ($search !== '' || $activeFilter !== 'all' || $showTest): ?>
        <a href="products.php" class="btn btn-secondary">Zurücksetzen</a>
    <?php endif; ?>
</form>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>PZN</th>
                    <th>Name</th>
                    <th>Hersteller</th>
                    <th>VK</th>
                    <th>Min.</th>
                    <th>Ziel-Rang</th>
                    <th>Status</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($products === []): ?>
                    <tr>
                        <td colspan="8" class="text-muted">Keine Produkte gefunden.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($products as $product): ?>
                        <tr class="<?= (int) $product['active'] === 0 ? 'row-inactive' : '' ?>">
                            <td>
                                <code><?= e((string) ($product['pzn'] ?? '')) ?></code>
                                <?php if ((int) ($product['is_test'] ?? 0) === 1): ?>
                                    <span class="badge badge-test">Test</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) ($product['name'] ?? '')) ?></td>
                            <td><?= e((string) ($product['manufacturer'] ?? '—')) ?></td>
                            <td><?= e(formatMoney($product['sale_price'] !== null ? (float) $product['sale_price'] : null)) ?></td>
                            <td><?= e(formatMoney($product['min_price'] !== null ? (float) $product['min_price'] : null)) ?></td>
                            <td><?= e($product['target_rank'] !== null ? (string) $product['target_rank'] : '—') ?></td>
                            <td>
                                <?php if ((int) $product['active'] === 1): ?>
                                    <span class="badge badge-active">Aktiv</span>
                                <?php else: ?>
                                    <span class="badge badge-inactive">Inaktiv</span>
                                <?php endif; ?>
                            </td>
                            <td class="actions-cell">
                                <a href="products.php?action=edit&amp;id=<?= (int) $product['id'] ?>" class="btn btn-secondary btn-sm">Bearbeiten</a>
                                <?php if ((int) $product['active'] === 1): ?>
                                    <form method="post" action="products.php" class="inline-form">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="deactivate">
                                        <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm" onclick="return confirm('Produkt wirklich deaktivieren?');">Deaktivieren</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
