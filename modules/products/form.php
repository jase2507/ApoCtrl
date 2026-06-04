<?php

declare(strict_types=1);

/** @var array<string, mixed>|null $product */
/** @var list<string> $errors */
/** @var string $formAction */
/** @var bool $isEdit */
/** @var array{status:string,message:string,hits:list<array<string,mixed>>}|null $pznAutofill */
/** @var list<array<string,mixed>> $priceHistory */
$priceHistory ??= [];

$pznAutofill ??= ['status' => '', 'message' => '', 'hits' => []];
$productRow = is_array($product) ? $product : [];

$values = $product ?? [
    'pzn' => post('pzn', ''),
    'name' => post('name', ''),
    'manufacturer' => post('manufacturer', ''),
    'cost_price' => post('cost_price', ''),
    'sale_price' => post('sale_price', ''),
    'min_price' => post('min_price', ''),
    'target_rank' => post('target_rank', ''),
    'strategy' => post('strategy', ''),
    'category' => post('category', ''),
    'shop_url' => post('shop_url', ''),
    'package_size' => post('package_size', ''),
    'avp_price' => post('avp_price', ''),
    'own_shipping_cost' => post('own_shipping_cost', '0'),
    'active' => post('active', '1') !== '' && post('active', '1') !== '0' ? 1 : 0,
];

if ($product !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    foreach (['cost_price', 'sale_price', 'min_price', 'avp_price', 'own_shipping_cost'] as $moneyField) {
        $values[$moneyField] = $product[$moneyField] !== null ? (string) $product[$moneyField] : '';
    }
    $values['target_rank'] = $product['target_rank'] !== null ? (string) $product['target_rank'] : '';
    $values['shop_url'] = (string) ($product['shop_url'] ?? '');
    $values['package_size'] = (string) ($product['package_size'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array(post('action', ''), ['pzn-search', 'pzn-apply'], true)) {
    $values['active'] = isset($_POST['active']) ? 1 : 0;
}

$shopConfig = is_array($config['shop'] ?? null) ? $config['shop'] : [];
$shopBaseUrl = (string) ($shopConfig['base_url'] ?? 'https://shop.apotheker-seidel.de/');
$syncStatus = (string) ($productRow['shop_sync_status'] ?? '');
$syncError = (string) ($productRow['shop_sync_error'] ?? '');
$lastSync = (string) ($productRow['last_shop_sync_at'] ?? '');
$autofillStatus = (string) ($pznAutofill['status'] ?? '');
$autofillMessage = (string) ($pznAutofill['message'] ?? '');
$autofillHits = $pznAutofill['hits'] ?? [];
$autofillDebug = is_array($pznAutofill['debug'] ?? null) ? $pznAutofill['debug'] : null;
$autofillParsed = is_array($pznAutofill['parsed'] ?? null) ? $pznAutofill['parsed'] : null;
$feedUrl = (string) ($shopConfig['feed_url'] ?? '');
$deeplinkTemplate = (string) ($shopConfig['deeplink_template'] ?? 'https://shop.apotheker-seidel.de/product?artnr={PZN}');
?>
<div class="page-header">
    <h1><?= $isEdit ? 'Produkt bearbeiten' : 'Produkt anlegen' ?></h1>
    <p class="page-subtitle">
        <a href="products.php">&larr; Zurück zur Liste</a>
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

<?php if ($autofillMessage !== ''): ?>
    <div class="alert <?= $autofillStatus === 'error' || $autofillStatus === 'none' ? 'alert-error' : 'alert-success' ?>" role="status">
        <?= e($autofillMessage) ?>
    </div>
<?php endif; ?>

<form method="post" action="products.php" class="entity-form" id="product-form">
    <?= Csrf::field() ?>
    <?php if ($isEdit && isset($product['id'])): ?>
        <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
    <?php endif; ?>

    <div class="panel panel-pzn-autofill">
        <div class="panel-header">
            <h2>PZN-Autofill</h2>
            <p class="text-muted">Datenquelle: eigener Medizinfuchs-Feed<?= $feedUrl !== '' ? ' (' . e($feedUrl) . ')' : '' ?></p>
        </div>
        <div class="panel-body">
            <div class="form-grid form-grid-pzn">
                <div class="form-group">
                    <label for="pzn">PZN <span class="required">*</span></label>
                    <div class="input-with-action">
                        <input type="text" id="pzn" name="pzn" value="<?= e((string) ($values['pzn'] ?? '')) ?>" required maxlength="20">
                        <button type="submit" name="action" value="pzn-search" class="btn btn-secondary btn-sm" formnovalidate>
                            PZN im eigenen Feed suchen
                        </button>
                    </div>
                    <p class="field-hint">Serverseitige Suche – kein JavaScript erforderlich.</p>
                </div>
            </div>
            <div class="form-group form-group-check">
                <label class="checkbox-label">
                    <input type="checkbox" name="autofill_overwrite" value="1">
                    Vorhandene Felder überschreiben
                </label>
            </div>
            <?php if ($isEdit): ?>
                <div class="form-group form-group-check">
                    <label class="checkbox-label">
                        <input type="checkbox" name="autofill_run_sync" value="1" checked>
                        Nach Übernahme Shop-Sync inkl. Snapshot und Ranking
                    </label>
                </div>
            <?php endif; ?>

            <?php if ($autofillDebug !== null): ?>
                <div class="autofill-debug">
                    <h3>Debug (PZN-Autofill)</h3>
                    <ul class="status-list">
                        <li>Quelle: <strong><?= e((string) ($autofillDebug['source'] ?? 'page+feed')) ?></strong></li>
                        <li>PZN: <strong><?= e((string) ($autofillDebug['pzn'] ?? '')) ?></strong></li>
                        <li>Feed erreichbar: <strong><?= !empty($autofillDebug['feed_reachable']) ? 'ja' : 'nein' ?></strong></li>
                        <li>Feed gefunden: <strong><?= !empty($autofillDebug['feed_found']) ? 'ja' : 'nein' ?></strong></li>
                        <li>Feedpreis: <strong><?= isset($autofillDebug['feed_price']) ? e(formatMoney((float) $autofillDebug['feed_price'])) : '—' ?></strong></li>
                        <?php if (!empty($autofillDebug['feed_url'])): ?>
                            <li>Feed-URL: <code><?= e((string) $autofillDebug['feed_url']) ?></code></li>
                            <li>Cache: <strong><?= !empty($autofillDebug['from_cache']) ? 'ja' : 'nein' ?></strong></li>
                        <?php endif; ?>
                        <li>Produktseiten-URL: <code><?= e((string) ($autofillDebug['product_page_url'] ?? '')) ?></code></li>
                        <li>Effektive URL: <code><?= e((string) ($autofillDebug['page_effective_url'] ?? '—')) ?></code></li>
                        <li>HTTP Produktseite: <strong><?= isset($autofillDebug['page_http_code']) && $autofillDebug['page_http_code'] !== '' && $autofillDebug['page_http_code'] !== null ? e((string) $autofillDebug['page_http_code']) : '—' ?></strong></li>
                        <li>Response-Länge: <strong><?= isset($autofillDebug['page_content_length']) ? e((string) $autofillDebug['page_content_length']) . ' Bytes' : '—' ?></strong></li>
                        <li>Transport: <strong><?= e((string) ($autofillDebug['page_transport'] ?? '—')) ?></strong></li>
                        <li>cURL installiert: <strong><?= !empty($autofillDebug['curl_available']) ? 'ja' : 'nein' ?></strong></li>
                        <li>allow_url_fopen: <strong><?= !empty($autofillDebug['allow_url_fopen']) ? 'ja' : 'nein' ?></strong></li>
                        <li>cURL errno: <strong><?= isset($autofillDebug['page_curl_errno']) && $autofillDebug['page_curl_errno'] !== null ? e((string) $autofillDebug['page_curl_errno']) : '—' ?></strong></li>
                        <li>cURL Fehler: <strong><?= e((string) ($autofillDebug['page_curl_error'] ?? '—')) ?></strong></li>
                        <li>PHP last_error: <strong><?= e((string) ($autofillDebug['page_last_error'] ?? '—')) ?></strong></li>
                        <?php if (!empty($autofillDebug['page_fetch_error'])): ?>
                            <li class="text-error">Abruf-Fehler: <?= e((string) $autofillDebug['page_fetch_error']) ?></li>
                        <?php endif; ?>
                        <li>Produktname (HTML): <strong><?= e((string) ($autofillDebug['parsed_product_name'] ?? '—')) ?></strong></li>
                        <li>Hersteller (HTML): <strong><?= e((string) ($autofillDebug['parsed_manufacturer'] ?? '—')) ?></strong></li>
                        <li>Einheit (HTML): <strong><?= e((string) ($autofillDebug['parsed_package_size'] ?? '—')) ?></strong></li>
                        <li>Preis (HTML): <strong><?= isset($autofillDebug['parsed_price']) ? e(formatMoney((float) $autofillDebug['parsed_price'])) : '—' ?></strong></li>
                        <li>AVP (HTML): <strong><?= isset($autofillDebug['parsed_avp']) ? e(formatMoney((float) $autofillDebug['parsed_avp'])) : '—' ?></strong></li>
                        <li>Verkaufspreis (merged): <strong><?= isset($autofillDebug['merged_price']) ? e(formatMoney((float) $autofillDebug['merged_price'])) : '—' ?></strong></li>
                        <?php if (!empty($autofillDebug['warning'])): ?>
                            <li class="text-muted"><?= e((string) $autofillDebug['warning']) ?></li>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($autofillParsed !== null && in_array($autofillStatus, ['single', 'applied'], true)): ?>
                <div class="autofill-preview">
                    <h3>Vorschau Shopdaten</h3>
                    <ul class="status-list">
                        <li>Produktname: <strong><?= e((string) ($autofillParsed['product_name'] ?? '—')) ?></strong></li>
                        <li>Hersteller: <strong><?= e((string) ($autofillParsed['manufacturer'] ?? '—')) ?></strong></li>
                        <li>Packungsgröße: <strong><?= e((string) ($autofillParsed['package_size'] ?? '—')) ?></strong></li>
                        <li>Verkaufspreis: <strong><?= isset($autofillParsed['price']) ? e(formatMoney((float) $autofillParsed['price'])) : '—' ?></strong></li>
                        <li>AVP/UVP: <strong><?= isset($autofillParsed['avp_price']) ? e(formatMoney((float) $autofillParsed['avp_price'])) : '—' ?></strong></li>
                    </ul>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <div class="form-grid">
        <div class="form-group">
            <label for="name">Produktname <span class="required">*</span></label>
            <input type="text" id="name" name="name" value="<?= e((string) ($values['name'] ?? '')) ?>" required maxlength="255">
        </div>
        <div class="form-group">
            <label for="manufacturer">Hersteller</label>
            <input type="text" id="manufacturer" name="manufacturer" value="<?= e((string) ($values['manufacturer'] ?? '')) ?>" maxlength="255">
        </div>
        <div class="form-group">
            <label for="category">Kategorie</label>
            <input type="text" id="category" name="category" value="<?= e((string) ($values['category'] ?? '')) ?>" maxlength="100">
        </div>
        <div class="form-group">
            <label for="cost_price">Einkaufspreis (€)</label>
            <input type="text" id="cost_price" name="cost_price" value="<?= e((string) ($values['cost_price'] ?? '')) ?>" inputmode="decimal" placeholder="0,00">
        </div>
        <div class="form-group">
            <label for="sale_price">Verkaufspreis (€)</label>
            <input type="text" id="sale_price" name="sale_price" value="<?= e((string) ($values['sale_price'] ?? '')) ?>" inputmode="decimal" placeholder="0,00">
        </div>
        <div class="form-group">
            <label for="min_price">Mindestpreis (€)</label>
            <input type="text" id="min_price" name="min_price" value="<?= e((string) ($values['min_price'] ?? '')) ?>" inputmode="decimal" placeholder="0,00">
        </div>
        <div class="form-group">
            <label for="target_rank">Ziel-Ranking</label>
            <input type="number" id="target_rank" name="target_rank" min="1" step="1" value="<?= e((string) ($values['target_rank'] ?? '')) ?>">
        </div>
        <div class="form-group form-group-full">
            <label for="strategy">Strategie</label>
            <input type="text" id="strategy" name="strategy" value="<?= e((string) ($values['strategy'] ?? '')) ?>" maxlength="255">
        </div>
        <div class="form-group form-group-check">
            <label class="checkbox-label">
                <input type="checkbox" name="active" value="1" <?= (int) ($values['active'] ?? 1) === 1 ? 'checked' : '' ?>>
                Produkt ist aktiv (beobachtet)
            </label>
        </div>
    </div>

    <div class="panel panel-shop">
        <div class="panel-header">
            <h2>Eigener Shop</h2>
            <p class="text-muted">Nur URLs unter <?= e($shopBaseUrl) ?></p>
        </div>
        <div class="panel-body">
            <div class="form-grid">
                <div class="form-group form-group-full">
                    <label for="shop_url">Shop-URL</label>
                    <input
                        type="url"
                        id="shop_url"
                        name="shop_url"
                        value="<?= e((string) ($values['shop_url'] ?? '')) ?>"
                        maxlength="500"
                        placeholder="<?= e($shopBaseUrl) ?>…"
                    >
                </div>
                <div class="form-group">
                    <label for="package_size">Packungsgröße</label>
                    <input type="text" id="package_size" name="package_size" value="<?= e((string) ($values['package_size'] ?? '')) ?>" maxlength="120">
                </div>
                <div class="form-group">
                    <label for="avp_price">AVP/UVP (€)</label>
                    <input type="text" id="avp_price" name="avp_price" value="<?= e((string) ($values['avp_price'] ?? '')) ?>" inputmode="decimal">
                </div>
                <div class="form-group">
                    <label for="own_shipping_cost">Eigene Versandkosten (€)</label>
                    <input type="text" id="own_shipping_cost" name="own_shipping_cost" value="<?= e((string) ($values['own_shipping_cost'] ?? '0')) ?>" inputmode="decimal">
                </div>
            </div>

            <?php if ($isEdit): ?>
                <div class="shop-sync-status">
                    <h3>Shop-Sync-Status</h3>
                    <ul class="status-list">
                        <li>
                            Status:
                            <?php if ($syncStatus === 'ok'): ?>
                                <span class="badge badge-active">OK</span>
                            <?php elseif ($syncStatus === 'error'): ?>
                                <span class="badge badge-inactive">Fehler</span>
                            <?php else: ?>
                                <span class="text-muted">Noch kein Sync</span>
                            <?php endif; ?>
                        </li>
                        <li>Letzter Sync: <strong><?= $lastSync !== '' ? e($lastSync) : '—' ?></strong></li>
                        <?php if ($syncError !== ''): ?>
                            <li class="text-error">Fehler: <?= e($syncError) ?></li>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" name="action" value="<?= e($formAction) ?>" class="btn btn-primary"><?= $isEdit ? 'Speichern' : 'Anlegen' ?></button>
        <a href="products.php" class="btn btn-secondary">Abbrechen</a>
    </div>
</form>

<?php if ($autofillStatus === 'multiple' && $autofillHits !== []): ?>
    <?php $draftValues = ProductFormDraft::toFormProduct($values, $product); ?>
    <div class="panel autofill-hits">
        <div class="panel-header">
            <h2>Mehrere Treffer – Produkt auswählen</h2>
        </div>
        <div class="panel-body">
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Produkt</th>
                            <th>Preis</th>
                            <th>URL</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($autofillHits as $hit): ?>
                            <tr>
                                <td><?= e((string) ($hit['name'] ?? '')) ?></td>
                                <td>
                                    <?= isset($hit['price']) && $hit['price'] !== null
                                        ? e(formatMoney((float) $hit['price']))
                                        : '—' ?>
                                </td>
                                <td class="cell-truncate">
                                    <a href="<?= e((string) ($hit['url'] ?? '')) ?>" target="_blank" rel="noopener noreferrer">
                                        <?= e((string) ($hit['url'] ?? '')) ?>
                                    </a>
                                </td>
                                <td>
                                    <form method="post" action="products.php" class="inline-form">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="pzn-apply">
                                        <?php require __DIR__ . '/form_draft_fields.php'; ?>
                                        <input type="hidden" name="hit_url" value="<?= e((string) ($hit['url'] ?? '')) ?>">
                                        <input type="hidden" name="hit_name" value="<?= e((string) ($hit['name'] ?? '')) ?>">
                                        <input type="hidden" name="hit_price" value="<?= e((string) ($hit['price'] ?? '')) ?>">
                                        <?php if (!empty($_POST['autofill_overwrite'])): ?>
                                            <input type="hidden" name="autofill_overwrite" value="1">
                                        <?php endif; ?>
                                        <?php if (!empty($_POST['autofill_run_sync'])): ?>
                                            <input type="hidden" name="autofill_run_sync" value="1">
                                        <?php endif; ?>
                                        <button type="submit" class="btn btn-primary btn-sm">Übernehmen</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($isEdit && isset($product['id'])): ?>
    <form method="post" action="products.php" class="shop-sync-form">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="shop-sync">
        <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
        <button type="submit" class="btn btn-secondary">Shopdaten aktualisieren</button>
    </form>

    <div class="panel">
        <div class="panel-header">
            <h2>Preisverlauf</h2>
        </div>
        <div class="panel-body">
            <?php if ($priceHistory === []): ?>
                <p class="text-muted">Für dieses Produkt sind noch keine Preis-Snapshots gespeichert.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Datum</th>
                                <th>Wettbewerber</th>
                                <th>Preis</th>
                                <th>Versand</th>
                                <th>Endpreis</th>
                                <th>Ranking</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($priceHistory as $historyRow): ?>
                                <?php
                                $hPrice = (float) ($historyRow['price'] ?? 0);
                                $hShipping = (float) ($historyRow['shipping_cost'] ?? 0);
                                $hEnd = $hPrice + $hShipping;
                                ?>
                                <tr>
                                    <td><?= e((string) ($historyRow['captured_at'] ?? '')) ?></td>
                                    <td><?= e((string) ($historyRow['competitor_name'] ?? '—')) ?></td>
                                    <td><?= e(formatMoney($hPrice)) ?></td>
                                    <td><?= e(formatMoney($hShipping)) ?></td>
                                    <td><strong><?= e(formatMoney($hEnd)) ?></strong></td>
                                    <td><?= $historyRow['ranking'] !== null ? (int) $historyRow['ranking'] : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="text-muted">Neueste Einträge zuerst (max. 100).</p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
