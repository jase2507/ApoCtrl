<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $users */
/** @var int $currentUserId */
?>
<div class="page-header page-header-row">
    <div>
        <h1>Benutzer</h1>
        <p class="page-subtitle"><?= count($users) ?> Benutzerkonto(s)</p>
    </div>
    <a href="users.php?action=create" class="btn btn-primary">Neuer Benutzer</a>
</div>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Benutzername</th>
                    <th>Rolle</th>
                    <th>Status</th>
                    <th>Erstellt</th>
                    <th>Aktualisiert</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($users === []): ?>
                    <tr>
                        <td colspan="6" class="text-muted">Noch keine Benutzer angelegt.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $account): ?>
                        <tr class="<?= (int) ($account['active'] ?? 0) === 0 ? 'row-inactive' : '' ?>">
                            <td>
                                <strong><?= e((string) ($account['username'] ?? '')) ?></strong>
                                <?php if ((int) ($account['id'] ?? 0) === $currentUserId): ?>
                                    <span class="badge badge-test">Sie</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) ($account['role'] ?? '')) ?></td>
                            <td>
                                <?php if ((int) ($account['active'] ?? 0) === 1): ?>
                                    <span class="badge badge-active">Aktiv</span>
                                <?php else: ?>
                                    <span class="badge badge-inactive">Inaktiv</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) ($account['created_at'] ?? '—')) ?></td>
                            <td><?= e((string) ($account['updated_at'] ?? '—')) ?></td>
                            <td class="actions-cell">
                                <a href="users.php?action=edit&amp;id=<?= (int) $account['id'] ?>" class="btn btn-secondary btn-sm">Bearbeiten</a>
                                <form method="post" action="users.php" class="inline-form">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="toggle-active">
                                    <input type="hidden" name="id" value="<?= (int) $account['id'] ?>">
                                    <?php if ((int) ($account['active'] ?? 0) === 1): ?>
                                        <button
                                            type="submit"
                                            class="btn btn-secondary btn-sm"
                                            onclick="return confirm('Benutzer <?= e((string) ($account['username'] ?? '')) ?> wirklich deaktivieren?');"
                                        >Deaktivieren</button>
                                    <?php else: ?>
                                        <button type="submit" class="btn btn-secondary btn-sm">Aktivieren</button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
