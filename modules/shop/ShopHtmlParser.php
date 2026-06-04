<?php

declare(strict_types=1);

require_once __DIR__ . '/PznMatchGuard.php';

class ShopHtmlParser
{
    /**
     * @return array{
     *   product_name:?string,
     *   manufacturer:?string,
     *   package_size:?string,
     *   pzn:?string,
     *   price:?float,
     *   avp_price:?float,
     *   delivery_status:?string
     * }
     */
    public function parse(string $html, ?string $expectedPzn = null): array
    {
        if ($expectedPzn !== null && $expectedPzn !== '') {
            $detail = $this->parseProductDetailPage($html, $expectedPzn);
            if ($detail !== null) {
                return $this->finalizeParsed($detail, $expectedPzn);
            }
        }

        if ($this->hasModernMarkup($html)) {
            $modern = $this->parseModern($html, $expectedPzn);
            if ($modern !== null) {
                return $this->finalizeParsed($modern, $expectedPzn);
            }
        }

        $text = $this->htmlToText($html);
        $block = $this->extractProductBlock($text, $expectedPzn);

        if ($block === null) {
            throw new RuntimeException(
                $expectedPzn !== null
                    ? 'Produkt mit PZN ' . $expectedPzn . ' wurde auf der Shop-Seite nicht gefunden.'
                    : 'Kein Produktdatenblock auf der Shop-Seite erkannt.'
            );
        }

        $manufacturer = $this->matchGroup($block, '/Anbieter:\s*(.+?)\s+Einheit:/isu');
        $packageSize = $this->matchGroup($block, '/Einheit:\s*(.+?)\s+PZN:/isu');
        $pzn = $this->matchGroup($block, '/PZN:\s*(\d{7,8})/i');
        $availabilityRaw = $this->matchGroup($block, '/Verfügbarkeit:\s*(.+?)(?:\n|Ihr Preis:|$)/isu');
        $priceRaw = $this->matchGroup($block, '/Ihr Preis:\s*([\d.,]+)\s*€/iu');
        $avpRaw = $this->matchGroup($block, '/(?:AVP|UVP):\s*([\d.,]+)\s*€/iu');

        $productName = null;
        if ($manufacturer !== null && $packageSize !== null) {
            $productName = trim($manufacturer . ' ' . $packageSize);
        } elseif ($packageSize !== null) {
            $productName = $packageSize;
        }

        return $this->finalizeParsed([
            'product_name' => $productName,
            'manufacturer' => $manufacturer,
            'package_size' => $packageSize,
            'pzn' => $pzn !== null ? self::normalizePzn($pzn) : null,
            'price' => $priceRaw !== null ? self::parseGermanPrice($priceRaw) : null,
            'avp_price' => $avpRaw !== null ? self::parseGermanPrice($avpRaw) : null,
            'delivery_status' => $this->mapAvailability($availabilityRaw ?? $block),
        ], $expectedPzn);
    }

    /**
     * @param array{
     *   product_name:?string,
     *   manufacturer:?string,
     *   package_size:?string,
     *   pzn:?string,
     *   price:?float,
     *   avp_price:?float,
     *   delivery_status:?string
     * } $parsed
     * @return array{
     *   product_name:?string,
     *   manufacturer:?string,
     *   package_size:?string,
     *   pzn:?string,
     *   price:?float,
     *   avp_price:?float,
     *   delivery_status:?string
     * }
     */
    private function finalizeParsed(array $parsed, ?string $expectedPzn): array
    {
        if ($expectedPzn === null || trim($expectedPzn) === '') {
            return $parsed;
        }

        $check = PznMatchGuard::validateParsedProduct($parsed, $expectedPzn);
        if (!$check['ok']) {
            throw new RuntimeException((string) $check['message']);
        }

        $parsed['pzn'] = $check['requested_pzn'];

        return $parsed;
    }

    private function hasModernMarkup(string $html): bool
    {
        return stripos($html, 'class="productInfos"') !== false
            || stripos($html, "class='productInfos'") !== false;
    }

    /**
     * @return array{
     *   product_name:?string,
     *   manufacturer:?string,
     *   package_size:?string,
     *   pzn:?string,
     *   price:?float,
     *   avp_price:?float,
     *   delivery_status:?string
     * }|null
     */
    private function parseModern(string $html, ?string $expectedPzn): ?array
    {
        $needle = $expectedPzn !== null && $expectedPzn !== ''
            ? self::normalizePzn($expectedPzn)
            : null;

        $block = $needle !== null ? $this->extractModernBlockForPzn($html, $needle) : null;
        if ($block === null && $needle === null) {
            $block = $this->extractFirstModernBlock($html);
        }

        if ($block === null || $block === '') {
            return null;
        }

        $pzn = $this->matchModernDd($block, 'pzn') ?? $needle;
        if ($pzn !== null) {
            $pzn = self::normalizePzn($pzn);
        }

        $manufacturer = $this->matchModernDd($block, 'producer');
        if ($manufacturer === null) {
            $manufacturer = $this->matchGroup(
                $block,
                '/<span[^>]*itemprop=["\']brand["\'][^>]*>([^<]+)</iu'
            );
        }

        $packageSize = $this->normalizePackageSize(
            $this->matchModernDd($block, 'form')
                ?? $this->matchModernDd($block, 'quantity')
        );

        $productName = $this->extractModernProductName($block);
        if ($productName === null && $manufacturer !== null && $packageSize !== null) {
            $productName = trim($manufacturer . ' ' . $packageSize);
        }

        $priceRaw = $this->matchModernPrice($block, 'yourPrice');
        $avpRaw = $this->matchModernPrice($block, 'listPrice');
        $availabilityRaw = $this->matchModernAvailability($block);

        if ($needle !== null && ($pzn === null || !PznMatchGuard::matches($needle, $pzn))) {
            return null;
        }

        return [
            'product_name' => $productName,
            'manufacturer' => $manufacturer,
            'package_size' => $packageSize,
            'pzn' => $pzn,
            'price' => $priceRaw !== null ? self::parseGermanPrice($priceRaw) : null,
            'avp_price' => $avpRaw !== null ? self::parseGermanPrice($avpRaw) : null,
            'delivery_status' => $this->mapAvailability($availabilityRaw ?? $block),
        ];
    }

    /**
     * @return array{
     *   product_name:?string,
     *   manufacturer:?string,
     *   package_size:?string,
     *   pzn:?string,
     *   price:?float,
     *   avp_price:?float,
     *   delivery_status:?string
     * }|null
     */
    private function parseProductDetailPage(string $html, string $expectedPzn): ?array
    {
        $needle = self::normalizePzn($expectedPzn);
        $block = $this->extractBoxProductDetailBlock($html, $needle);
        if ($block === null) {
            return null;
        }

        $pzn = $this->matchModernDd($block, 'pzn');
        if ($pzn === null && preg_match('/itemprop=["\']sku["\'][^>]+content=["\'](\d{7,8})["\']/iu', $block, $sku) === 1) {
            $pzn = (string) ($sku[1] ?? '');
        }
        if ($pzn === null && preg_match('/itemprop=["\']productID["\'][^>]*>\s*(\d{7,8})\s*</iu', $block, $pid) === 1) {
            $pzn = (string) ($pid[1] ?? '');
        }

        if ($pzn === null) {
            return null;
        }

        $pzn = self::normalizePzn($pzn);
        if (!PznMatchGuard::matches($needle, $pzn)) {
            return null;
        }

        $manufacturer = $this->matchModernDd($block, 'producer');
        if ($manufacturer === null) {
            $manufacturer = $this->matchProducerInfoBrand($block);
        }

        $packageSize = $this->normalizePackageSize(
            $this->extractDetailPackageSize($block)
                ?? $this->matchModernDd($block, 'form')
                ?? $this->matchModernDd($block, 'quantity')
        );

        $productName = $this->extractModernProductName($block);
        if ($productName === null) {
            $productName = $this->matchGroup(
                $this->htmlToText($block),
                '/^(.+?)\s+Anbieter:/imu'
            );
        }

        $priceBlock = $this->extractCurrentVariantPriceBlock($block, $needle) ?? $block;
        $priceRaw = $this->matchModernPrice($priceBlock, 'yourPrice');
        $avpRaw = $this->matchModernPrice($priceBlock, 'listPrice');
        $availabilityRaw = $this->matchModernAvailability($block);

        if ($pzn !== null && $needle !== null && !PznMatchGuard::matches($needle, $pzn)) {
            return null;
        }

        return [
            'product_name' => $productName,
            'manufacturer' => $manufacturer,
            'package_size' => $packageSize,
            'pzn' => $pzn,
            'price' => $priceRaw !== null ? self::parseGermanPrice($priceRaw) : null,
            'avp_price' => $avpRaw !== null ? self::parseGermanPrice($avpRaw) : null,
            'delivery_status' => $this->mapAvailability($availabilityRaw ?? $block),
        ];
    }

    private function extractBoxProductDetailBlock(string $html, string $needle): ?string
    {
        $pattern = '/<div[^>]+class=["\'][^"\']*boxProductDetail[^"\']*["\'][^>]*id=["\']product-'
            . preg_quote($needle, '/')
            . '["\'][\s\S]*?(?=<\/div>\s*<\/div>\s*<div[^>]+class=["\']boxContentFooter|<footer|<\/body|$)/iu';

        if (preg_match($pattern, $html, $match) === 1) {
            return (string) $match[0];
        }

        return $this->extractModernBlockForPzn($html, $needle);
    }

    private function extractDetailPackageSize(string $block): ?string
    {
        if (preg_match(
            '/<div[^>]+class=["\']productdetail-mid["\'][\s\S]*?<dl[^>]+class=["\']productInfos["\'][\s\S]*?<dd[^>]+class=["\']form["\'][^>]*>([\s\S]*?)<\/dd>/iu',
            $block,
            $match
        ) === 1) {
            return $this->normalizePackageSize((string) ($match[1] ?? ''));
        }

        return null;
    }

    private function matchProducerInfoBrand(string $block): ?string
    {
        if (preg_match(
            '/<div[^>]+class=["\']producer-info["\'][\s\S]*?<span[^>]+itemprop=["\']brand["\'][^>]*>([^<]+)</iu',
            $block,
            $match
        ) === 1) {
            return trim(html_entity_decode((string) ($match[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        if (preg_match('/Anbieter:\s*<\/span>\s*<span[^>]*>([^<]+)</iu', $block, $match) === 1) {
            return trim(html_entity_decode((string) ($match[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return null;
    }

    private function extractCurrentVariantPriceBlock(string $block, string $needle): ?string
    {
        if (preg_match(
            '/<div[^>]+class=["\'][^"\']*similarProduct[^"\']*current[^"\']*["\'][\s\S]*?itemprop=["\']sku["\'][^>]+content=["\']'
            . preg_quote($needle, '/')
            . '["\'][\s\S]*?<dl[^>]+class=["\']productPrice["\'][\s\S]*?<\/dl>/iu',
            $block,
            $match
        ) === 1) {
            return (string) $match[0];
        }

        if (preg_match_all(
            '/<div[^>]+class=["\'][^"\']*similarProduct[^"\']*current[^"\']*["\'][\s\S]*?(?=<div[^>]+class=["\']similarProduct|$)/iu',
            $block,
            $matches
        ) >= 1) {
            foreach ($matches[0] as $variant) {
                if (preg_match('/itemprop=["\']sku["\'][^>]+content=["\']' . preg_quote($needle, '/') . '["\']/iu', $variant) === 1) {
                    return (string) $variant;
                }
            }
        }

        if (preg_match(
            '/add_product_id["\'][^>]+value=["\']' . preg_quote($needle, '/')
            . '["\'][\s\S]{0,5000}<dl[^>]+class=["\']productPrice["\'][\s\S]*?<\/dl>/iu',
            $block,
            $match
        ) === 1) {
            return (string) $match[0];
        }

        if (preg_match(
            '/-' . preg_quote($needle, '/')
            . '["\'][\s\S]{0,20000}<dl[^>]+class=["\']productPrice["\'][\s\S]*?<\/dl>/iu',
            $block,
            $match
        ) === 1) {
            return (string) $match[0];
        }

        return null;
    }

    private function extractModernBlockForPzn(string $html, string $needle): ?string
    {
        $short = ltrim($needle, '0');
        $patterns = [
            '/id=["\']product-box-' . preg_quote($needle, '/') . '["\'][\s\S]*?(?=<div[^>]+id=["\']product-box-|<\/body|$)/iu',
            '/id=["\']product-' . preg_quote($needle, '/') . '["\'][\s\S]*?(?=<footer|<\/body|$)/iu',
            '/<dd[^>]*class=["\']pzn["\'][^>]*>\s*' . preg_quote($needle, '/') . '\s*<\/dd>/iu',
            '/<dd[^>]*class=["\']pzn["\'][^>]*>\s*0*' . preg_quote($short, '/') . '\s*<\/dd>/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $match, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $pos = (int) $match[0][1];
            $start = max(0, $pos - 3500);
            $length = min(strlen($html) - $start, 9000);

            return substr($html, $start, $length);
        }

        return null;
    }

    private function extractFirstModernBlock(string $html): ?string
    {
        if (preg_match('/<div[^>]+class=["\'][^"\']*boxProduct[^"\']*["\'][\s\S]{200,8000}/iu', $html, $match) === 1) {
            return (string) $match[0];
        }

        if (preg_match('/<div[^>]+class=["\'][^"\']*boxProductDetail[^"\']*["\'][\s\S]{200,12000}/iu', $html, $match) === 1) {
            return (string) $match[0];
        }

        return null;
    }

    private function extractModernProductName(string $block): ?string
    {
        $patterns = [
            '/<h1[^>]*itemprop=["\']name["\'][^>]*>([\s\S]*?)<\/h1>/iu',
            '/<h3[^>]*>\s*<a[^>]*itemprop=["\']url["\'][^>]*>([\s\S]*?)<\/a>/iu',
            '/<meta[^>]+itemprop=["\']name["\'][^>]+content=["\']([^"\']+)["\']/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $block, $match) !== 1) {
                continue;
            }

            $name = $this->flattenTitleHtml((string) ($match[1] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }

    private function flattenTitleHtml(string $html): string
    {
        $text = preg_replace('/<span[^>]*>([\s\S]*?)<\/span>/iu', ' $1 ', $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function matchModernDd(string $block, string $class): ?string
    {
        $pattern = '/<dd[^>]*class=["\']' . preg_quote($class, '/') . '["\'][^>]*>([\s\S]*?)<\/dd>/iu';
        if (preg_match($pattern, $block, $match) !== 1) {
            return null;
        }

        $text = strip_tags((string) ($match[1] ?? ''));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text) !== '' ? trim($text) : null;
    }

    private function matchModernPrice(string $block, string $class): ?string
    {
        $pattern = '/<dd[^>]*class=["\']' . preg_quote($class, '/') . '["\'][^>]*>[\s\S]*?([\d.,]+)\s*€/iu';
        if (preg_match($pattern, $block, $match) !== 1) {
            return null;
        }

        return trim((string) ($match[1] ?? ''));
    }

    private function matchModernAvailability(string $block): ?string
    {
        if (preg_match('/<dd[^>]*class=["\']status[^"\']*status1[^"\']*["\'][^>]*>[\s\S]*?<span[^>]*>([^<]+)</iu', $block, $match) === 1) {
            return trim(html_entity_decode((string) ($match[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        if (preg_match('/itemprop=["\']availability["\'][^>]+content=["\'][^"\']*InStock[^"\']*["\']/i', $block) === 1) {
            return 'vorrätig, sofort lieferbar.';
        }

        return null;
    }

    private function normalizePackageSize(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $text = strip_tags($raw);
        $text = html_entity_decode(trim($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function htmlToText(string $html): string
    {
        $normalized = preg_replace('/<(br|BR)\s*\/?>/', "\n", $html) ?? $html;
        $normalized = preg_replace('/<\/(p|div|li|h1|h2|h3|td|tr)>/i', "\n", $normalized) ?? $normalized;
        $text = strip_tags($normalized);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\r\n|\r/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function extractProductBlock(string $text, ?string $expectedPzn): ?string
    {
        if ($expectedPzn !== null && $expectedPzn !== '') {
            $needle = self::normalizePzn($expectedPzn);
            $pattern = '/PZN:\s*' . preg_quote($needle, '/') . '\b/is';

            if (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
                $short = ltrim($needle, '0');
                $pattern = '/PZN:\s*0*' . preg_quote($short, '/') . '\b/is';
                if (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
                    return null;
                }
            }

            $pos = (int) $match[0][1];
            $start = max(0, (int) strrpos(substr($text, 0, $pos), 'Anbieter:'));
            $after = substr($text, $pos);
            $nextAnbieter = strpos($after, "\nAnbieter:");
            $length = $nextAnbieter !== false ? $pos + $nextAnbieter - $start : strlen($text) - $start;

            return trim(substr($text, $start, $length));
        }

        if (preg_match('/Anbieter:.+?PZN:\s*\d{7,8}.+/is', $text, $block) === 1) {
            return trim($block[0]);
        }

        return null;
    }

    private function mapAvailability(string $raw): ?string
    {
        if (stripos($raw, 'nicht lieferbar') !== false || stripos($raw, 'leider nicht') !== false) {
            return 'nicht lieferbar';
        }

        if (stripos($raw, 'lieferbereit') !== false || stripos($raw, 'begrenzt') !== false) {
            return 'begrenzt';
        }

        if (
            stripos($raw, 'vorrätig') !== false
            || stripos($raw, 'sofort lieferbar') !== false
            || stripos($raw, 'lieferbar') !== false
        ) {
            return 'lieferbar';
        }

        return null;
    }

    private function matchGroup(string $haystack, string $pattern): ?string
    {
        if (preg_match($pattern, $haystack, $matches) !== 1) {
            return null;
        }

        return trim((string) ($matches[1] ?? ''));
    }

    public static function normalizePzn(string $pzn): string
    {
        $digits = preg_replace('/\D/', '', $pzn) ?? '';

        if ($digits === '') {
            return $pzn;
        }

        return str_pad($digits, 8, '0', STR_PAD_LEFT);
    }

    public static function parseGermanPrice(string $raw): float
    {
        $normalized = str_replace([' ', '€'], '', $raw);
        $normalized = str_replace('.', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);

        return (float) $normalized;
    }
}
