<?php

declare(strict_types=1);

class PznAutofillService
{
    public function __construct(
        private readonly ProductAutofillMerger $merger,
        private readonly ProductRepository $products,
        private readonly ShopUrlValidator $urlValidator,
        private readonly ?OwnShopFeedLookupService $feedLookup = null,
        private readonly bool $htmlSearchFallback = false,
        private readonly ?ShopSearchUrlBuilder $searchUrlBuilder = null,
        private readonly ?ShopSearchParser $searchParser = null,
        private readonly ?ShopFetcher $fetcher = null,
        private readonly ?ShopHtmlParser $productParser = null,
        private readonly ?ShopSyncService $syncService = null,
        private readonly bool $debugAutofill = false,
    ) {
    }

    /**
     * @return array{
     *   status:string,
     *   message:string,
     *   hits:list<array{pzn:string,name:string,price:?float,url:string}>,
     *   parsed:?array,
     *   shop_url:?string,
     *   debug:?array<string, mixed>
     * }
     */
    public function searchByPzn(
        string $pzn,
        ?string $searchHtmlOverride = null,
        ?string $productHtmlOverride = null,
        ?string $feedCsvOverride = null,
        ?string $feedLastUpdateOverride = null,
    ): array {
        $pzn = trim($pzn);
        if ($pzn === '') {
            return $this->result('error', 'Bitte zuerst eine PZN eingeben.', [], null, null, null);
        }

        if ($this->feedLookup !== null) {
            $feedResult = $this->searchByPznViaFeed(
                $pzn,
                $productHtmlOverride,
                $feedCsvOverride,
                $feedLastUpdateOverride,
            );
            if ($feedResult !== null) {
                return $feedResult;
            }
        }

        if ($this->htmlSearchFallback && $this->searchUrlBuilder !== null && $this->searchParser !== null && $this->fetcher !== null) {
            return $this->searchByPznViaHtml($pzn, $searchHtmlOverride, $productHtmlOverride);
        }

        return $this->result(
            'error',
            'PZN-Autofill ist nicht konfiguriert (Feed fehlt).',
            [],
            null,
            null,
            null,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function searchByPznViaFeed(
        string $pzn,
        ?string $productHtmlOverride,
        ?string $feedCsvOverride,
        ?string $feedLastUpdateOverride,
    ): ?array {
        $resolved = $this->feedLookup->resolvePzn(
            $pzn,
            $productHtmlOverride,
            $feedCsvOverride,
            $feedLastUpdateOverride,
        );

        $debug = $this->debugAutofill ? $this->feedLookup->getLastResolveDebug() : null;

        if ($resolved['status'] === 'error') {
            return $this->result(
                'error',
                (string) $resolved['message'],
                [],
                null,
                null,
                $debug,
            );
        }

        if ($resolved['status'] === 'none') {
            if ($this->htmlSearchFallback) {
                return null;
            }

            return $this->result(
                'none',
                (string) $resolved['message'],
                [],
                null,
                null,
                $debug,
            );
        }

        if ($resolved['status'] !== 'single' || !is_array($resolved['hit'])) {
            return null;
        }

        $hit = $resolved['hit'];

        return [
            'status' => 'single',
            'message' => (string) $resolved['message'],
            'hits' => [$hit],
            'parsed' => $resolved['parsed'],
            'shop_url' => $resolved['product_url'],
            'debug' => $debug,
        ];
    }

    /**
     * @return array{
     *   status:string,
     *   message:string,
     *   hits:list<array{pzn:string,name:string,price:?float,url:string}>,
     *   parsed:?array,
     *   shop_url:?string,
     *   debug:?array<string, mixed>
     * }
     */
    private function searchByPznViaHtml(
        string $pzn,
        ?string $searchHtmlOverride,
        ?string $productHtmlOverride,
    ): array {
        if ($this->searchUrlBuilder === null || $this->searchParser === null || $this->fetcher === null) {
            return $this->result('error', 'HTML-Fallback nicht verfügbar.', [], null, null, null);
        }

        try {
            $searchUrl = $this->searchUrlBuilder->build($pzn);
        } catch (Throwable $e) {
            return $this->result('error', $e->getMessage(), [], null, null, null);
        }

        $httpCode = null;
        if ($searchHtmlOverride === null) {
            $fetch = $this->fetcher->fetch($searchUrl);
            $httpCode = $fetch['http_code'] ?? null;
            if (!$fetch['ok']) {
                return $this->result(
                    'error',
                    (string) ($fetch['error'] ?? 'Shop-Suche fehlgeschlagen.'),
                    [],
                    null,
                    null,
                    $this->buildHtmlDebug($pzn, $searchUrl, $httpCode, 0, null, null),
                );
            }
            $searchHtml = (string) $fetch['html'];
        } else {
            $searchHtml = $searchHtmlOverride;
        }

        $hits = $this->searchParser->parseSearchResults($searchHtml, $pzn);
        $debug = $this->buildHtmlDebug(
            $pzn,
            $searchUrl,
            $httpCode,
            count($hits),
            $hits[0]['name'] ?? null,
            $hits[0]['url'] ?? null,
        );

        if ($hits === []) {
            return $this->result(
                'none',
                'Keine Shopdaten zur PZN gefunden (HTML-Fallback).',
                [],
                null,
                null,
                $debug,
            );
        }

        if (count($hits) === 1) {
            return $this->loadProductFromHit($hits[0], $pzn, $productHtmlOverride, $searchHtml, $debug);
        }

        return $this->result('multiple', 'Mehrere Treffer im Shop – bitte Produkt auswählen.', $hits, null, null, $debug);
    }

    /**
     * @param array{pzn:string,name:string,price:?float,url:string,parsed?:array,from_feed?:bool} $hit
     * @param array<string, mixed> $draft
     * @return array{draft:array<string,mixed>,message:string,parsed:array}
     */
    public function applyHitToDraft(array $draft, array $hit, bool $overwrite, ?string $productHtmlOverride = null): array
    {
        $url = trim($hit['url']);
        $urlError = $this->urlValidator->validateOrError($url);
        if ($urlError !== null) {
            throw new RuntimeException($urlError);
        }

        $parsed = $this->resolveParsedFromHit($hit, $draft, $productHtmlOverride);
        $merged = $this->merger->mergeDraft($draft, $parsed, $url, $overwrite);

        return [
            'draft' => $merged,
            'message' => 'Feeddaten wurden übernommen.',
            'parsed' => $parsed,
        ];
    }

    /**
     * @param array<string, mixed> $draft
     * @return array{success:bool,message:string,draft:array<string,mixed>}
     */
    public function applyHitToProduct(
        int $productId,
        array $draft,
        array $hit,
        bool $overwrite,
        bool $runSync,
        ?string $productHtmlOverride = null,
    ): array {
        try {
            $apply = $this->applyHitToDraft($draft, $hit, $overwrite, $productHtmlOverride);
        } catch (Throwable $e) {
            $this->products->recordShopSyncError($productId, $e->getMessage());

            return ['success' => false, 'message' => $e->getMessage(), 'draft' => $draft];
        }

        $existing = $this->products->findById($productId);
        if ($existing === null) {
            return ['success' => false, 'message' => 'Produkt nicht gefunden.', 'draft' => $draft];
        }

        $patch = $this->merger->buildDbPatch($existing, $apply['parsed'], (string) $hit['url'], $overwrite);
        if ($patch !== []) {
            $this->products->applyAutofillPatch($productId, $patch);
        } else {
            $this->products->touchShopAutofillOk($productId);
        }

        if ($runSync && $this->syncService !== null) {
            if (!empty($hit['allow_feed_snapshot']) && isset($hit['parsed']) && is_array($hit['parsed'])) {
                $parsedForSync = $hit['parsed'];
                if (isset($hit['feed_price']) && $hit['feed_price'] !== null) {
                    $parsedForSync['price'] = (float) $hit['feed_price'];
                }
                $sync = $this->syncService->syncProductFromParsed($productId, $parsedForSync);
            } else {
                return [
                    'success' => true,
                    'message' => 'Stammdaten übernommen. Kein Snapshot (Feed-Preis nicht verfügbar).',
                    'draft' => $apply['draft'],
                ];
            }

            if (!$sync['success']) {
                return [
                    'success' => false,
                    'message' => 'Daten übernommen, Shop-Sync: ' . $sync['message'],
                    'draft' => $apply['draft'],
                ];
            }

            return [
                'success' => true,
                'message' => 'Feeddaten übernommen, Snapshot erzeugt und Ranking aktualisiert.',
                'draft' => $apply['draft'],
            ];
        }

        return ['success' => true, 'message' => $apply['message'], 'draft' => $apply['draft']];
    }

    /**
     * @param array{pzn:string,name:string,price:?float,url:string,parsed?:array,from_feed?:bool} $hit
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
    private function resolveParsedFromHit(array $hit, array $draft, ?string $productHtmlOverride): array
    {
        if (isset($hit['parsed']) && is_array($hit['parsed'])) {
            return $hit['parsed'];
        }

        if ($this->productParser === null || $this->fetcher === null) {
            throw new RuntimeException('Produktdaten konnten nicht aufgelöst werden.');
        }

        $pzn = ShopHtmlParser::normalizePzn((string) ($draft['pzn'] ?? $hit['pzn']));

        return $this->loadParsedProduct((string) $hit['url'], $pzn, $productHtmlOverride);
    }

    /**
     * @param array{pzn:string,name:string,price:?float,url:string} $hit
     * @param array<string, mixed>|null $debug
     * @return array{
     *   status:string,
     *   message:string,
     *   hits:list<array{pzn:string,name:string,price:?float,url:string}>,
     *   parsed:?array,
     *   shop_url:?string,
     *   debug:?array<string, mixed>
     * }
     */
    private function loadProductFromHit(
        array $hit,
        string $pzn,
        ?string $productHtmlOverride,
        ?string $searchHtml,
        ?array $debug,
    ): array {
        try {
            $urlError = $this->urlValidator->validateOrError($hit['url']);
            if ($urlError !== null) {
                return $this->result('error', $urlError, [$hit], null, null, $debug);
            }

            $normalizedPzn = ShopHtmlParser::normalizePzn($pzn);
            $parsed = null;

            if ($searchHtml !== null && $searchHtml !== '' && $this->productParser !== null) {
                try {
                    $parsed = $this->productParser->parse($searchHtml, $normalizedPzn);
                } catch (Throwable) {
                    $parsed = null;
                }
            }

            if ($parsed === null && $this->productParser !== null && $this->fetcher !== null) {
                $parsed = $this->loadParsedProduct($hit['url'], $normalizedPzn, $productHtmlOverride);
            }

            return [
                'status' => 'single',
                'message' => 'Ein Treffer gefunden – Daten können übernommen werden.',
                'hits' => [$hit],
                'parsed' => $parsed,
                'shop_url' => $hit['url'],
                'debug' => $this->debugAutofill ? $debug : null,
            ];
        } catch (Throwable $e) {
            return $this->result('error', $e->getMessage(), [$hit], null, null, $debug);
        }
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
     * }
     */
    private function loadParsedProduct(string $url, string $pzn, ?string $productHtmlOverride): array
    {
        if ($this->productParser === null || $this->fetcher === null) {
            throw new RuntimeException('HTML-Parser nicht verfügbar.');
        }

        if ($productHtmlOverride === null) {
            $fetch = $this->fetcher->fetch($url);
            if (!$fetch['ok']) {
                throw new RuntimeException((string) ($fetch['error'] ?? 'Produktseite konnte nicht geladen werden.'));
            }
            $productHtmlOverride = (string) $fetch['html'];
        }

        return $this->productParser->parse($productHtmlOverride, $pzn);
    }

    /**
     * @param list<array{pzn:string,name:string,price:?float,url:string}> $hits
     * @param array<string, mixed>|null $debug
     * @return array{
     *   status:string,
     *   message:string,
     *   hits:list<array{pzn:string,name:string,price:?float,url:string}>,
     *   parsed:?array,
     *   shop_url:?string,
     *   debug:?array<string, mixed>
     * }
     */
    private function result(
        string $status,
        string $message,
        array $hits,
        ?array $parsed,
        ?string $shopUrl,
        ?array $debug,
    ): array {
        return [
            'status' => $status,
            'message' => $message,
            'hits' => $hits,
            'parsed' => $parsed,
            'shop_url' => $shopUrl,
            'debug' => $this->debugAutofill ? $debug : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildHtmlDebug(
        string $pzn,
        string $searchUrl,
        ?int $httpCode,
        int $hitCount,
        ?string $firstHitName,
        ?string $firstHitUrl,
    ): ?array {
        if (!$this->debugAutofill) {
            return null;
        }

        return [
            'source' => 'html',
            'pzn' => ShopHtmlParser::normalizePzn($pzn),
            'search_url' => $searchUrl,
            'http_code' => $httpCode,
            'hit_count' => $hitCount,
            'first_hit_name' => $firstHitName,
            'first_hit_url' => $firstHitUrl,
        ];
    }
}
