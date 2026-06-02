<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $competitors */
?>
<div class="page-header page-header-row">
    <div>
        <h1>Wettbewerber</h1>
        <p class="page-subtitle"><?= count($competitors) ?> Wettbewerber</p>
    </div>
    <a href="competitors.php?action=create" class="btn btn-primary">Neuer Wettbewerber</a>
</div>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>URL</th>
                    <th>Priorität</th>
                    <th>Status</th>
                    <th>Notizen</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($competitors === []): ?>
                    <tr>
                        <td colspan="6" class="text-muted">Noch keine Wettbewerber angelegt.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($competitors as $competitor): ?>
                        <tr class="<?= (int) $competitor['active'] === 0 ? 'row-inactive' : '' ?>">
                            <td><strong><?= e($competitor['name']) ?></strong></td>
                            <td>
                                <?php if (!empty($competitor['url'])): ?>
                                    <a href="<?= e($competitor['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($competitor['url']) ?></a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) $competitor['priority'] ?></td>
                            <td>
                                <?php if ((int) $competitor['active'] === 1): ?>
                                    <span class="badge badge-active">Aktiv</span>
                                <?php else: ?>
                                    <span class="badge badge-inactive">Inaktiv</span>
                                <?php endif; ?>
                            </td>
                            <td class="cell-truncate"><?= e($competitor['notes'] ?? '—') ?></td>
                            <td class="actions-cell">
                                <a href="competitors.php?action=edit&amp;id=<?= (int) $competitor['id'] ?>" class="btn btn-secondary btn-sm">Bearbeiten</a>
                                <?php if ((int) $competitor['active'] === 1): ?>
                                    <form method="post" action="competitors.php" class="inline-form">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="set-status">
                                        <input type="hidden" name="id" value="<?= (int) $competitor['id'] ?>">
                                        <input type="hidden" name="active" value="0">
                                        <button type="submit" class="btn btn-secondary btn-sm">Deaktivieren</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="competitors.php" class="inline-form">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="set-status">
                                        <input type="hidden" name="id" value="<?= (int) $competitor['id'] ?>">
                                        <input type="hidden" name="active" value="1">
                                        <button type="submit" class="btn btn-primary btn-sm">Aktivieren</button>
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
