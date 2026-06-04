<?php

declare(strict_types=1);

/** @var array<string, mixed> $draftValues */
/** @var bool $isEdit */
/** @var array<string, mixed>|null $product */
?>
<input type="hidden" name="pzn" value="<?= e((string) ($draftValues['pzn'] ?? '')) ?>">
<input type="hidden" name="name" value="<?= e((string) ($draftValues['name'] ?? '')) ?>">
<input type="hidden" name="manufacturer" value="<?= e((string) ($draftValues['manufacturer'] ?? '')) ?>">
<input type="hidden" name="cost_price" value="<?= e((string) ($draftValues['cost_price'] ?? '')) ?>">
<input type="hidden" name="sale_price" value="<?= e((string) ($draftValues['sale_price'] ?? '')) ?>">
<input type="hidden" name="min_price" value="<?= e((string) ($draftValues['min_price'] ?? '')) ?>">
<input type="hidden" name="target_rank" value="<?= e((string) ($draftValues['target_rank'] ?? '')) ?>">
<input type="hidden" name="strategy" value="<?= e((string) ($draftValues['strategy'] ?? '')) ?>">
<input type="hidden" name="category" value="<?= e((string) ($draftValues['category'] ?? '')) ?>">
<input type="hidden" name="shop_url" value="<?= e((string) ($draftValues['shop_url'] ?? '')) ?>">
<input type="hidden" name="package_size" value="<?= e((string) ($draftValues['package_size'] ?? '')) ?>">
<input type="hidden" name="avp_price" value="<?= e((string) ($draftValues['avp_price'] ?? '')) ?>">
<input type="hidden" name="own_shipping_cost" value="<?= e((string) ($draftValues['own_shipping_cost'] ?? '0')) ?>">
<?php if ((int) ($draftValues['active'] ?? 1) === 1): ?>
    <input type="hidden" name="active" value="1">
<?php endif; ?>
<?php if ($isEdit && isset($product['id'])): ?>
    <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
<?php endif; ?>
