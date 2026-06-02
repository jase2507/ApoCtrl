<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $competitors */
/** @var list<array{name:string,count:int,ids:string}> $duplicates */
?>
<div class="page-header page-header-row">
    <div>
        <h1>Wettbewerber</h1>
        <p class="page-subtitle"><?= count($competitors) ?> Wettbewerber</p>
    </div>
    <div class="actions-cell">
        <a href="competitors.php?action=create" class="btn btn-primary">Neuer Wettbewerber</a>
        <form method="post" action="competitors.php" class="inline-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="cleanup-competitors">
            <button
                type="submit"
                class="btn btn-secondary"
                onclick="return confirm('Phase4-Testdaten löschen und DocMorris-Duplikate zusammenführen?');"
            >
                Bereinigung ausführen
            </button>
        </form>
    </div>
</div>

<?php if ($duplicates !== []): ?>
    <div class="alert alert-warning" role="alert">
        <strong>Duplikate gefunden:</strong>
        <?php foreach ($duplicates as $dup): ?>
            <div>
                <?= e($dup['name']) ?> (<?= (int) $dup['count'] ?>x, IDs: <?= e($dup['ids']) ?>)
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

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
                                <form method="post" action="competitors.php" class="inline-form">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $competitor['id'] ?>">
                                    <button
                                        type="submit"
                                        class="btn btn-danger btn-sm"
                                        onclick="return confirm('Wettbewerber wirklich löschen?');"
                                    >
                                        Löschen
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
