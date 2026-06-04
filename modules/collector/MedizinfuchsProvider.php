<?php

declare(strict_types=1);

class MedizinfuchsProvider implements CollectorProviderInterface
{
    private static ?float $lastRequestAt = null;

    private ?int $currentRunId = null;

    /** @var array<string, mixed> */
    private array $lastFetchDebug = [];

    public function __construct(
        private readonly bool $mockMode,
        private readonly string $fixturesDir,
        private readonly string $cacheDir,
        private readonly string $urlTemplate,
        private readonly int $timeoutSeconds,
        private readonly int $requestDelayMs,
        private readonly int $cacheTtlMinutes,
        private readonly string $userAgent,
        private readonly bool $allowInsecureSsl,
        private readonly bool $fetchAjaxOffers = true,
        private readonly ?CollectorRepository $logRepository = null,
    ) {
    }

    public function setRunId(?int $runId): void
    {
        $this->currentRunId = $runId;
    }

    public static function resetRateLimitClock(): void
    {
        self::$lastRequestAt = null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getLastFetchDebug(): array
    {
        return $this->lastFetchDebug;
    }

    public function fetchByPzn(string $pzn): string
    {
        $pzn = trim($pzn);
        if ($pzn === '') {
            throw new RuntimeException('PZN fehlt für Medizinfuchs-Abruf.');
        }

        $this->lastFetchDebug = [
            'pzn' => $pzn,
            'url' => null,
            'http_code' => null,
            'duration_ms' => null,
            'cache_hit' => false,
            'status' => 'pending',
            'error' => null,
        ];

        if ($this->mockMode) {
            $started = microtime(true);
            try {
                $html = $this->loadFixture($pzn);
                $this->lastFetchDebug = [
                    'pzn' => $pzn,
                    'url' => 'mock:' . $pzn,
                    'http_code' => 200,
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                    'cache_hit' => false,
                    'status' => 'mock',
                    'error' => null,
                ];
                $this->persistLog($pzn, 'mock:' . $pzn, 200, (int) $this->lastFetchDebug['duration_ms'], 'mock', null);

                return $html;
            } catch (Throwable $e) {
                $durationMs = (int) round((microtime(true) - $started) * 1000);
                $this->lastFetchDebug['status'] = 'error';
                $this->lastFetchDebug['error'] = $e->getMessage();
                $this->lastFetchDebug['duration_ms'] = $durationMs;
                $this->persistLog($pzn, 'mock:' . $pzn, null, $durationMs, 'error', $e->getMessage());
                logError('Collector Mock PZN ' . $pzn . ': ' . $e->getMessage());
                throw $e;
            }
        }

        $this->enforceRateLimit();

        $url = $this->buildUrl($pzn);
        $this->lastFetchDebug['url'] = $url;

        $cachePath = $this->cachePathForPzn($pzn);
        if ($this->isCacheValid($cachePath)) {
            $html = (string) file_get_contents($cachePath);
            if ($html !== '') {
                $this->lastFetchDebug = [
                    'pzn' => $pzn,
                    'url' => $url,
                    'http_code' => 200,
                    'duration_ms' => 0,
                    'cache_hit' => true,
                    'status' => 'cache_hit',
                    'error' => null,
                ];
                $this->persistLog($pzn, $url, 200, 0, 'cache_hit', null);

                return $html;
            }
        }

        $started = microtime(true);
        try {
            $response = $this->httpFetch($url);
            $durationMs = (int) round((microtime(true) - $started) * 1000);
            $httpCode = (int) ($response['http_code'] ?? 0);
            $html = (string) ($response['html'] ?? '');

            if ($this->fetchAjaxOffers) {
                $html = $this->enrichWithAjaxOffers($html, $url);
            }

            $httpRejected = $httpCode > 0 && ($httpCode < 200 || $httpCode >= 400);
            if ($html === '' || $httpRejected) {
                $message = (string) ($response['error'] ?? 'Leere oder ungültige HTTP-Antwort.');
                $this->lastFetchDebug = [
                    'pzn' => $pzn,
                    'url' => $url,
                    'http_code' => $httpCode > 0 ? $httpCode : null,
                    'duration_ms' => $durationMs,
                    'cache_hit' => false,
                    'status' => 'error',
                    'error' => $message,
                ];
                $this->persistLog($pzn, $url, $httpCode > 0 ? $httpCode : null, $durationMs, 'error', $message);
                logError('Collector Live PZN ' . $pzn . ' HTTP ' . $httpCode . ': ' . $message);

                throw new RuntimeException('HTTP ' . ($httpCode > 0 ? $httpCode : '—') . ': ' . $message);
            }

            $this->writeCache($cachePath, $html);

            $this->lastFetchDebug = [
                'pzn' => $pzn,
                'url' => $url,
                'http_code' => $httpCode,
                'duration_ms' => $durationMs,
                'cache_hit' => false,
                'status' => 'ok',
                'error' => null,
            ];
            $this->persistLog($pzn, $url, $httpCode, $durationMs, 'ok', null);

            return $html;
        } catch (Throwable $e) {
            $durationMs = (int) round((microtime(true) - $started) * 1000);
            if (($this->lastFetchDebug['status'] ?? '') !== 'error') {
                $this->lastFetchDebug = [
                    'pzn' => $pzn,
                    'url' => $url,
                    'http_code' => null,
                    'duration_ms' => $durationMs,
                    'cache_hit' => false,
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
                $this->persistLog($pzn, $url, null, $durationMs, 'error', $e->getMessage());
            }
            logError('Collector Live PZN ' . $pzn . ': ' . $e->getMessage());
            throw $e;
        }
    }

    private function enrichWithAjaxOffers(string $html, string $pageUrl): string
    {
        if (!function_exists('curl_init')) {
            return $html;
        }

        $ppnId = $this->extractProductPpnId($html);
        if ($ppnId === null) {
            return $html;
        }

        $this->enforceRateLimit();

        $cookieFile = tempnam(sys_get_temp_dir(), 'mf_col_');
        if ($cookieFile === false) {
            return $html;
        }

        try {
            $ch = curl_init($pageUrl);
            if ($ch === false) {
                return $html;
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_USERAGENT => $this->userAgent,
                CURLOPT_COOKIEJAR => $cookieFile,
                CURLOPT_COOKIEFILE => $cookieFile,
                CURLOPT_SSL_VERIFYPEER => !$this->allowInsecureSsl,
                CURLOPT_SSL_VERIFYHOST => $this->allowInsecureSsl ? 0 : 2,
            ]);
            curl_exec($ch);
            unset($ch);

            $cookieContent = (string) file_get_contents($cookieFile);
            if (preg_match('/product_history\s+([0-9]+)/', $cookieContent, $m) === 1) {
                $ppnId = $m[1];
            }

            $ajaxHtml = $this->fetchAjaxApothekenHtml($ppnId, $cookieFile);
            if ($ajaxHtml === '') {
                return $html;
            }

            return $html . "\n<!-- apoctrl-mf-ajax -->\n" . $ajaxHtml;
        } finally {
            @unlink($cookieFile);
        }
    }

    private function extractProductPpnId(string $html): ?string
    {
        if (preg_match('/data-ppn=["\']([0-9]+)["\']/i', $html, $m) === 1) {
            return $m[1];
        }

        if (preg_match('/params\[ppn\]\s*=\s*["\']?([0-9]+)/i', $html, $m) === 1) {
            return $m[1];
        }

        if (preg_match('/"ppn"\s*:\s*"?([0-9]+)"?/i', $html, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function fetchAjaxApothekenHtml(string $ppnId, string $cookieFile): string
    {
        $ch = curl_init('https://www.medizinfuchs.de/ajax_apotheken');
        if ($ch === false) {
            return '';
        }

        $post = [
            'params[ppn]' => $ppnId,
            'params[entry_order]' => 'single_asc',
            'params[filter][rating]' => '',
            'params[filter][country]' => 7,
            'params[filter][favorit]' => 0,
            'params[filter][products_from][de]' => 0,
            'params[filter][products_from][at]' => 0,
            'params[filter][send]' => 1,
            'params[limit]' => 300,
            'params[merkzettel_sel]' => '',
            'params[merkzettel_reload]' => '',
            'params[apo_id]' => '',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($post),
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => ['Accept: text/html', 'Connection: close'],
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_SSL_VERIFYPEER => !$this->allowInsecureSsl,
            CURLOPT_SSL_VERIFYHOST => $this->allowInsecureSsl ? 0 : 2,
        ]);

        $ajax = curl_exec($ch);
        unset($ch);

        return is_string($ajax) ? $ajax : '';
    }

    private function buildUrl(string $pzn): string
    {
        if (!str_contains($this->urlTemplate, '{PZN}')) {
            throw new RuntimeException('collector.medizinfuchs_url_template muss {PZN} enthalten.');
        }

        return str_replace('{PZN}', rawurlencode($pzn), $this->urlTemplate);
    }

    private function cachePathForPzn(string $pzn): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $pzn) ?? $pzn;

        return rtrim($this->cacheDir, '/\\') . '/' . $safe . '.html';
    }

    private function isCacheValid(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $ttlSeconds = max(60, $this->cacheTtlMinutes * 60);
        $mtime = filemtime($path);

        return $mtime !== false && (time() - $mtime) < $ttlSeconds;
    }

    private function writeCache(string $path, string $html): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Cache-Verzeichnis nicht beschreibbar: ' . $dir);
        }

        file_put_contents($path, $html);
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

    /**
     * @return array{html:?string,http_code:?int,error:?string,effective_url:?string}
     */
    private function httpFetch(string $url): array
    {
        if (function_exists('curl_init')) {
            return $this->httpFetchCurl($url);
        }

        return $this->httpFetchStream($url);
    }

    /**
     * @return array{html:?string,http_code:?int,error:?string,effective_url:?string}
     */
    private function httpFetchCurl(string $url): array
    {
        $result = ['html' => null, 'http_code' => null, 'error' => null, 'effective_url' => $url];

        $ch = curl_init($url);
        if ($ch === false) {
            $result['error'] = 'curl_init() fehlgeschlagen';

            return $result;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => ['Accept: text/html', 'Connection: close'],
            CURLOPT_SSL_VERIFYPEER => !$this->allowInsecureSsl,
            CURLOPT_SSL_VERIFYHOST => $this->allowInsecureSsl ? 0 : 2,
            CURLOPT_ENCODING => '',
        ]);

        $html = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        unset($ch);

        $result['curl_errno'] = $errno;
        if ($errno !== 0) {
            $result['error'] = $error !== '' ? $error : 'cURL Fehler ' . $errno;
        }

        $result['http_code'] = is_int($httpCode) && $httpCode > 0 ? $httpCode : null;
        $result['effective_url'] = is_string($effectiveUrl) && $effectiveUrl !== '' ? $effectiveUrl : $url;
        $result['html'] = is_string($html) && $html !== '' ? $html : null;

        return $result;
    }

    /**
     * @return array{html:?string,http_code:?int,error:?string,effective_url:?string}
     */
    private function httpFetchStream(string $url): array
    {
        $result = ['html' => null, 'http_code' => null, 'error' => null, 'effective_url' => $url];

        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            $result['error'] = 'allow_url_fopen deaktiviert';

            return $result;
        }

        $ssl = $this->allowInsecureSsl
            ? ['verify_peer' => false, 'verify_peer_name' => false]
            : ['verify_peer' => true, 'verify_peer_name' => true];

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeoutSeconds,
                'header' => "User-Agent: {$this->userAgent}\r\nAccept: text/html\r\nConnection: close\r\n",
                'follow_location' => 1,
                'max_redirects' => 5,
            ],
            'ssl' => $ssl,
        ]);

        $previous = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', (string) $this->timeoutSeconds);
        error_clear_last();
        $html = @file_get_contents($url, false, $context);
        $phpError = error_get_last();
        if ($previous !== false) {
            ini_set('default_socket_timeout', (string) $previous);
        }

        $headers = function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers()
            : null;
        if (is_array($headers) && isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $m) === 1) {
            $result['http_code'] = (int) $m[1];
        }

        if (!is_string($html) || $html === '') {
            $result['error'] = is_array($phpError) && isset($phpError['message'])
                ? (string) $phpError['message']
                : 'Leere Stream-Antwort';

            return $result;
        }

        $result['html'] = $html;

        return $result;
    }

    private function persistLog(
        string $pzn,
        string $url,
        ?int $httpCode,
        int $durationMs,
        string $status,
        ?string $errorMessage,
    ): void {
        if ($this->logRepository === null) {
            return;
        }

        try {
            $this->logRepository->saveCollectorLog(
                $this->currentRunId,
                $pzn,
                $url,
                $httpCode,
                $durationMs,
                $status,
                $errorMessage,
            );
        } catch (Throwable $e) {
            logError('collector_logs speichern fehlgeschlagen: ' . $e->getMessage());
        }
    }

    private function loadFixture(string $pzn): string
    {
        $specific = $this->fixturesDir . '/medizinfuchs_collector_' . $pzn . '.html';
        $default = $this->fixturesDir . '/medizinfuchs_collector_default.html';

        $path = is_file($specific) ? $specific : $default;
        if (!is_file($path)) {
            throw new RuntimeException('Mock-Fixture nicht gefunden: ' . $path);
        }

        $content = file_get_contents($path);
        if (!is_string($content) || $content === '') {
            throw new RuntimeException('Mock-Fixture ist leer: ' . $path);
        }

        return $content;
    }
}
