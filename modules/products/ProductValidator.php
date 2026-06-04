<?php

declare(strict_types=1);

class ProductValidator
{
    public function __construct(
        private readonly ProductRepository $repository,
        private readonly ?ShopUrlValidator $shopUrlValidator = null,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{data: array<string, mixed>, errors: list<string>}
     */
    public function validate(array $input, ?int $excludeId = null): array
    {
        $errors = [];

        $pzn = trim((string) ($input['pzn'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $manufacturer = trim((string) ($input['manufacturer'] ?? ''));
        $strategy = trim((string) ($input['strategy'] ?? ''));
        $category = trim((string) ($input['category'] ?? ''));
        $shopUrl = trim((string) ($input['shop_url'] ?? ''));
        $packageSize = trim((string) ($input['package_size'] ?? ''));

        if ($pzn === '') {
            $errors[] = 'PZN ist ein Pflichtfeld.';
        } elseif ($this->repository->pznExists($pzn, $excludeId)) {
            $errors[] = 'Diese PZN ist bereits vergeben.';
        }

        if ($name === '') {
            $errors[] = 'Produktname ist ein Pflichtfeld.';
        }

        $costPrice = self::parsePrice($input['cost_price'] ?? '', 'Einkaufspreis', $errors);
        $salePrice = self::parsePrice($input['sale_price'] ?? '', 'Verkaufspreis', $errors);
        $minPrice = self::parsePrice($input['min_price'] ?? '', 'Mindestpreis', $errors);
        $avpPrice = self::parsePrice($input['avp_price'] ?? '', 'AVP/UVP', $errors);
        $ownShipping = self::parsePrice($input['own_shipping_cost'] ?? '', 'Eigene Versandkosten', $errors, false);
        if ($ownShipping === null) {
            $ownShipping = 0.0;
        }

        if ($minPrice !== null && $salePrice !== null && $minPrice > $salePrice) {
            $errors[] = 'Der Mindestpreis darf nicht größer als der Verkaufspreis sein.';
        }

        if ($shopUrl !== '' && $this->shopUrlValidator !== null) {
            $shopError = $this->shopUrlValidator->validateOrError($shopUrl);
            if ($shopError !== null) {
                $errors[] = $shopError;
            }
        }

        $targetRank = self::parseTargetRank($input['target_rank'] ?? '', $errors);

        $active = !empty($input['active']) ? 1 : 0;

        $data = [
            'pzn' => $pzn,
            'name' => $name,
            'manufacturer' => $manufacturer !== '' ? $manufacturer : null,
            'cost_price' => $costPrice,
            'sale_price' => $salePrice,
            'min_price' => $minPrice,
            'target_rank' => $targetRank,
            'strategy' => $strategy !== '' ? $strategy : null,
            'category' => $category !== '' ? $category : null,
            'active' => $active,
            'shop_url' => $shopUrl !== '' ? $shopUrl : null,
            'package_size' => $packageSize !== '' ? $packageSize : null,
            'avp_price' => $avpPrice,
            'own_shipping_cost' => $ownShipping,
        ];

        return ['data' => $data, 'errors' => $errors];
    }

    private static function parsePrice(mixed $value, string $label, array &$errors, bool $allowEmpty = true): ?float
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return $allowEmpty ? null : 0.0;
        }

        $normalized = str_replace(',', '.', $raw);

        if (!is_numeric($normalized)) {
            $errors[] = "{$label} muss eine Zahl sein.";
            return null;
        }

        $float = (float) $normalized;

        if ($float < 0) {
            $errors[] = "{$label} muss größer oder gleich 0 sein.";
            return null;
        }

        return $float;
    }

    private static function parseTargetRank(mixed $value, array &$errors): ?int
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        if (!ctype_digit($raw) && !preg_match('/^\d+$/', $raw)) {
            $errors[] = 'Ziel-Ranking muss eine ganze Zahl sein.';
            return null;
        }

        $rank = (int) $raw;

        if ($rank < 1) {
            $errors[] = 'Ziel-Ranking muss mindestens 1 sein.';
            return null;
        }

        return $rank;
    }
}
