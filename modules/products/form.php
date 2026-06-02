<?php

declare(strict_types=1);

/** @var array<string, mixed>|null $product */
/** @var list<string> $errors */
/** @var string $formAction */
/** @var bool $isEdit */

$values = $product ?? [
    'pzn' => post('pzn', ''),
    'name' => post('name', ''),
    'manufacturer' => post('manufacturer', ''),
    'cost_price' => post('cost_price', ''),
    'sale_price' => post('sale_price', ''),
    'min_price' => post('min_price', ''),
    'target_rank' => post('target_rank', ''),
    'strategy' => post('strategy', ''),
    'category' => post('category', ''),
    'active' => post('active', '1') !== '' && post('active', '1') !== '0' ? 1 : 0,
];

if ($product !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $values['cost_price'] = $product['cost_price'] !== null ? (string) $product['cost_price'] : '';
    $values['sale_price'] = $product['sale_price'] !== null ? (string) $product['sale_price'] : '';
    $values['min_price'] = $product['min_price'] !== null ? (string) $product['min_price'] : '';
    $values['target_rank'] = $product['target_rank'] !== null ? (string) $product['target_rank'] : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['active'] = isset($_POST['active']) ? 1 : 0;
}
?>
<div class="page-header">
    <h1><?= $isEdit ? 'Produkt bearbeiten' : 'Produkt anlegen' ?></h1>
    <p class="page-subtitle">
        <a href="products.php">&larr; Zurück zur Liste</a>
    </p>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <ul class="error-list">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="products.php" class="entity-form">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="<?= e($formAction) ?>">
    <?php if ($isEdit && isset($product['id'])): ?>
        <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
    <?php endif; ?>

    <div class="form-grid">
        <div class="form-group">
            <label for="pzn">PZN <span class="required">*</span></label>
            <input type="text" id="pzn" name="pzn" value="<?= e((string) ($values['pzn'] ?? '')) ?>" required maxlength="20">
        </div>
        <div class="form-group">
            <label for="name">Produktname <span class="required">*</span></label>
            <input type="text" id="name" name="name" value="<?= e((string) ($values['name'] ?? '')) ?>" required maxlength="255">
        </div>
        <div class="form-group">
            <label for="manufacturer">Hersteller</label>
            <input type="text" id="manufacturer" name="manufacturer" value="<?= e((string) ($values['manufacturer'] ?? '')) ?>" maxlength="255">
        </div>
        <div class="form-group">
            <label for="category">Kategorie</label>
            <input type="text" id="category" name="category" value="<?= e((string) ($values['category'] ?? '')) ?>" maxlength="100">
        </div>
        <div class="form-group">
            <label for="cost_price">Einkaufspreis (€)</label>
            <input type="text" id="cost_price" name="cost_price" value="<?= e((string) ($values['cost_price'] ?? '')) ?>" inputmode="decimal" placeholder="0,00">
        </div>
        <div class="form-group">
            <label for="sale_price">Verkaufspreis (€)</label>
            <input type="text" id="sale_price" name="sale_price" value="<?= e((string) ($values['sale_price'] ?? '')) ?>" inputmode="decimal" placeholder="0,00">
        </div>
        <div class="form-group">
            <label for="min_price">Mindestpreis (€)</label>
            <input type="text" id="min_price" name="min_price" value="<?= e((string) ($values['min_price'] ?? '')) ?>" inputmode="decimal" placeholder="0,00">
        </div>
        <div class="form-group">
            <label for="target_rank">Ziel-Ranking</label>
            <input type="number" id="target_rank" name="target_rank" min="1" step="1" value="<?= e((string) ($values['target_rank'] ?? '')) ?>">
        </div>
        <div class="form-group form-group-full">
            <label for="strategy">Strategie</label>
            <input type="text" id="strategy" name="strategy" value="<?= e((string) ($values['strategy'] ?? '')) ?>" maxlength="255">
        </div>
        <div class="form-group form-group-check">
            <label class="checkbox-label">
                <input type="checkbox" name="active" value="1" <?= (int) ($values['active'] ?? 1) === 1 ? 'checked' : '' ?>>
                Produkt ist aktiv
            </label>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Speichern' : 'Anlegen' ?></button>
        <a href="products.php" class="btn btn-secondary">Abbrechen</a>
    </div>
</form>
