<?php

declare(strict_types=1);

/** @var array<string, mixed>|null $result */
/** @var string $pzn */
/** @var string $debugFilePath */
/** @var bool $debugFileExists */
/** @var string $savedPreview */
?>
<div class="page-header page-header-row">
    <div>
        <h1>Collector HTTP-Diagnose</h1>
        <p class="page-subtitle">
            Zeigt den tatsächlichen HTML-Abruf der Medizinfuchs-Such-URL
            <a href="collector.php">&larr; Zurück zur Datenerfassung</a>
        </p>
    </div>
</div>

<section class="panel">
    <div class="panel-header">
        <h2>Abruf testen</h2>
    </div>
    <div class="panel-body">
        <form method="post" action="collector_diagnose.php" class="toolbar">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="probe">
            <div class="toolbar-group">
                <label for="diag-pzn">PZN</label>
                <input type="text" id="diag-pzn" name="pzn" maxlength="20" value="<?= e($pzn) ?>" required>
            </div>
            <button type="submit" class="btn btn-primary">HTTP-Abruf starten</button>
        </form>
    </div>
</section>

<?php if (is_array($result)): ?>
    <section class="panel">
        <div class="panel-header">
            <h2>Ergebnis</h2>
        </div>
        <div class="panel-body">
            <dl class="detail-dl">
                <dt>URL</dt>
                <dd><code><?= e((string) ($result['url'] ?? '')) ?></code></dd>
                <dt>HTTP-Code</dt>
                <dd><?= e((string) ($result['http_code'] ?? '—')) ?></dd>
                <dt>Content-Type</dt>
                <dd><?= e((string) ($result['content_type'] ?? '—')) ?></dd>
                <dt>Response-Größe</dt>
                <dd><?= (int) ($result['response_length'] ?? 0) ?> Bytes</dd>
                <dt>Final URL</dt>
                <dd><code><?= e((string) ($result['effective_url'] ?? '—')) ?></code></dd>
                <dt>User-Agent</dt>
                <dd><small><?= e((string) ($result['user_agent'] ?? '—')) ?></small></dd>
                <dt>Transport</dt>
                <dd><?= e((string) ($result['transport'] ?? '—')) ?></dd>
                <?php if (!empty($result['error'])): ?>
                    <dt>Fehler</dt>
                    <dd class="text-muted"><?= e((string) $result['error']) ?></dd>
                <?php endif; ?>
            </dl>

            <h3>Inhalt-Prüfung</h3>
            <ul class="status-list">
                <?php foreach (is_array($result['content_checks'] ?? null) ? $result['content_checks'] : [] as $marker => $found): ?>
                    <li>
                        <span class="status-badge <?= $found ? 'status-ok' : 'status-warn' ?>">
                            <?= $found ? 'gefunden' : 'fehlt' ?>
                        </span>
                        <?= e((string) $marker) ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <h3>Response-Vorschau (erste 1000 Zeichen)</h3>
            <pre class="code-preview"><?= e((string) ($result['response_preview'] ?? '')) ?></pre>

            <?php if ($debugFileExists): ?>
                <p class="text-muted">
                    Gespeichert unter: <code><?= e($debugFilePath) ?></code>
                    <?php if ($savedPreview !== '' && $savedPreview !== (string) ($result['response_preview'] ?? '')): ?>
                        <br>Datei-Inhalt (Vorschau):
                    <?php endif; ?>
                </p>
                <?php if ($savedPreview !== '' && $savedPreview !== (string) ($result['response_preview'] ?? '')): ?>
                    <pre class="code-preview"><?= e($savedPreview) ?></pre>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
