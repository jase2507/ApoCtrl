<?php

declare(strict_types=1);

class ProductAutofillMerger
{
    /**
     * @param array<string, mixed> $draft
     * @param array{
     *   product_name:?string,
     *   manufacturer:?string,
     *   package_size:?string,
     *   pzn:?string,
     *   price:?float,
     *   avp_price:?float,
     *   delivery_status:?string
     * } $parsed
     * @return array<string, mixed>
     */
    public function mergeDraft(array $draft, array $parsed, string $shopUrl, bool $overwrite): array
    {
        $mapping = [
            'name' => $parsed['product_name'] ?? null,
            'manufacturer' => $parsed['manufacturer'] ?? null,
            'package_size' => $parsed['package_size'] ?? null,
            'avp_price' => $parsed['avp_price'] ?? null,
            'sale_price' => $parsed['price'] ?? null,
        ];

        foreach ($mapping as $field => $value) {
            if ($value === null) {
                continue;
            }

            $draft[$field] = $this->assignValue($draft, $field, $value, $overwrite);
        }

        if ($shopUrl !== '') {
            $draft['shop_url'] = $this->assignValue($draft, 'shop_url', $shopUrl, $overwrite);
        }

        if (isset($draft['pzn']) && trim((string) $draft['pzn']) !== '' && isset($parsed['pzn'])) {
            $draft['pzn'] = ShopHtmlParser::normalizePzn((string) $draft['pzn']);
        }

        return $draft;
    }

    /**
     * @param array<string, mixed> $existing DB row or draft
     * @param array{
     *   product_name:?string,
     *   manufacturer:?string,
     *   package_size:?string,
     *   pzn:?string,
     *   price:?float,
     *   avp_price:?float,
     *   delivery_status:?string
     * } $parsed
     * @return array<string, mixed> fields to UPDATE (only changed keys for DB)
     */
    public function buildDbPatch(array $existing, array $parsed, string $shopUrl, bool $overwrite): array
    {
        $draft = $this->mergeDraft($existing, $parsed, $shopUrl, $overwrite);
        $patch = [];

        foreach (['name', 'manufacturer', 'package_size', 'avp_price', 'sale_price', 'shop_url'] as $field) {
            $newVal = $draft[$field] ?? null;
            $oldVal = $existing[$field] ?? null;

            if ($this->valuesDiffer($oldVal, $newVal)) {
                $patch[$field] = $newVal;
            }
        }

        return $patch;
    }

    private function assignValue(array $draft, string $field, mixed $value, bool $overwrite): mixed
    {
        if ($overwrite) {
            if ($field === 'avp_price' || $field === 'sale_price') {
                return is_float($value) || is_int($value)
                    ? (string) $value
                    : (string) $value;
            }

            return is_string($value) ? $value : $value;
        }

        $current = $draft[$field] ?? null;

        if ($field === 'avp_price' || $field === 'sale_price') {
            if ($current !== null && $current !== '' && (float) $current > 0) {
                return $current;
            }

            return $value !== null ? (string) $value : $current;
        }

        if (is_string($current) && trim($current) !== '') {
            return $current;
        }

        if ($current !== null && $current !== '' && !is_string($current)) {
            return $current;
        }

        return is_string($value) ? $value : ($value !== null ? (string) $value : $current);
    }

    private function valuesDiffer(mixed $old, mixed $new): bool
    {
        if ($old === null && $new === null) {
            return false;
        }

        if (is_numeric($old) || is_numeric($new)) {
            return abs((float) $old - (float) $new) > 0.0001;
        }

        return trim((string) $old) !== trim((string) $new);
    }
}
