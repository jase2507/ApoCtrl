<?php

declare(strict_types=1);

class MedizinfuchsParser
{
    /**
     * @return list<array{competitor:string,price:float,shipping_cost:float,delivery_status:string}>
     */
    public function parse(string $html): array
    {
        $html = trim($html);
        if ($html === '') {
            return [];
        }

        $offers = [];

        if (preg_match_all(
            '/data-competitor="([^"]*)"[^>]*data-price="([^"]*)"[^>]*data-shipping="([^"]*)"[^>]*data-status="([^"]*)"/iu',
            $html,
            $matches,
            PREG_SET_ORDER
        ) === 0) {
            return [];
        }

        foreach ($matches as $match) {
            $name = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($name === '') {
                continue;
            }

            $price = $this->parseMoney($match[2]);
            if ($price === null) {
                continue;
            }

            $shipping = $this->parseMoney($match[3]) ?? 0.0;
            $status = trim(html_entity_decode($match[4], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            $offers[] = [
                'competitor' => $name,
                'price' => $price,
                'shipping_cost' => $shipping,
                'delivery_status' => $status !== '' ? $status : 'lieferbar',
            ];
        }

        return $offers;
    }

    private function parseMoney(string $value): ?float
    {
        $normalized = str_replace(['€', ' '], '', trim($value));
        $normalized = str_replace(',', '.', $normalized);
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 2);
    }
}
