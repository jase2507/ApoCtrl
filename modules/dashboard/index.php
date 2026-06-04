<?php

declare(strict_types=1);

/** @var array{observed_products:int,competitors:int,snapshots_today:int,snapshots_total:int,average_ranking:?float,products_needing_action:int} $dashboardStats */
/** @var array<string,mixed>|null $lastCollectorRun */
$avgRanking = $dashboardStats['average_ranking'] ?? null;
$lastCollectionLabel = '—';
if (is_array($lastCollectorRun) && !empty($lastCollectorRun['finished_at'])) {
    $lastCollectionLabel = (string) $lastCollectorRun['finished_at'];
} elseif (is_array($lastCollectorRun) && !empty($lastCollectorRun['started_at'])) {
    $lastCollectionLabel = (string) $lastCollectorRun['started_at'] . ' (läuft)';
}
?>
<div class="page-header">
    <h1>Dashboard</h1>
    <p class="page-subtitle">Willkommen, <?= e($user['username']) ?> (<?= e($user['role']) ?>)</p>
</div>

<div class="kpi-grid">
    <div class="kpi-card">
        <span class="kpi-label">Beobachtete Produkte</span>
        <span class="kpi-value"><?= (int) $dashboardStats['observed_products'] ?></span>
        <span class="kpi-hint">Mit mindestens einem Snapshot</span>
    </div>
    <div class="kpi-card">
        <span class="kpi-label">Wettbewerber</span>
        <span class="kpi-value"><?= (int) $dashboardStats['competitors'] ?></span>
        <span class="kpi-hint">Aktiv, ohne Testdaten</span>
    </div>
    <div class="kpi-card">
        <span class="kpi-label">Snapshots heute</span>
        <span class="kpi-value"><?= (int) $dashboardStats['snapshots_today'] ?></span>
        <span class="kpi-hint">Erfasst am heutigen Tag</span>
    </div>
    <div class="kpi-card">
        <span class="kpi-label">Snapshots gesamt</span>
        <span class="kpi-value"><?= (int) $dashboardStats['snapshots_total'] ?></span>
        <span class="kpi-hint"><a href="snapshots.php">Alle anzeigen</a></span>
    </div>
    <div class="kpi-card">
        <span class="kpi-label">Durchschnittliches Ranking</span>
        <span class="kpi-value"><?= $avgRanking !== null ? e((string) $avgRanking) : '—' ?></span>
        <span class="kpi-hint">Über alle gerankten Snapshots</span>
    </div>
    <div class="kpi-card">
        <span class="kpi-label">Produkte mit Handlungsbedarf</span>
        <span class="kpi-value"><?= (int) ($dashboardStats['products_needing_action'] ?? 0) ?></span>
        <span class="kpi-hint"><a href="pricing.php">Ist-Rang schlechter als Ziel</a></span>
    </div>
    <div class="kpi-card">
        <span class="kpi-label">Letzte Datenerfassung</span>
        <span class="kpi-value kpi-value-text"><?= e($lastCollectionLabel) ?></span>
        <span class="kpi-hint"><a href="collector.php">Collector</a></span>
    </div>
</div>

<div class="content-grid">
    <section class="panel">
        <div class="panel-header">
            <h2>Systemstatus</h2>
        </div>
        <div class="panel-body">
            <ul class="status-list">
                <li>
                    <span class="status-badge status-ok">Aktiv</span>
                    Authentifizierung &amp; Session
                </li>
                <li>
                    <span class="status-badge status-ok">Aktiv</span>
                    SQLite-Datenbank (PDO)
                </li>
                <li>
                    <span class="status-badge status-ok">Aktiv</span>
                    CSRF-Schutz
                </li>
                <li>
                    <span class="status-badge status-ok">Aktiv</span>
                    Produkte, Importe, Rankings, Snapshots
                </li>
            </ul>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <h2>Alerts</h2>
        </div>
        <div class="panel-body">
            <p class="text-muted">Noch keine Alerts – wird in einer späteren Phase implementiert.</p>
        </div>
    </section>
</div>

<div class="content-grid">
    <section class="panel">
        <div class="panel-header">
            <h2>Top Gewinner</h2>
        </div>
        <div class="panel-body">
            <p class="text-muted">Ranking-Auswertungen folgen in einer späteren Phase.</p>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <h2>Top Verlierer</h2>
        </div>
        <div class="panel-body">
            <p class="text-muted">Ranking-Auswertungen folgen in einer späteren Phase.</p>
        </div>
    </section>
</div>
