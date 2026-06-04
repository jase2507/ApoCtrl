<?php

declare(strict_types=1);

class ShopSearchParser
{
    public function __construct(
        private readonly ShopUrlValidator $urlValidator,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * @return list<array{pzn:string,name:string,price:?float,url:string}>
     */
    public function parseSearchResults(string $html, string $searchPzn): array
    {
        $needle = ShopHtmlParser::normalizePzn($searchPzn);
        $short = ltrim($needle, '0');

        $hits = $this->parseModernProductBoxes($html, $needle, $short);
        if ($hits !== []) {
            return $this->deduplicateHits($hits);
        }

        $pattern = '/href=["\']([^"\']+)["\'][\s\S]*?Anbieter:\s*(.+?)\s+Einheit:\s*(.+?)\s+PZN:\s*(?:0*)?'
            . preg_quote($short, '/')
            . '\b[\s\S]*?(?:Ihr Preis:\s*([\d.,]+)\s*€)?/iu';

        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER) >= 1) {
            foreach ($matches as $match) {
                $hit = $this->buildHit(
                    (string) $match[1],
                    (string) $match[2],
                    (string) $match[3],
                    $needle,
                    isset($match[4]) ? (string) $match[4] : null,
                    null,
                );
                if ($hit !== null) {
                    $hits[] = $hit;
                }
            }
        }

        if ($hits === []) {
            $hits = $this->parseFromTextBlocks($html, $needle, $short);
        }

        return $this->deduplicateHits($hits);
    }

    /**
     * @return list<array{pzn:string,name:string,price:?float,url:string}>
     */
    private function parseModernProductBoxes(string $html, string $needle, string $short): array
    {
        $hits = [];
        $pattern = '/<div[^>]+class=["\'][^"\']*boxProduct(?:Detail)?[^"\']*["\'][^>]*(?:id=["\']product-(?:box-)?(\d+)["\'])?[\s\S]*?(?=<div[^>]+class=["\'][^"\']*boxProduct|<\/body|$)/iu';

        if (preg_match_all($pattern, $html, $blocks, PREG_SET_ORDER) < 1) {
            $pattern = '/id=["\']product-box-(\d+)["\'][\s\S]*?(?=<div[^>]+id=["\']product-box-|$)/iu';
            if (preg_match_all($pattern, $html, $blocks, PREG_SET_ORDER) < 1) {
                return [];
            }
        }

        foreach ($blocks as $blockMatch) {
            $block = (string) ($blockMatch[0] ?? '');
            $blockPzn = $this->extractPznFromBlock($block);
            if ($blockPzn === null) {
                continue;
            }

            $normalized = ShopHtmlParser::normalizePzn($blockPzn);
            if ($normalized !== $needle && ltrim($normalized, '0') !== $short) {
                continue;
            }

            $url = $this->extractProductUrlFromBlock($block);
            if ($url === null) {
                continue;
            }

            $name = $this->extractTitleFromBlock($block);
            $manufacturer = $this->matchDd($block, 'producer');
            $packageSize = $this->normalizePackage(
                $this->matchDd($block, 'form') ?? $this->matchDd($block, 'quantity')
            );
            if ($name === null || $name === '') {
                $name = trim(($manufacturer ?? '') . ' ' . ($packageSize ?? ''));
            }

            $priceRaw = $this->matchPrice($block, 'yourPrice');
            $hit = $this->buildHit($url, (string) $manufacturer, (string) $packageSize, $needle, $priceRaw, $name);
            if ($hit !== null) {
                $hits[] = $hit;
            }
        }

        return $hits;
    }

    private function extractPznFromBlock(string $block): ?string
    {
        if (preg_match('/<dd[^>]*class=["\']pzn["\'][^>]*>\s*(\d{7,8})\s*<\/dd>/iu', $block, $match) === 1) {
            return (string) ($match[1] ?? '');
        }

        if (preg_match('/itemprop=["\']sku["\'][^>]+content=["\'](\d{7,8})["\']/iu', $block, $match) === 1) {
            return (string) ($match[1] ?? '');
        }

        if (preg_match('/id=["\']product-box-(\d{7,8})["\']/iu', $block, $match) === 1) {
            return (string) ($match[1] ?? '');
        }

        return null;
    }

    private function extractProductUrlFromBlock(string $block): ?string
    {
        if (preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/iu', $block, $links) < 1) {
            return null;
        }

        foreach ($links[1] as $href) {
            $absolute = $this->resolveUrl((string) $href);
            if ($absolute === null || !$this->urlValidator->isAllowed($absolute)) {
                continue;
            }

            if (
                preg_match('#/(bite-|artikel/|[^/]+-\d{7,8})(?:\?|$)#i', $absolute) === 1
                || stripos($absolute, 'shop.apotheker-seidel.de/') !== false
            ) {
                if (stripos($absolute, '/search') === false && stripos($absolute, '/additem') === false) {
                    return $absolute;
                }
            }
        }

        return null;
    }

    private function extractTitleFromBlock(string $block): ?string
    {
        $patterns = [
            '/<h3[^>]*>[\s\S]*?<a[^>]*>([\s\S]*?)<\/a>/iu',
            '/<h1[^>]*itemprop=["\']name["\'][^>]*>([\s\S]*?)<\/h1>/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $block, $match) !== 1) {
                continue;
            }

            $text = preg_replace('/<span[^>]*>([\s\S]*?)<\/span>/iu', ' $1 ', (string) ($match[1] ?? '')) ?? '';
            $text = strip_tags($text);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
            $text = trim($text);

            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    private function matchDd(string $block, string $class): ?string
    {
        if (preg_match('/<dd[^>]*class=["\']' . preg_quote($class, '/') . '["\'][^>]*>([\s\S]*?)<\/dd>/iu', $block, $match) !== 1) {
            return null;
        }

        $text = strip_tags((string) ($match[1] ?? ''));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text) !== '' ? trim($text) : null;
    }

    private function matchPrice(string $block, string $class): ?string
    {
        if (preg_match('/<dd[^>]*class=["\']' . preg_quote($class, '/') . '["\'][^>]*>[\s\S]*?([\d.,]+)\s*€/iu', $block, $match) !== 1) {
            return null;
        }

        return trim((string) ($match[1] ?? ''));
    }

    private function normalizePackage(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(trim($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    /**
     * @return list<array{pzn:string,name:string,price:?float,url:string}>
     */
    private function parseFromTextBlocks(string $html, string $needle, string $short): array
    {
        $text = $this->htmlToText($html);
        $hits = [];
        $offset = 0;

        while (preg_match('/Anbieter:/i', $text, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = (int) $match[0][1];
            $next = stripos($text, 'Anbieter:', $start + 9);
            $block = $next !== false
                ? substr($text, $start, $next - $start)
                : substr($text, $start);

            if (
                preg_match('/PZN:\s*' . preg_quote($needle, '/') . '\b/i', $block) !== 1
                && preg_match('/PZN:\s*0*' . preg_quote($short, '/') . '\b/i', $block) !== 1
            ) {
                $offset = $start + 9;
                if ($next === false) {
                    break;
                }
                continue;
            }

            $manufacturer = $this->matchGroup($block, '/Anbieter:\s*(.+?)\s+Einheit:/isu');
            $packageSize = $this->matchGroup($block, '/Einheit:\s*(.+?)\s+PZN:/isu');
            $priceRaw = $this->matchGroup($block, '/Ihr Preis:\s*([\d.,]+)\s*€/iu');
            $url = $this->findProductUrlNear($html, $needle, $short);
            $title = $this->extractTitleFromBlock($html);

            if ($url !== null) {
                $hit = $this->buildHit(
                    $url,
                    (string) $manufacturer,
                    (string) $packageSize,
                    $needle,
                    $priceRaw,
                    $title,
                );
                if ($hit !== null) {
                    $hits[] = $hit;
                }
            }

            $offset = $start + 9;
            if ($next === false) {
                break;
            }
        }

        return $hits;
    }

    /**
     * @return array{pzn:string,name:string,price:?float,url:string}|null
     */
    private function buildHit(
        string $href,
        string $manufacturer,
        string $packageSize,
        string $pzn,
        ?string $priceRaw,
        ?string $title,
    ): ?array {
        $absolute = $this->resolveUrl($href);
        if ($absolute === null || !$this->urlValidator->isAllowed($absolute)) {
            return null;
        }

        $name = $title !== null && trim($title) !== ''
            ? trim($title)
            : trim($manufacturer . ' ' . $packageSize);
        if ($name === '') {
            $name = 'PZN ' . $pzn;
        }

        return [
            'pzn' => $pzn,
            'name' => $name,
            'price' => $priceRaw !== null && $priceRaw !== '' ? ShopHtmlParser::parseGermanPrice($priceRaw) : null,
            'url' => $absolute,
        ];
    }

    private function findProductUrlNear(string $html, string $needle, string $short): ?string
    {
        $positions = [];
        if (preg_match('/PZN:\s*' . preg_quote($needle, '/') . '\b/i', $html, $m, PREG_OFFSET_CAPTURE) === 1) {
            $positions[] = (int) $m[0][1];
        }
        if (preg_match('/PZN:\s*0*' . preg_quote($short, '/') . '\b/i', $html, $m, PREG_OFFSET_CAPTURE) === 1) {
            $positions[] = (int) $m[0][1];
        }
        if (preg_match('/<dd[^>]*class=["\']pzn["\'][^>]*>\s*' . preg_quote($needle, '/') . '\s*<\/dd>/iu', $html, $m, PREG_OFFSET_CAPTURE) === 1) {
            $positions[] = (int) $m[0][1];
        }

        foreach ($positions as $pos) {
            $snippet = substr($html, max(0, $pos - 2500), 2500);
            if (preg_match_all('/href=["\']([^"\']+)["\']/i', $snippet, $links) < 1) {
                continue;
            }

            foreach (array_reverse($links[1]) as $href) {
                $absolute = $this->resolveUrl((string) $href);
                if ($absolute !== null && $this->urlValidator->isAllowed($absolute)) {
                    return $absolute;
                }
            }
        }

        return null;
    }

    private function resolveUrl(string $href): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($href === '' || str_starts_with($href, '#') || stripos($href, 'javascript:') === 0) {
            return null;
        }

        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }

        return rtrim($this->baseUrl, '/') . '/' . ltrim($href, '/');
    }

    /**
     * @param list<array{pzn:string,name:string,price:?float,url:string}> $hits
     * @return list<array{pzn:string,name:string,price:?float,url:string}>
     */
    private function deduplicateHits(array $hits): array
    {
        $unique = [];
        foreach ($hits as $hit) {
            $unique[$hit['url'] . '|' . $hit['name']] = $hit;
        }

        return array_values($unique);
    }

    private function htmlToText(string $html): string
    {
        $normalized = preg_replace('/<(br|BR)\s*\/?>/', "\n", $html) ?? $html;
        $normalized = preg_replace('/<\/(p|div|li|h1|h2|h3|td|tr)>/i', "\n", $normalized) ?? $normalized;
        $text = strip_tags($normalized);

        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function matchGroup(string $haystack, string $pattern): ?string
    {
        if (preg_match($pattern, $haystack, $matches) !== 1) {
            return null;
        }

        return trim((string) ($matches[1] ?? ''));
    }
}
