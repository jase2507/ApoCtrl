<?php

declare(strict_types=1);

/** @var array<string, mixed>|null $lastRun */
/** @var list<array<string, mixed>> $recentRuns */
/** @var bool $mockMode */
/** @var array<string, mixed>|null $singleResult */
/** @var array<string, mixed>|null $runSummary */
/** @var list<array<string, mixed>> $latestLogs */
/** @var bool $collectorDebug */
?>
<div class="page-header page-header-row">
    <div>
        <h1>Datenerfassung (Medizinfuchs)</h1>
        <p class="page-subtitle">
            Automatische Wettbewerberpreise per Collector
            <?php if ($mockMode): ?>
                <span class="badge badge-test">Mock-Modus</span>
            <?php else: ?>
                <span class="badge badge-active">Live-Provider</span>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php if ($lastRun !== null): ?>
    <div class="panel">
        <div class="panel-header">
            <h2>Letzter Lauf</h2>
        </div>
        <div class="panel-body">
            <dl class="detail-dl">
                <dt>Start</dt>
                <dd><?= e((string) ($lastRun['started_at'] ?? '—')) ?></dd>
                <dt>Ende</dt>
                <dd><?= e((string) ($lastRun['finished_at'] ?? '—')) ?></dd>
                <dt>Produkte</dt>
                <dd><?= (int) ($lastRun['products_processed'] ?? 0) ?></dd>
                <dt>Snapshots</dt>
                <dd><?= (int) ($lastRun['snapshots_created'] ?? 0) ?></dd>
                <dt>Fehler</dt>
                <dd><?= (int) ($lastRun['errors'] ?? 0) ?></dd>
                <dt>Status</dt>
                <dd><strong><?= e((string) ($lastRun['status'] ?? '—')) ?></strong></dd>
            </dl>
        </div>
    </div>
<?php endif; ?>

<div class="content-grid">
    <section class="panel">
        <div class="panel-header">
            <h2>Gesamten Lauf starten</h2>
        </div>
        <div class="panel-body">
            <p class="text-muted">Verarbeitet alle aktiven Produkte (ohne Testdaten). Fehler bei einzelnen PZN stoppen den Lauf nicht.</p>
            <form method="post" action="collector.php" class="inline-form">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="run-all">
                <button type="submit" class="btn btn-primary" onclick="return confirm('Collector für alle aktiven Produkte starten?');">
                    Gesamten Lauf starten
                </button>
            </form>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <h2>Einzelne PZN sammeln</h2>
        </div>
        <div class="panel-body">
            <form method="post" action="collector.php" class="toolbar">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="run-pzn">
                <div class="toolbar-group">
                    <label for="collector-pzn">PZN</label>
                    <input type="text" id="collector-pzn" name="pzn" maxlength="20" placeholder="z. B. 16609329" required>
                </div>
                <button type="submit" class="btn btn-secondary">Jetzt sammeln</button>
            </form>
            <?php if (is_array($singleResult)): ?>
                <div class="alert <?= !empty($singleResult['success']) ? 'alert-success' : 'alert-error' ?>" role="status">
                    PZN <?= e((string) ($singleResult['pzn'] ?? '')) ?>:
                    <?= e((string) ($singleResult['message'] ?? '')) ?>
                    (<?= (int) ($singleResult['snapshots_created'] ?? 0) ?> Snapshot(s))
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php if (is_array($runSummary)): ?>
    <div class="panel">
        <div class="panel-header">
            <h2>Ergebnis letzter Gesamt-Lauf</h2>
        </div>
        <div class="panel-body">
            <ul class="status-list">
                <li>Run-ID: <strong><?= (int) ($runSummary['run_id'] ?? 0) ?></strong></li>
                <li>Produkte: <strong><?= (int) ($runSummary['products_processed'] ?? 0) ?></strong></li>
                <li>Snapshots: <strong><?= (int) ($runSummary['snapshots_created'] ?? 0) ?></strong></li>
                <li>Fehler: <strong><?= (int) ($runSummary['errors'] ?? 0) ?></strong></li>
                <li>Status: <strong><?= e((string) ($runSummary['status'] ?? '')) ?></strong></li>
            </ul>
        </div>
    </div>
<?php endif; ?>

<div class="panel">
    <div class="panel-header">
        <h2>Letzte Abrufe</h2>
    </div>
    <div class="panel-body">
        <?php if ($collectorDebug): ?>
            <p class="text-muted">Debug-Modus aktiv: Abrufdetails werden nach jedem Lauf in den Logs gespeichert (URL, HTTP, Dauer, Cache).</p>
        <?php endif; ?>
        <?php if (($latestLogs ?? []) === []): ?>
            <p class="text-muted">Noch keine Abruf-Logs vorhanden.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Zeit</th>
                            <th>PZN</th>
                            <th>HTTP</th>
                            <th>Dauer</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($latestLogs as $log): ?>
                            <tr>
                                <td><?= e((string) ($log['created_at'] ?? '')) ?></td>
                                <td><?= e((string) ($log['pzn'] ?? '')) ?></td>
                                <td><?= e((string) ($log['http_code'] ?? '—')) ?></td>
                                <td><?= (int) ($log['duration_ms'] ?? 0) ?> ms</td>
                                <td>
                                    <?= e((string) ($log['status'] ?? '')) ?>
                                    <?php if ($collectorDebug && !empty($log['url'])): ?>
                                        <br><small class="text-muted"><?= e((string) $log['url']) ?></small>
                                    <?php endif; ?>
                                    <?php if ($collectorDebug && !empty($log['error_message'])): ?>
                                        <br><small class="text-muted"><?= e((string) $log['error_message']) ?></small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <div class="panel-header">
        <h2>Collector-Läufe (Historie)</h2>
    </div>
    <div class="panel-body">
        <?php if ($recentRuns === []): ?>
            <p class="text-muted">Noch kein Collector-Lauf protokolliert.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Start</th>
                            <th>Ende</th>
                            <th>Produkte</th>
                            <th>Snapshots</th>
                            <th>Fehler</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentRuns as $run): ?>
                            <tr>
                                <td><?= e((string) ($run['started_at'] ?? '')) ?></td>
                                <td><?= e((string) ($run['finished_at'] ?? '—')) ?></td>
                                <td><?= (int) ($run['products_processed'] ?? 0) ?></td>
                                <td><?= (int) ($run['snapshots_created'] ?? 0) ?></td>
                                <td><?= (int) ($run['errors'] ?? 0) ?></td>
                                <td><?= e((string) ($run['status'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
