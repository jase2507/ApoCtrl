<?php

declare(strict_types=1);

class ShopFetcher
{
    private const USER_AGENT = 'Mozilla/5.0 (compatible; ApoCtrl-ShopSync/4.2; +https://shop.apotheker-seidel.de)';

    public function __construct(
        private readonly int $timeoutSeconds = 15,
        private readonly bool $allowInsecureSsl = false,
    ) {
    }

    /**
     * @return array{
     *   ok:bool,
     *   html:?string,
     *   error:?string,
     *   hint:?string,
     *   http_code:?int,
     *   curl_errno:?int,
     *   curl_error:?string,
     *   last_error:?string,
     *   effective_url:?string,
     *   content_length:int,
     *   transport:?string,
     *   curl_available:bool,
     *   allow_url_fopen:bool,
     *   https_wrapper:bool,
     *   openssl_loaded:bool
     * }
     */
    public function fetch(string $url): array
    {
        $prepared = $this->prepareUrl($url);
        if ($prepared['error'] !== null) {
            $base = $this->emptyResult($url);
            $base['error'] = $prepared['error'];

            return $base;
        }

        $fetchUrl = $prepared['url'];

        $base = $this->emptyResult($fetchUrl);

        if (!function_exists('curl_init') && !$this->allowUrlFopenEnabled()) {
            $base['error'] = 'allow_url_fopen deaktiviert und cURL nicht installiert – HTTP-Abruf nicht möglich.';
            $base['hint'] = 'cURL ist nicht installiert. Für stabile HTTPS-Abrufe sollte php-curl aktiviert werden.';

            return $base;
        }

        $scheme = strtolower((string) (parse_url($fetchUrl, PHP_URL_SCHEME) ?? ''));
        if ($scheme === 'https') {
            $httpsCheck = $this->httpsEnvironmentError();
            if ($httpsCheck !== null) {
                $base['error'] = $httpsCheck;
                if (!function_exists('curl_init')) {
                    $base['hint'] = 'cURL ist nicht installiert. Für stabile HTTPS-Abrufe sollte php-curl aktiviert werden.';
                }

                return $base;
            }
        }

        $curlAttempt = $this->fetchWithCurl($fetchUrl);
        if ($curlAttempt['html'] !== null && $curlAttempt['html'] !== '') {
            return $this->successResult($curlAttempt, 'curl');
        }

        $streamAttempt = $this->fetchWithStream($fetchUrl);
        if ($streamAttempt['html'] !== null && $streamAttempt['html'] !== '') {
            return $this->successResult($streamAttempt, 'stream');
        }

        return $this->failureResult($fetchUrl, $curlAttempt, $streamAttempt);
    }

    /**
     * @return array{url:?string, error:?string}
     */
    private function prepareUrl(string $url): array
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return ['url' => null, 'error' => 'URL ist leer.'];
        }

        if (str_contains($trimmed, "\0")) {
            return ['url' => null, 'error' => 'URL enthält ungültige Zeichen.'];
        }

        $parsed = parse_url($trimmed);
        if (!is_array($parsed)) {
            return ['url' => null, 'error' => 'URL konnte nicht geparst werden (parse_url).'];
        }

        $scheme = isset($parsed['scheme']) ? strtolower((string) $parsed['scheme']) : '';
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['url' => null, 'error' => 'URL-Schema muss http:// oder https:// sein (gefunden: ' . ($scheme !== '' ? $scheme : 'fehlt') . ').'];
        }

        $host = isset($parsed['host']) ? (string) $parsed['host'] : '';
        if ($host === '') {
            return ['url' => null, 'error' => 'URL-Host fehlt – Abruf würde als lokaler Pfad fehlschlagen.'];
        }

        if (str_starts_with($trimmed, '//')) {
            return ['url' => null, 'error' => 'Protokoll-relative URL (//host) wird nicht unterstützt.'];
        }

        if (!str_starts_with($trimmed, $scheme . '://')) {
            return ['url' => null, 'error' => 'URL beginnt nicht mit dem erkannten Schema – möglicher Pfad statt HTTP(S).'];
        }

        return ['url' => $trimmed, 'error' => null];
    }

    private function httpsEnvironmentError(): ?string
    {
        if (!extension_loaded('openssl')) {
            return 'openssl-Extension nicht geladen – HTTPS-Abrufe sind nicht möglich.';
        }

        if (!in_array('https', stream_get_wrappers(), true)) {
            return 'HTTPS-Stream-Wrapper nicht registriert (openssl-Extension und php.ini prüfen).';
        }

        return null;
    }

    /**
     * @return array{
     *   ok:bool,
     *   html:?string,
     *   error:?string,
     *   hint:?string,
     *   http_code:?int,
     *   curl_errno:?int,
     *   curl_error:?string,
     *   last_error:?string,
     *   effective_url:?string,
     *   content_length:int,
     *   transport:?string,
     *   curl_available:bool,
     *   allow_url_fopen:bool,
     *   https_wrapper:bool,
     *   openssl_loaded:bool
     * }
     */
    private function emptyResult(string $url): array
    {
        return [
            'ok' => false,
            'html' => null,
            'error' => null,
            'hint' => null,
            'http_code' => null,
            'curl_errno' => null,
            'curl_error' => null,
            'last_error' => null,
            'effective_url' => $url,
            'content_length' => 0,
            'transport' => null,
            'curl_available' => function_exists('curl_init'),
            'allow_url_fopen' => $this->allowUrlFopenEnabled(),
            'https_wrapper' => in_array('https', stream_get_wrappers(), true),
            'openssl_loaded' => extension_loaded('openssl'),
        ];
    }

    /**
     * @param array<string, mixed> $attempt
     * @return array{
     *   ok:bool,
     *   html:?string,
     *   error:?string,
     *   hint:?string,
     *   http_code:?int,
     *   curl_errno:?int,
     *   curl_error:?string,
     *   last_error:?string,
     *   effective_url:?string,
     *   content_length:int,
     *   transport:?string,
     *   curl_available:bool,
     *   allow_url_fopen:bool,
     *   https_wrapper:bool,
     *   openssl_loaded:bool
     * }
     */
    private function successResult(array $attempt, string $transport): array
    {
        $html = (string) $attempt['html'];

        return [
            'ok' => true,
            'html' => $html,
            'error' => null,
            'hint' => null,
            'http_code' => isset($attempt['http_code']) ? (int) $attempt['http_code'] : null,
            'curl_errno' => isset($attempt['curl_errno']) ? (int) $attempt['curl_errno'] : null,
            'curl_error' => isset($attempt['curl_error']) ? (string) $attempt['curl_error'] : null,
            'last_error' => isset($attempt['last_error']) ? (string) $attempt['last_error'] : null,
            'effective_url' => isset($attempt['effective_url']) ? (string) $attempt['effective_url'] : null,
            'content_length' => strlen($html),
            'transport' => $transport,
            'curl_available' => function_exists('curl_init'),
            'allow_url_fopen' => $this->allowUrlFopenEnabled(),
            'https_wrapper' => in_array('https', stream_get_wrappers(), true),
            'openssl_loaded' => extension_loaded('openssl'),
        ];
    }

    /**
     * @param array<string, mixed> $curlAttempt
     * @param array<string, mixed> $streamAttempt
     * @return array{
     *   ok:bool,
     *   html:?string,
     *   error:?string,
     *   hint:?string,
     *   http_code:?int,
     *   curl_errno:?int,
     *   curl_error:?string,
     *   last_error:?string,
     *   effective_url:?string,
     *   content_length:int,
     *   transport:?string,
     *   curl_available:bool,
     *   allow_url_fopen:bool,
     *   https_wrapper:bool,
     *   openssl_loaded:bool
     * }
     */
    private function failureResult(string $url, array $curlAttempt, array $streamAttempt): array
    {
        $result = $this->emptyResult($url);
        $result['http_code'] = $curlAttempt['http_code'] ?? $streamAttempt['http_code'] ?? null;
        $result['curl_errno'] = $curlAttempt['curl_errno'] ?? null;
        $result['curl_error'] = $curlAttempt['curl_error'] ?? null;
        $result['last_error'] = $streamAttempt['last_error'] ?? $curlAttempt['last_error'] ?? null;
        $result['effective_url'] = $curlAttempt['effective_url'] ?? $streamAttempt['effective_url'] ?? $url;
        $result['content_length'] = (int) ($curlAttempt['content_length'] ?? $streamAttempt['content_length'] ?? 0);
        $result['transport'] = $curlAttempt['transport'] ?? $streamAttempt['transport'] ?? null;
        $result['error'] = $this->buildFailureMessage($curlAttempt, $streamAttempt);
        $result['hint'] = $this->buildFailureHint($curlAttempt, $streamAttempt);

        return $result;
    }

    /**
     * @param array<string, mixed> $curlAttempt
     * @param array<string, mixed> $streamAttempt
     */
    private function buildFailureMessage(array $curlAttempt, array $streamAttempt): string
    {
        $curlAvailable = function_exists('curl_init');
        $fopen = $this->allowUrlFopenEnabled();

        if (!$curlAvailable && !$fopen) {
            return 'allow_url_fopen deaktiviert und cURL nicht installiert – HTTP-Abruf nicht möglich.';
        }

        $errno = isset($curlAttempt['curl_errno']) ? (int) $curlAttempt['curl_errno'] : 0;
        $curlError = trim((string) ($curlAttempt['curl_error'] ?? ''));
        if ($errno !== 0 || $curlError !== '') {
            $msg = 'cURL Fehler';
            if ($errno !== 0) {
                $msg .= ' ' . $errno;
            }
            if ($curlError !== '') {
                $msg .= ': ' . $curlError;
            }

            return $msg;
        }

        $lastError = trim((string) ($streamAttempt['last_error'] ?? $curlAttempt['last_error'] ?? ''));
        if ($lastError !== '') {
            if (stripos($lastError, 'allow_url_fopen') !== false) {
                return 'allow_url_fopen deaktiviert: ' . $lastError;
            }

            if (stripos($lastError, 'No such file or directory') !== false) {
                return $this->classifyStreamOpenFailure($lastError);
            }

            if (stripos($lastError, 'SSL') !== false || stripos($lastError, 'certificate') !== false) {
                return 'SSL/Zertifikat: ' . $lastError;
            }

            return $lastError;
        }

        $httpsError = $this->httpsEnvironmentError();
        if ($httpsError !== null && !$curlAvailable) {
            return $httpsError;
        }

        $httpCode = $curlAttempt['http_code'] ?? $streamAttempt['http_code'] ?? null;
        if ($httpCode !== null) {
            $length = (int) ($curlAttempt['content_length'] ?? $streamAttempt['content_length'] ?? 0);
            if ($length === 0) {
                return 'HTTP ' . $httpCode . ' – leere Antwort (kein HTML-Inhalt).';
            }

            return 'HTTP ' . $httpCode . ' – Antwort konnte nicht verarbeitet werden.';
        }

        if (!$curlAvailable) {
            return 'cURL nicht installiert und stream-Abruf fehlgeschlagen (HTTPS-Wrapper, openssl oder CA-Zertifikate prüfen).';
        }

        if (!$fopen) {
            return 'allow_url_fopen deaktiviert und cURL-Abruf ohne Inhalt.';
        }

        return 'Shop-Seite konnte nicht abgerufen werden (Timeout, Netzwerk oder leere Antwort).';
    }

    private function classifyStreamOpenFailure(string $lastError): string
    {
        if (!extension_loaded('openssl')) {
            return 'HTTPS-Abruf fehlgeschlagen: openssl-Extension nicht geladen. (' . $lastError . ')';
        }

        if (!in_array('https', stream_get_wrappers(), true)) {
            return 'HTTPS-Abruf fehlgeschlagen: https-Stream-Wrapper fehlt. (' . $lastError . ')';
        }

        return 'Stream konnte URL nicht öffnen (kein lokaler Pfad – prüfen: https-Wrapper, SSL/CA, DNS): ' . $lastError;
    }

    /**
     * @param array<string, mixed> $curlAttempt
     * @param array<string, mixed> $streamAttempt
     */
    private function buildFailureHint(array $curlAttempt, array $streamAttempt): ?string
    {
        if (function_exists('curl_init')) {
            return null;
        }

        $hasContent = ($curlAttempt['html'] ?? '') !== '' || ($streamAttempt['html'] ?? '') !== '';
        if ($hasContent) {
            return null;
        }

        return 'cURL ist nicht installiert. Für stabile HTTPS-Abrufe sollte php-curl aktiviert werden.';
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchWithCurl(string $url): array
    {
        $result = [
            'html' => null,
            'http_code' => null,
            'curl_errno' => null,
            'curl_error' => null,
            'last_error' => null,
            'effective_url' => $url,
            'content_length' => 0,
            'transport' => 'curl',
        ];

        if (!function_exists('curl_init')) {
            $result['transport'] = null;

            return $result;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            $result['curl_errno'] = -1;
            $result['curl_error'] = 'curl_init() fehlgeschlagen';

            return $result;
        }

        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,text/plain,*/*'],
            CURLOPT_SSL_VERIFYPEER => !$this->allowInsecureSsl,
            CURLOPT_SSL_VERIFYHOST => $this->allowInsecureSsl ? 0 : 2,
            CURLOPT_ENCODING => '',
        ];

        curl_setopt_array($ch, $curlOptions);

        $html = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        $result['curl_errno'] = $errno !== 0 ? $errno : null;
        $result['curl_error'] = $error !== '' ? $error : null;
        $result['http_code'] = is_int($httpCode) && $httpCode > 0 ? $httpCode : null;
        $result['effective_url'] = is_string($effectiveUrl) && $effectiveUrl !== '' ? $effectiveUrl : $url;

        if (!is_string($html) || $html === '') {
            return $result;
        }

        $result['content_length'] = strlen($html);

        if (is_int($httpCode) && ($httpCode < 200 || $httpCode >= 400)) {
            $result['html'] = null;

            return $result;
        }

        $result['html'] = $html;

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchWithStream(string $url): array
    {
        $result = [
            'html' => null,
            'http_code' => null,
            'curl_errno' => null,
            'curl_error' => null,
            'last_error' => null,
            'effective_url' => $url,
            'content_length' => 0,
            'transport' => 'stream',
        ];

        if (!$this->allowUrlFopenEnabled()) {
            $result['last_error'] = 'allow_url_fopen ist deaktiviert (ini: allow_url_fopen=0)';
            $result['transport'] = null;

            return $result;
        }

        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
        if ($scheme === 'https') {
            $httpsError = $this->httpsEnvironmentError();
            if ($httpsError !== null) {
                $result['last_error'] = $httpsError;

                return $result;
            }
        }

        $sslOptions = $this->allowInsecureSsl
            ? ['verify_peer' => false, 'verify_peer_name' => false]
            : ['verify_peer' => true, 'verify_peer_name' => true];

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeoutSeconds,
                'header' => 'User-Agent: ' . self::USER_AGENT . "\r\n"
                    . "Accept: text/html,application/xhtml+xml,text/plain,*/*\r\n"
                    . "Connection: close\r\n",
                'follow_location' => 1,
                'max_redirects' => 5,
            ],
            'ssl' => $sslOptions,
        ]);

        $previous = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', (string) $this->timeoutSeconds);

        error_clear_last();
        $html = @file_get_contents($url, false, $context);
        $phpError = error_get_last();

        if ($previous !== false) {
            ini_set('default_socket_timeout', (string) $previous);
        }

        $httpCode = null;
        $headers = function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers()
            : null;
        if (is_array($headers) && isset($headers[0]) && is_string($headers[0])) {
            if (preg_match('/\s(\d{3})\s/', $headers[0], $m) === 1) {
                $httpCode = (int) $m[1];
            }
        }

        $result['http_code'] = $httpCode;

        if (!is_string($html) || $html === '') {
            if (is_array($phpError) && isset($phpError['message'])) {
                $result['last_error'] = (string) $phpError['message'];
            }

            return $result;
        }

        $result['content_length'] = strlen($html);

        if ($httpCode !== null && ($httpCode < 200 || $httpCode >= 400)) {
            $result['html'] = null;

            return $result;
        }

        $result['html'] = $html;

        return $result;
    }

    private function allowUrlFopenEnabled(): bool
    {
        return filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL);
    }
}
