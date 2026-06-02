<div class="page-header">
    <h1>Dashboard</h1>
    <p class="page-subtitle">Willkommen, <?= e($user['username']) ?> (<?= e($user['role']) ?>)</p>
</div>

<div class="kpi-grid">
    <div class="kpi-card kpi-placeholder">
        <span class="kpi-label">Produkte gesamt</span>
        <span class="kpi-value">—</span>
        <span class="kpi-hint">Phase 2</span>
    </div>
    <div class="kpi-card kpi-placeholder">
        <span class="kpi-label">Aktive Produkte</span>
        <span class="kpi-value">—</span>
        <span class="kpi-hint">Phase 2</span>
    </div>
    <div class="kpi-card kpi-placeholder">
        <span class="kpi-label">Alerts offen</span>
        <span class="kpi-value">—</span>
        <span class="kpi-hint">Phase 2</span>
    </div>
    <div class="kpi-card kpi-placeholder">
        <span class="kpi-label">Durchschnittsranking</span>
        <span class="kpi-value">—</span>
        <span class="kpi-hint">Phase 2</span>
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
                    <span class="status-badge status-pending">Geplant</span>
                    Produkte, Importe, Rankings
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
            <p class="text-muted">Ranking-Daten folgen in Phase 2.</p>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <h2>Top Verlierer</h2>
        </div>
        <div class="panel-body">
            <p class="text-muted">Ranking-Daten folgen in Phase 2.</p>
        </div>
    </section>
</div>
