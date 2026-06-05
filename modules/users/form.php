<?php

declare(strict_types=1);

/** @var array<string, mixed>|null $account */
/** @var list<string> $errors */
/** @var string $formAction */
/** @var bool $isEdit */

$values = $account ?? [
    'username' => post('username', ''),
    'role' => post('role', 'User'),
    'active' => isset($_POST['active']) ? 1 : 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['active'] = isset($_POST['active']) ? 1 : 0;
}

$roleValue = strtolower((string) ($values['role'] ?? 'user'));
if (!in_array($roleValue, ['admin', 'user'], true)) {
    $roleValue = strcasecmp((string) ($values['role'] ?? ''), 'Admin') === 0 ? 'admin' : 'user';
}
?>
<div class="page-header">
    <h1><?= $isEdit ? 'Benutzer bearbeiten' : 'Benutzer anlegen' ?></h1>
    <p class="page-subtitle">
        <a href="users.php">&larr; Zurück zur Liste</a>
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

<form method="post" action="users.php" class="entity-form" autocomplete="off">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="<?= e($formAction) ?>">
    <?php if ($isEdit && isset($account['id'])): ?>
        <input type="hidden" name="id" value="<?= (int) $account['id'] ?>">
    <?php endif; ?>

    <div class="form-grid">
        <div class="form-group">
            <label for="username">Benutzername <span class="required">*</span></label>
            <input type="text" id="username" name="username" value="<?= e((string) ($values['username'] ?? '')) ?>" required maxlength="100" autocomplete="off">
        </div>
        <div class="form-group">
            <label for="role">Rolle <span class="required">*</span></label>
            <select id="role" name="role" required>
                <option value="user" <?= $roleValue === 'user' ? 'selected' : '' ?>>User</option>
                <option value="admin" <?= $roleValue === 'admin' ? 'selected' : '' ?>>Admin</option>
            </select>
        </div>
    </div>

    <div class="form-grid">
        <div class="form-group">
            <label for="password">
                <?= $isEdit ? 'Neues Passwort (optional)' : 'Passwort' ?>
                <?php if (!$isEdit): ?><span class="required">*</span><?php endif; ?>
            </label>
            <input type="password" id="password" name="password" <?= $isEdit ? '' : 'required' ?> minlength="6" autocomplete="new-password">
        </div>
        <div class="form-group">
            <label for="password_confirm">Passwort bestätigen</label>
            <input type="password" id="password_confirm" name="password_confirm" minlength="6" autocomplete="new-password">
        </div>
    </div>

    <div class="form-group form-group-check">
        <label class="checkbox-label">
            <input type="checkbox" name="active" value="1" <?= (int) ($values['active'] ?? 1) === 1 ? 'checked' : '' ?>>
            Konto aktiv
        </label>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Speichern' : 'Anlegen' ?></button>
        <a href="users.php" class="btn btn-secondary">Abbrechen</a>
    </div>
</form>
