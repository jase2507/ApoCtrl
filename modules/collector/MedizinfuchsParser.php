<?php

declare(strict_types=1);

class MedizinfuchsParser
{
    private const PRICE_REGEX = '/([0-9]+,[0-9]{2})\s*€/u';

    /** @var array<string, string|null> */
    private array $lastProductInfo = [];

    /** @var list<array<string, mixed>> */
    private array $lastOffersDebug = [];

    /**
     * @return list<array{competitor:string,price:float,shipping_cost:float,delivery_status:string}>
     */
    public function parse(string $html): array
    {
        $this->lastProductInfo = [];
        $this->lastOffersDebug = [];

        $html = trim($html);
        if ($html === '') {
            return [];
        }

        $mockOffers = $this->parseMockAttributes($html);
        if ($mockOffers !== []) {
            $this->buildDebugFromOffers($mockOffers);

            return $mockOffers;
        }

        $dom = $this->loadDom($html);
        if ($dom === null) {
            return [];
        }

        $this->lastProductInfo = $this->extractProductInfo($dom, $html);
        $offers = $this->parseApothekeBlocks($dom);

        if ($offers === []) {
            $offers = $this->parseTextOfferBlocks($html);
        }

        $offers = $this->deduplicateOffers($offers);
        $this->buildDebugFromOffers($offers);

        return array_map(static function (array $offer): array {
            return [
                'competitor' => (string) $offer['competitor'],
                'price' => (float) $offer['price'],
                'shipping_cost' => (float) ($offer['shipping_cost'] ?? 0),
                'delivery_status' => (string) ($offer['delivery_status'] ?? 'lieferbar'),
            ];
        }, $offers);
    }

    /**
     * @return array{product: array<string, string|null>, offers: list<array<string, mixed>>}
     */
    public function getLastParseDebug(): array
    {
        return [
            'product' => $this->lastProductInfo,
            'offers' => $this->lastOffersDebug,
        ];
    }

    /**
     * @return list<array{competitor:string,price:float,shipping_cost:float,total_price:float,delivery_status:string}>
     */
    private function parseMockAttributes(string $html): array
    {
        if (preg_match_all(
            '/data-competitor="([^"]*)"[^>]*data-price="([^"]*)"[^>]*data-shipping="([^"]*)"[^>]*data-status="([^"]*)"/iu',
            $html,
            $matches,
            PREG_SET_ORDER
        ) === 0) {
            return [];
        }

        $offers = [];
        foreach ($matches as $match) {
            $name = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $price = $this->parseMoney($match[2]);
            if ($name === '' || $price === null) {
                continue;
            }

            $shipping = $this->parseMoney($match[3]) ?? 0.0;
            $status = trim(html_entity_decode($match[4], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            $offers[] = [
                'competitor' => $name,
                'price' => $price,
                'shipping_cost' => $shipping,
                'total_price' => round($price + $shipping, 2),
                'delivery_status' => $status !== '' ? $status : 'lieferbar',
            ];
        }

        return $offers;
    }

    private function loadDom(string $html): ?DOMDocument
    {
        if (!class_exists(DOMDocument::class)) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $dom : null;
    }

    /**
     * @return array<string, string|null>
     */
    private function extractProductInfo(DOMDocument $dom, string $html): array
    {
        $xpath = new DOMXPath($dom);

        $info = [
            'name' => null,
            'manufacturer' => null,
            'pzn' => null,
            'uvp' => null,
            'package_size' => null,
        ];

        $h1 = $xpath->query('//h1');
        if ($h1 !== false && $h1->length > 0) {
            $info['name'] = $this->normalizeText($h1->item(0)?->textContent ?? '');
        }

        $pznNodes = $xpath->query("//*[contains(translate(@class,'PZN','pzn'),'pzn')]");
        if ($pznNodes !== false) {
            foreach ($pznNodes as $node) {
                $text = $this->normalizeText($node->textContent ?? '');
                if (preg_match('/\b([0-9]{7,8})\b/u', $text, $m) === 1) {
                    $info['pzn'] = $m[1];
                    break;
                }
            }
        }

        if ($info['pzn'] === null && preg_match('/pzn-([0-9]{7,8})/iu', $html, $m) === 1) {
            $info['pzn'] = $m[1];
        }

        if ($info['pzn'] === null && preg_match('/\bPZN[:\s]*([0-9]{7,8})\b/iu', $html, $m) === 1) {
            $info['pzn'] = $m[1];
        }

        if (preg_match('/(?:Hersteller|Marke|Anbieter)[:\s]+([^\n<]+)/iu', $html, $m) === 1) {
            $info['manufacturer'] = trim($m[1]);
        }

        if ($info['manufacturer'] === null) {
            $hersteller = $xpath->query("//*[contains(translate(@class,'HERST','herst'),'herst')]");
            if ($hersteller !== false && $hersteller->length > 0) {
                $info['manufacturer'] = $this->normalizeText($hersteller->item(0)?->textContent ?? '');
            }
        }

        if (preg_match('/\bUVP[:\s]*([0-9]+,[0-9]{2})\s*€/iu', $html, $m) === 1) {
            $info['uvp'] = $m[1] . ' €';
        }

        if (preg_match('/\b([0-9]+(?:[.,][0-9]+)?\s*(?:ml|g|mg|l|Stk\.|St\.|Stück|Tabletten|Kapseln|Filmtabletten|Zäpfchen|Ampullen))\b/iu', $html, $m) === 1) {
            $info['package_size'] = trim($m[1]);
        }

        return $info;
    }

    /**
     * @return list<array{competitor:string,price:float,shipping_cost:float,total_price:float,delivery_status:string}>
     */
    private function parseApothekeBlocks(DOMDocument $dom): array
    {
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' apotheke ')]");
        if ($nodes === false || $nodes->length === 0) {
            $nodes = $xpath->query("//*[contains(translate(@class,'APOTHEKE','apotheke'),'apotheke')]");
        }

        if ($nodes === false || $nodes->length === 0) {
            return [];
        }

        $offers = [];
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $blockHtml = $dom->saveHTML($node) ?: '';
            $text = $this->normalizeText($node->textContent ?? '');

            $name = $this->extractCompetitorNameFromBlock($node, $xpath);
            if ($name === '') {
                continue;
            }

            $offer = $this->parseOfferFromText($text, $blockHtml);
            if ($offer === null) {
                continue;
            }

            $offers[] = [
                'competitor' => $name,
                'price' => $offer['price'],
                'shipping_cost' => $offer['shipping_cost'],
                'total_price' => $offer['total_price'],
                'delivery_status' => $offer['delivery_status'],
            ];
        }

        return $offers;
    }

    private function extractCompetitorNameFromBlock(DOMElement $node, DOMXPath $xpath): string
    {
        $links = $xpath->query('.//a', $node);
        if ($links !== false) {
            foreach ($links as $link) {
                $name = $this->normalizeText($link->textContent ?? '');
                if ($name !== '' && !preg_match('/^(details|mehr|zum shop|bestellen)$/iu', $name)) {
                    return $name;
                }
            }
        }

        $headings = $xpath->query('.//*[self::h2 or self::h3 or self::h4 or self::strong]', $node);
        if ($headings !== false && $headings->length > 0) {
            $name = $this->normalizeText($headings->item(0)?->textContent ?? '');
            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }

    /**
     * @return list<array{competitor:string,price:float,shipping_cost:float,total_price:float,delivery_status:string}>
     */
    private function parseTextOfferBlocks(string $html): array
    {
        $parts = preg_split('/(?=<div[^>]*apotheke|\bGesamtpreis:|\bGünstigster Gesamtpreis)/iu', $html) ?: [];
        if (count($parts) <= 1) {
            $parts = preg_split('/\n{2,}/', strip_tags($html, '<div><span><p><br>')) ?: [];
        }

        $offers = [];
        foreach ($parts as $part) {
            if (!is_string($part) || strlen($part) < 40) {
                continue;
            }

            if (!preg_match(self::PRICE_REGEX, $part)) {
                continue;
            }

            $name = '';
            if (preg_match('/<a[^>]*>([^<]{2,80})<\/a>/iu', $part, $m) === 1) {
                $name = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }

            if ($name === '') {
                continue;
            }

            $offer = $this->parseOfferFromText($this->normalizeText(strip_tags($part)), $part);
            if ($offer === null) {
                continue;
            }

            $offers[] = [
                'competitor' => $name,
                'price' => $offer['price'],
                'shipping_cost' => $offer['shipping_cost'],
                'total_price' => $offer['total_price'],
                'delivery_status' => $offer['delivery_status'],
            ];
        }

        return $offers;
    }

    /**
     * @return array{price:float,shipping_cost:float,total_price:float,delivery_status:string}|null
     */
    private function parseOfferFromText(string $text, string $rawHtml = ''): ?array
    {
        $combined = $text . "\n" . strip_tags($rawHtml);
        $normalized = $this->normalizeText($combined);

        $price = null;
        if (preg_match('/(?<![a-zäöüß])Preis[:\s]*([0-9]+,[0-9]{2})\s*€/iu', $normalized, $m) === 1) {
            $price = $this->parseMoney($m[1]);
        }

        $shipping = null;
        if (preg_match('/versandkostenfrei/iu', $normalized)) {
            $shipping = 0.0;
        } elseif (preg_match('/(?:zzgl\.?\s*)?Versand[:\s]*([0-9]+,[0-9]{2})\s*€/iu', $normalized, $m) === 1) {
            $shipping = $this->parseMoney($m[1]);
        } elseif (preg_match('/\+\s*Versand\s*([0-9]+,[0-9]{2})\s*€/iu', $normalized, $m) === 1) {
            $shipping = $this->parseMoney($m[1]);
        }

        $total = null;
        if (preg_match('/Gesamt(?:preis)?[:\s]*([0-9]+,[0-9]{2})\s*€/iu', $normalized, $m) === 1) {
            $total = $this->parseMoney($m[1]);
        }

        if ($price === null) {
            $singleNode = null;
            if (preg_match('/class=["\'][^"\']*single[^"\']*["\'][^>]*>([^<]+)</iu', $rawHtml, $m) === 1) {
                $price = $this->parseMoney($m[1]);
            } elseif (preg_match_all(self::PRICE_REGEX, $normalized, $all) >= 1) {
                $price = $this->parseMoney($all[1][0]);
            }
        }

        if ($price === null) {
            return null;
        }

        if ($shipping === null) {
            if (preg_match('/class=["\'][^"\']*shipping[^"\']*["\'][^>]*>([^<]+)</iu', $rawHtml, $m) === 1) {
                $shipText = $m[1];
                if (preg_match('/versandkostenfrei/iu', $shipText)) {
                    $shipping = 0.0;
                } elseif (preg_match(self::PRICE_REGEX, $shipText, $sm) === 1) {
                    $shipping = $this->parseMoney($sm[1]);
                }
            }
        }

        if ($shipping === null) {
            $shipping = 0.0;
        }

        if ($total === null) {
            $total = round($price + $shipping, 2);
        }

        if ($shipping === 0.0 && $total > $price + 0.001) {
            $shipping = round($total - $price, 2);
        } elseif ($total < $price) {
            $total = round($price + $shipping, 2);
        }

        $status = 'lieferbar';
        if (preg_match('/Lieferzeit[:\s]*([0-9][0-9\s\-–]+(?:Werktage?|Stunden|Tage))/iu', $normalized, $m) === 1) {
            $status = trim($m[1]);
        } elseif (preg_match('/(nicht lieferbar|derzeit nicht|ausverkauft)/iu', $normalized, $m) === 1) {
            $status = $m[1];
        }

        return [
            'price' => $price,
            'shipping_cost' => $shipping,
            'total_price' => $total,
            'delivery_status' => $status,
        ];
    }

    /**
     * @param list<array{competitor:string,price:float,shipping_cost:float,total_price?:float,delivery_status:string}> $offers
     * @return list<array{competitor:string,price:float,shipping_cost:float,total_price:float,delivery_status:string}>
     */
    private function deduplicateOffers(array $offers): array
    {
        $seen = [];
        $unique = [];

        foreach ($offers as $offer) {
            $key = strtolower(trim($offer['competitor']));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = [
                'competitor' => $offer['competitor'],
                'price' => $offer['price'],
                'shipping_cost' => $offer['shipping_cost'],
                'total_price' => $offer['total_price'] ?? round($offer['price'] + $offer['shipping_cost'], 2),
                'delivery_status' => $offer['delivery_status'],
            ];
        }

        return $unique;
    }

    /**
     * @param list<array{competitor:string,price:float,shipping_cost:float,total_price?:float,delivery_status:string}> $offers
     */
    private function buildDebugFromOffers(array $offers): void
    {
        $this->lastOffersDebug = [];
        foreach ($offers as $offer) {
            $this->lastOffersDebug[] = [
                'competitor_name' => $offer['competitor'],
                'price' => $offer['price'],
                'shipping_cost' => $offer['shipping_cost'],
                'total_price' => $offer['total_price'] ?? round($offer['price'] + $offer['shipping_cost'], 2),
                'delivery_status' => $offer['delivery_status'],
            ];
        }
    }

    private function normalizeText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function parseMoney(string $value): ?float
    {
        if (preg_match(self::PRICE_REGEX, $value, $m) === 1) {
            $value = $m[1];
        }

        $normalized = str_replace(['€', ' '], '', trim($value));
        $normalized = str_replace(',', '.', $normalized);
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 2);
    }
}
