<?php

declare(strict_types=1);

class MedizinfuchsUrlResolver
{
    private static ?float $lastRequestAt = null;

    /** @var array<string, mixed> */
    private array $lastResolveDebug = [];

    public function __construct(
        private readonly string $searchUrlTemplate,
        private readonly int $requestDelayMs,
        private readonly MedizinfuchsHttpClient $httpClient,
    ) {
    }

    public static function resetRateLimitClock(): void
    {
        self::$lastRequestAt = null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getLastResolveDebug(): array
    {
        return $this->lastResolveDebug;
    }

    public function resolveProductUrl(string $pzn): ?string
    {
        $pzn = trim($pzn);
        if ($pzn === '') {
            $this->lastResolveDebug = [
                'search_url' => null,
                'resolved_url' => null,
                'effective_url' => null,
                'pzn_found' => false,
            ];

            return null;
        }

        $searchUrl = $this->buildSearchUrl($pzn);
        $this->lastResolveDebug = [
            'search_url' => $searchUrl,
            'resolved_url' => null,
            'effective_url' => null,
            'pzn_found' => false,
        ];

        $this->enforceRateLimit();
        $response = $this->httpClient->fetch($searchUrl);
        $this->mergeHttpDebug($response);

        $html = (string) ($response['html'] ?? '');
        $effectiveUrl = (string) ($response['effective_url'] ?? $searchUrl);
        $this->lastResolveDebug['effective_url'] = $effectiveUrl;

        if ($html === '') {
            return null;
        }

        if (!$this->pznAppearsInHtml($html, $pzn)) {
            return null;
        }

        $this->lastResolveDebug['pzn_found'] = true;
        $resolvedUrl = $this->pickResolvedUrl($searchUrl, $effectiveUrl, $html);
        $this->lastResolveDebug['resolved_url'] = $resolvedUrl;

        return $resolvedUrl;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function mergeHttpDebug(array $response): void
    {
        $this->lastResolveDebug = array_merge(
            $this->lastResolveDebug,
            MedizinfuchsHttpClient::toDebugMeta($response),
        );
    }

    private function buildSearchUrl(string $pzn): string
    {
        if (!str_contains($this->searchUrlTemplate, '{PZN}')) {
            throw new RuntimeException('collector.medizinfuchs_search_url_template muss {PZN} enthalten.');
        }

        return str_replace('{PZN}', rawurlencode($pzn), $this->searchUrlTemplate);
    }

    private function pznAppearsInHtml(string $html, string $pzn): bool
    {
        if (str_contains($html, $pzn)) {
            return true;
        }

        $digits = preg_replace('/\D+/', '', $pzn) ?? '';
        if ($digits !== '' && str_contains($html, $digits)) {
            return true;
        }

        $trimmed = ltrim($digits, '0');

        return $trimmed !== '' && str_contains($html, $trimmed);
    }

    private function pickResolvedUrl(string $searchUrl, string $effectiveUrl, string $html): string
    {
        if ($effectiveUrl !== $searchUrl && $this->looksLikeProductUrl($effectiveUrl)) {
            return $effectiveUrl;
        }

        foreach ($this->extractCandidateUrls($html, $effectiveUrl) as $candidate) {
            if ($candidate !== $searchUrl && $this->looksLikeProductUrl($candidate)) {
                return $candidate;
            }
        }

        return $searchUrl;
    }

    /**
     * @return list<string>
     */
    private function extractCandidateUrls(string $html, string $baseUrl): array
    {
        $candidates = [];

        $patterns = [
            '/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/i',
            '/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']canonical["\']/i',
            '/<meta[^>]+property=["\']og:url["\'][^>]+content=["\']([^"\']+)["\']/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:url["\']/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $match) === 1) {
                $candidates[] = $this->absoluteUrl((string) $match[1], $baseUrl);
            }
        }

        if (preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches) === 1) {
            foreach ($matches[1] as $href) {
                if ($this->looksLikeProductUrl((string) $href)) {
                    $candidates[] = $this->absoluteUrl((string) $href, $baseUrl);
                }
            }
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    private function looksLikeProductUrl(string $url): bool
    {
        $lower = strtolower(trim($url));
        if ($lower === '' || $lower === '#') {
            return false;
        }

        if (str_contains($lower, 'ajax_') || str_contains($lower, 'javascript:')) {
            return false;
        }

        return str_contains($lower, '/preisvergleich/')
            || str_contains($lower, 'produkt-pzn-')
            || str_contains($lower, '/pzn/')
            || str_contains($lower, 'medizinfuchs_product')
            || preg_match('/pzn-\d+/i', $url) === 1;
    }

    private function absoluteUrl(string $href, string $baseUrl): string
    {
        $href = trim($href);
        if ($href === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $href) === 1 || str_starts_with($href, 'file://')) {
            return $href;
        }

        $baseParts = parse_url($baseUrl);
        if (!is_array($baseParts) || !isset($baseParts['scheme'])) {
            return $href;
        }

        if (str_starts_with($href, '//')) {
            return ($baseParts['scheme'] ?? 'https') . ':' . $href;
        }

        if (str_starts_with($href, '/')) {
            $host = $baseParts['host'] ?? '';
            $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';

            return ($baseParts['scheme'] ?? 'https') . '://' . $host . $port . $href;
        }

        $basePath = $baseParts['path'] ?? '/';
        $dir = str_contains($basePath, '/') ? substr($basePath, 0, (int) strrpos($basePath, '/') + 1) : '/';

        return ($baseParts['scheme'] ?? 'https') . '://'
            . ($baseParts['host'] ?? '')
            . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '')
            . $dir . $href;
    }

    private function enforceRateLimit(): void
    {
        $delayMs = max(0, $this->requestDelayMs);
        if ($delayMs <= 0) {
            return;
        }

        $now = microtime(true);
        if (self::$lastRequestAt !== null) {
            $elapsedMs = ($now - self::$lastRequestAt) * 1000;
            $remaining = $delayMs - $elapsedMs;
            if ($remaining > 0) {
                usleep((int) round($remaining * 1000));
            }
        }

        self::$lastRequestAt = microtime(true);
    }
}
