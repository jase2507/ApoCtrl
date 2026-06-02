<?php

declare(strict_types=1);

/** @var array<string, mixed>|null $competitor */
/** @var list<string> $errors */
/** @var string $formAction */
/** @var bool $isEdit */

$values = $competitor ?? [
    'name' => post('name', ''),
    'url' => post('url', ''),
    'priority' => post('priority', '0'),
    'notes' => post('notes', ''),
    'active' => isset($_POST['active']) ? 1 : 0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['active'] = isset($_POST['active']) ? 1 : 0;
}
?>
<div class="page-header">
    <h1><?= $isEdit ? 'Wettbewerber bearbeiten' : 'Wettbewerber anlegen' ?></h1>
    <p class="page-subtitle">
        <a href="competitors.php">&larr; Zurück zur Liste</a>
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

<form method="post" action="competitors.php" class="entity-form">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="<?= e($formAction) ?>">
    <?php if ($isEdit && isset($competitor['id'])): ?>
        <input type="hidden" name="id" value="<?= (int) $competitor['id'] ?>">
    <?php endif; ?>

    <div class="form-grid">
        <div class="form-group">
            <label for="name">Name <span class="required">*</span></label>
            <input type="text" id="name" name="name" value="<?= e((string) ($values['name'] ?? '')) ?>" required maxlength="255">
        </div>
        <div class="form-group">
            <label for="url">URL</label>
            <input type="url" id="url" name="url" value="<?= e((string) ($values['url'] ?? '')) ?>" placeholder="https://…" maxlength="500">
        </div>
        <div class="form-group">
            <label for="priority">Priorität</label>
            <input type="number" id="priority" name="priority" min="0" step="1" value="<?= e((string) ($values['priority'] ?? '0')) ?>">
        </div>
        <div class="form-group form-group-check">
            <label class="checkbox-label">
                <input type="checkbox" name="active" value="1" <?= (int) ($values['active'] ?? 1) === 1 ? 'checked' : '' ?>>
                Wettbewerber ist aktiv
            </label>
        </div>
        <div class="form-group form-group-full">
            <label for="notes">Notizen</label>
            <textarea id="notes" name="notes" rows="4" maxlength="2000"><?= e((string) ($values['notes'] ?? '')) ?></textarea>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Speichern' : 'Anlegen' ?></button>
        <a href="competitors.php" class="btn btn-secondary">Abbrechen</a>
    </div>
</form>
