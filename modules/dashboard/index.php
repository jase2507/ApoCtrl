<?php

declare(strict_types=1);

/** @var array{observed_products:int,competitors:int,snapshots_today:int,snapshots_total:int,average_ranking:?float} $dashboardStats */
$avgRanking = $dashboardStats['average_ranking'] ?? null;
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
