<?php

declare(strict_types=1);

class OwnShopFeedLookupService
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $index = null;

    /** @var array{ok:bool,from_cache:bool,warning:?string,error:?string,http_code:?int,row_count:int} */
    private array $lastLoad = [
        'ok' => false,
        'from_cache' => false,
        'warning' => null,
        'error' => null,
        'http_code' => null,
        'row_count' => 0,
    ];

    /** @var array<string, mixed> */
    private array $lastResolveDebug = [];

    public function __construct(
        private readonly OwnShopFeedFetcher $fetcher,
        private readonly OwnShopFeedCache $cache,
        private readonly OwnShopFeedParser $parser,
        private readonly ShopUrlValidator $urlValidator,
        private readonly ShopFetcher $pageFetcher,
        private readonly ShopHtmlParser $htmlParser,
        private readonly string $feedUrl,
        private readonly string $feedLastUpdateUrl,
        private readonly string $deeplinkTemplate,
    ) {
    }

    /**
     * @return array{ok:bool,from_cache:bool,warning:?string,error:?string,http_code:?int,row_count:int}
     */
    public function loadFeed(?string $csvOverride = null, ?string $lastUpdateOverride = null): array
    {
        if ($csvOverride !== null) {
            $this->index = $this->parser->parseIndex($csvOverride);
            $lastUpdateToStore = $lastUpdateOverride ?? $this->cache->readCachedLastUpdate() ?? date('Y-m-d H:i:s');
            $this->cache->writeCache($csvOverride, $lastUpdateToStore);
            $this->lastLoad = [
                'ok' => true,
                'from_cache' => false,
                'warning' => null,
                'error' => null,
                'http_code' => null,
                'row_count' => count($this->index),
            ];

            return $this->lastLoad;
        }

        $this->assertAllowedUrl($this->feedUrl);
        $this->assertAllowedUrl($this->feedLastUpdateUrl);

        $cachedLastUpdate = $this->cache->readCachedLastUpdate();
        $remoteLastUpdate = $lastUpdateOverride;

        if ($remoteLastUpdate === null) {
            $lastUpdateFetch = $this->fetcher->fetch($this->feedLastUpdateUrl);
            $remoteLastUpdate = $lastUpdateFetch['ok']
                ? trim((string) $lastUpdateFetch['body'])
                : null;
        }

        $useCacheOnly = $remoteLastUpdate !== null
            && $cachedLastUpdate !== null
            && $remoteLastUpdate === $cachedLastUpdate
            && $this->cache->hasCachedFeed();

        if ($useCacheOnly) {
            return $this->loadFromCache(null);
        }

        $feedFetch = $this->fetcher->fetch($this->feedUrl);
        if ($feedFetch['ok'] && $feedFetch['body'] !== null) {
            $csv = (string) $feedFetch['body'];
            $this->index = $this->parser->parseIndex($csv);
            $lastUpdateToStore = $remoteLastUpdate ?? date('Y-m-d H:i:s');
            $this->cache->writeCache($csv, $lastUpdateToStore);
            $this->lastLoad = [
                'ok' => true,
                'from_cache' => false,
                'warning' => null,
                'error' => null,
                'http_code' => $feedFetch['http_code'] ?? null,
                'row_count' => count($this->index),
            ];

            return $this->lastLoad;
        }

        if ($this->cache->hasCachedFeed()) {
            return $this->loadFromCache(
                'Feed konnte nicht online geladen werden – zwischengespeicherter Stand wird verwendet.'
                . ($feedFetch['error'] !== null ? ' (' . $feedFetch['error'] . ')' : ''),
                $feedFetch['http_code'] ?? null,
            );
        }

        $this->index = [];
        $this->lastLoad = [
            'ok' => false,
            'from_cache' => false,
            'warning' => null,
            'error' => (string) ($feedFetch['error'] ?? 'Feed konnte nicht geladen werden.'),
            'http_code' => $feedFetch['http_code'] ?? null,
            'row_count' => 0,
        ];

        return $this->lastLoad;
    }

    /**
     * Feed (Preis/Existenz) + Produktseite (Stammdaten) kombinieren.
     *
     * @return array{
     *   status:string,
     *   message:string,
     *   feed_found:bool,
     *   feed_price:?float,
     *   product_url:string,
     *   page_http_code:?int,
     *   page_ok:bool,
     *   parsed:?array,
     *   hit:?array,
     *   allow_feed_snapshot:bool
     * }
     */
    public function resolvePzn(string $pzn, ?string $productHtmlOverride = null, ?string $feedCsvOverride = null, ?string $feedLastUpdateOverride = null): array
    {
        $normalized = ShopHtmlParser::normalizePzn(trim($pzn));
        $productUrl = $this->buildDeeplink($normalized);

        $pageResult = $this->fetchProductPage($productUrl, $normalized, $productHtmlOverride);
        $pageParsed = $pageResult['parsed'];
        $pageOk = $pageResult['ok'] && is_array($pageParsed);

        $feedState = $this->loadFeedState($feedCsvOverride, $feedLastUpdateOverride, $normalized);
        $feedReachable = $feedState['reachable'];
        $feedFound = $feedState['found'];
        $feedPrice = $feedState['price'];

        $this->lastResolveDebug = array_merge(
            [
                'source' => 'page+feed',
                'pzn' => $normalized,
                'feed_url' => $this->feedUrl,
                'feed_reachable' => $feedReachable,
                'feed_found' => $feedFound,
                'feed_price' => $feedPrice,
                'from_cache' => $feedState['from_cache'],
                'product_page_url' => $productUrl,
                'page_ok' => $pageOk,
                'parsed_product_name' => is_array($pageParsed) ? ($pageParsed['product_name'] ?? null) : null,
                'parsed_manufacturer' => is_array($pageParsed) ? ($pageParsed['manufacturer'] ?? null) : null,
                'parsed_package_size' => is_array($pageParsed) ? ($pageParsed['package_size'] ?? null) : null,
                'parsed_price' => is_array($pageParsed) ? ($pageParsed['price'] ?? null) : null,
                'parsed_avp' => is_array($pageParsed) ? ($pageParsed['avp_price'] ?? null) : null,
                'merged_price' => null,
                'warning' => $feedState['hint'],
            ],
            $pageResult['diagnostics'] ?? [],
        );

        if (!$pageOk) {
            return [
                'status' => 'error',
                'message' => 'Produktseite konnte nicht abgerufen werden.'
                    . ($pageResult['error'] !== null ? ' ' . $pageResult['error'] : ''),
                'feed_found' => $feedFound,
                'feed_price' => $feedPrice,
                'product_url' => $productUrl,
                'page_http_code' => $pageResult['http_code'],
                'page_ok' => false,
                'parsed' => null,
                'hit' => null,
                'allow_feed_snapshot' => false,
            ];
        }

        $merged = $this->mergePageWithOptionalFeed($pageParsed, $feedPrice, $normalized);
        $this->lastResolveDebug['merged_price'] = $merged['price'] ?? null;
        $message = $this->buildSuccessMessage($feedReachable, $feedFound, $feedPrice, $feedState['hint']);

        return [
            'status' => 'single',
            'message' => $message,
            'feed_found' => $feedFound,
            'feed_price' => $feedPrice,
            'product_url' => $productUrl,
            'page_http_code' => $pageResult['http_code'],
            'page_ok' => true,
            'parsed' => $merged,
            'hit' => $this->buildHit(
                $merged,
                $normalized,
                $productUrl,
                $feedFound,
                $feedPrice,
            ),
            'allow_feed_snapshot' => $feedPrice !== null,
        ];
    }

    /**
     * @return array{
     *   reachable:bool,
     *   found:bool,
     *   price:?float,
     *   row:?array,
     *   from_cache:bool,
     *   hint:?string
     * }
     */
    private function loadFeedState(
        ?string $feedCsvOverride,
        ?string $feedLastUpdateOverride,
        string $normalizedPzn,
    ): array {
        $load = $this->loadFeed($feedCsvOverride, $feedLastUpdateOverride);
        $row = $this->parser->findByPzn($this->index ?? [], $normalizedPzn);
        $found = $row !== null;
        $price = $found && isset($row['price']) ? (float) $row['price'] : null;

        $hint = null;
        if (!$load['ok']) {
            $hint = 'Preisfeed nicht erreichbar, Produktseite wird verwendet.';
        } elseif (!empty($load['warning'])) {
            $hint = (string) $load['warning'];
        } elseif (!$found) {
            $hint = 'PZN nicht im Preisfeed – Verkaufspreis von der Produktseite.';
        }

        return [
            'reachable' => (bool) $load['ok'],
            'found' => $found,
            'price' => $price,
            'row' => $row,
            'from_cache' => !empty($load['from_cache']),
            'hint' => $hint,
        ];
    }

    private function buildSuccessMessage(bool $feedReachable, bool $feedFound, ?float $feedPrice, ?string $hint): string
    {
        if (!$feedReachable) {
            return 'Preisfeed nicht erreichbar, Produktseite wird verwendet.';
        }

        if ($hint !== null && $hint !== '') {
            return $hint;
        }

        if ($feedFound && $feedPrice !== null) {
            return 'Produktseite und Feedpreis übernommen.';
        }

        return 'Produktdaten von der Produktseite übernommen.';
    }

    /**
     * @param array<string, mixed> $pageParsed
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
    private function mergePageWithOptionalFeed(array $pageParsed, ?float $feedPrice, string $pzn): array
    {
        $merged = [
            'product_name' => $pageParsed['product_name'] ?? null,
            'manufacturer' => $pageParsed['manufacturer'] ?? null,
            'package_size' => $pageParsed['package_size'] ?? null,
            'pzn' => $pzn,
            'price' => $feedPrice ?? $pageParsed['price'] ?? null,
            'avp_price' => $pageParsed['avp_price'] ?? null,
            'delivery_status' => $pageParsed['delivery_status'] ?? null,
        ];

        return $merged;
    }

    /**
     * @return array{ok:bool,from_cache:bool,warning:?string,error:?string,http_code:?int,row_count:int}
     */
    private function loadFromCache(?string $warning, ?int $httpCode = null): array
    {
        $csv = $this->cache->readCachedFeed();
        if ($csv === null) {
            $this->index = [];
            $this->lastLoad = [
                'ok' => false,
                'from_cache' => false,
                'warning' => null,
                'error' => 'Kein Feed-Cache vorhanden.',
                'http_code' => $httpCode,
                'row_count' => 0,
            ];

            return $this->lastLoad;
        }

        $this->index = $this->parser->parseIndex($csv);
        $this->lastLoad = [
            'ok' => true,
            'from_cache' => true,
            'warning' => $warning,
            'error' => null,
            'http_code' => $httpCode,
            'row_count' => count($this->index),
        ];

        return $this->lastLoad;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPzn(string $pzn): ?array
    {
        if ($this->index === null) {
            $this->loadFeed();
        }

        return $this->parser->findByPzn($this->index ?? [], $pzn);
    }

    public function buildDeeplink(string $pzn): string
    {
        $normalized = ShopHtmlParser::normalizePzn($pzn);
        $url = str_replace('{PZN}', rawurlencode($normalized), $this->deeplinkTemplate);

        if (!$this->urlValidator->isAllowed($url)) {
            throw new RuntimeException('Deeplink-URL ist nicht erlaubt.');
        }

        return $url;
    }

    /**
     * @return array{
     *   ok:bool,
     *   http_code:?int,
     *   error:?string,
     *   parsed:?array,
     *   diagnostics:array<string, mixed>
     * }
     */
    private function fetchProductPage(string $productUrl, string $pzn, ?string $htmlOverride): array
    {
        if ($htmlOverride !== null && $htmlOverride !== '') {
            try {
                $parsed = $this->htmlParser->parse($htmlOverride, $pzn);

                return [
                    'ok' => true,
                    'http_code' => 200,
                    'error' => null,
                    'parsed' => $parsed,
                    'diagnostics' => $this->diagnosticsFromFetch([
                        'ok' => true,
                        'http_code' => 200,
                        'effective_url' => $productUrl,
                        'content_length' => strlen($htmlOverride),
                        'transport' => 'override',
                        'error' => null,
                    ]),
                ];
            } catch (Throwable $e) {
                return [
                    'ok' => false,
                    'http_code' => null,
                    'error' => $e->getMessage(),
                    'parsed' => null,
                    'diagnostics' => $this->diagnosticsFromFetch([
                        'ok' => false,
                        'effective_url' => $productUrl,
                        'error' => $e->getMessage(),
                    ]),
                ];
            }
        }

        $fetch = $this->pageFetcher->fetch($productUrl);
        $diagnostics = $this->diagnosticsFromFetch($fetch);

        if (!$fetch['ok']) {
            return [
                'ok' => false,
                'http_code' => $fetch['http_code'] ?? null,
                'error' => (string) ($fetch['error'] ?? 'Produktseite konnte nicht geladen werden.'),
                'parsed' => null,
                'diagnostics' => $diagnostics,
            ];
        }

        try {
            $parsed = $this->htmlParser->parse((string) $fetch['html'], $pzn);

            return [
                'ok' => true,
                'http_code' => $fetch['http_code'] ?? null,
                'error' => null,
                'parsed' => $parsed,
                'diagnostics' => $diagnostics,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'http_code' => $fetch['http_code'] ?? null,
                'error' => $e->getMessage(),
                'parsed' => null,
                'diagnostics' => $diagnostics,
            ];
        }
    }

    /**
     * @param array<string, mixed> $fetch
     * @return array<string, mixed>
     */
    private function diagnosticsFromFetch(array $fetch): array
    {
        return [
            'page_http_code' => $fetch['http_code'] ?? null,
            'page_curl_errno' => $fetch['curl_errno'] ?? null,
            'page_curl_error' => $fetch['curl_error'] ?? null,
            'page_last_error' => $fetch['last_error'] ?? null,
            'page_effective_url' => $fetch['effective_url'] ?? null,
            'page_content_length' => (int) ($fetch['content_length'] ?? 0),
            'page_transport' => $fetch['transport'] ?? null,
            'page_fetch_error' => $fetch['error'] ?? null,
            'curl_available' => !empty($fetch['curl_available']),
            'allow_url_fopen' => !empty($fetch['allow_url_fopen']),
        ];
    }

    /**
     * @param array<string, mixed> $row
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
    public function toParsedProduct(array $row): array
    {
        return [
            'product_name' => isset($row['name']) ? (string) $row['name'] : null,
            'manufacturer' => isset($row['manufacturer']) ? (string) $row['manufacturer'] : null,
            'package_size' => isset($row['package_size']) ? (string) $row['package_size'] : null,
            'pzn' => isset($row['pzn']) ? (string) $row['pzn'] : null,
            'price' => isset($row['price']) ? (float) $row['price'] : null,
            'avp_price' => isset($row['avp']) ? (float) $row['avp'] : null,
            'delivery_status' => $this->mapAvailability(isset($row['availability']) ? (string) $row['availability'] : null),
        ];
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
     *   pzn:string,
     *   name:string,
     *   price:?float,
     *   url:string,
     *   parsed:array,
     *   from_feed:bool,
     *   feed_price:?float,
     *   allow_feed_snapshot:bool
     * }
     */
    private function buildHit(array $parsed, string $pzn, string $url, bool $fromFeed, ?float $feedPrice): array
    {
        $name = (string) ($parsed['product_name'] ?? '');
        if ($name === '') {
            $name = 'PZN ' . $pzn;
        }

        return [
            'pzn' => $pzn,
            'name' => $name,
            'price' => $parsed['price'] ?? null,
            'url' => $url,
            'parsed' => $parsed,
            'from_feed' => $fromFeed,
            'feed_price' => $feedPrice,
            'allow_feed_snapshot' => $fromFeed && $feedPrice !== null,
        ];
    }

    /**
     * @return array{ok:bool,from_cache:bool,warning:?string,error:?string,http_code:?int,row_count:int}
     */
    public function getLastLoadMeta(): array
    {
        return $this->lastLoad;
    }

    /**
     * @return array<string, mixed>
     */
    public function getLastResolveDebug(): array
    {
        return $this->lastResolveDebug;
    }

    public function getFeedUrl(): string
    {
        return $this->feedUrl;
    }

    private function mapAvailability(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $lower = strtolower($raw);
        if (str_contains($lower, 'nicht') || str_contains($lower, 'unavailable') || $lower === '0') {
            return 'nicht lieferbar';
        }

        if (str_contains($lower, 'begrenzt') || str_contains($lower, 'limited')) {
            return 'begrenzt';
        }

        if (
            str_contains($lower, 'lieferbar')
            || str_contains($lower, 'vorrätig')
            || str_contains($lower, 'available')
            || $lower === '1'
            || $lower === 'yes'
        ) {
            return 'lieferbar';
        }

        return trim($raw);
    }

    private function assertAllowedUrl(string $url): void
    {
        if (!$this->urlValidator->isAllowed($url)) {
            throw new RuntimeException('Feed-URL ist nicht erlaubt: ' . $url);
        }
    }
}
