<?php

declare(strict_types=1);

class ProductFormDraft
{
    /**
     * @return array<string, mixed>
     */
    public static function fromPost(array $post): array
    {
        return [
            'pzn' => trim((string) ($post['pzn'] ?? '')),
            'name' => trim((string) ($post['name'] ?? '')),
            'manufacturer' => trim((string) ($post['manufacturer'] ?? '')),
            'cost_price' => trim((string) ($post['cost_price'] ?? '')),
            'sale_price' => trim((string) ($post['sale_price'] ?? '')),
            'min_price' => trim((string) ($post['min_price'] ?? '')),
            'target_rank' => trim((string) ($post['target_rank'] ?? '')),
            'strategy' => trim((string) ($post['strategy'] ?? '')),
            'category' => trim((string) ($post['category'] ?? '')),
            'shop_url' => trim((string) ($post['shop_url'] ?? '')),
            'package_size' => trim((string) ($post['package_size'] ?? '')),
            'avp_price' => trim((string) ($post['avp_price'] ?? '')),
            'own_shipping_cost' => trim((string) ($post['own_shipping_cost'] ?? '0')),
            'active' => !empty($post['active']) ? 1 : 0,
        ];
    }

    /**
     * @param array<string, mixed> $draft
     * @return array<string, mixed>
     */
    public static function toFormProduct(array $draft, ?array $existing = null): array
    {
        $product = $existing ?? [];
        foreach ($draft as $key => $value) {
            $product[$key] = $value;
        }

        return $product;
    }

    /**
     * Vor neuer PZN-Suche: Shop-Autofill-Felder leeren, damit keine Daten einer früheren PZN erhalten bleiben.
     *
     * @param array<string, mixed> $draft
     * @return array<string, mixed>
     */
    public static function clearShopAutofillFields(array $draft): array
    {
        foreach (['name', 'manufacturer', 'package_size', 'shop_url', 'avp_price', 'sale_price'] as $field) {
            $draft[$field] = '';
        }

        return $draft;
    }
}
