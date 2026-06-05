<?php

declare(strict_types=1);

class MedizinfuchsHttpClient
{
    public const DEFAULT_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0 Safari/537.36';

    public function __construct(
        private readonly string $userAgent = self::DEFAULT_USER_AGENT,
        private readonly int $timeoutSeconds = 15,
        private readonly bool $allowInsecureSsl = false,
        private readonly bool $debugMode = false,
        private readonly string $debugResponsePath = '',
    ) {
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    /**
     * @return array{
     *   html:?string,
     *   http_code:?int,
     *   error:?string,
     *   effective_url:string,
     *   content_type:?string,
     *   response_length:int,
     *   user_agent:string,
     *   transport:string
     * }
     */
    public function fetch(string $url): array
    {
        $result = function_exists('curl_init')
            ? $this->fetchCurl($url)
            : $this->fetchStream($url);

        if ($this->debugMode && ($result['html'] ?? '') !== '') {
            self::saveDebugSnapshot((string) $result['html'], $this->debugResponsePath);
        }

        return $result;
    }

    public static function saveDebugSnapshot(string $html, string $path): void
    {
        if ($path === '') {
            return;
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        file_put_contents($path, mb_substr($html, 0, 1000));
    }

    /**
     * @return array<string, bool>
     */
    public static function checkContentMarkers(string $html): array
    {
        $lower = mb_strtolower($html);

        return [
            '16609329' => str_contains($html, '16609329'),
            'bite away' => str_contains($lower, 'bite away'),
            'medizinfuchs' => str_contains($lower, 'medizinfuchs'),
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    public static function toDebugMeta(array $response): array
    {
        $html = (string) ($response['html'] ?? '');

        return [
            'http_code' => $response['http_code'] ?? null,
            'content_type' => $response['content_type'] ?? null,
            'response_length' => (int) ($response['response_length'] ?? 0),
            'effective_url' => $response['effective_url'] ?? null,
            'user_agent' => $response['user_agent'] ?? null,
            'transport' => $response['transport'] ?? null,
            'content_checks' => self::checkContentMarkers($html),
            'response_preview' => mb_substr($html, 0, 1000),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function defaultRequestHeaders(): array
    {
        return [
            'Accept: text/html',
            'Accept-Language: de-DE,de;q=0.9',
            'Connection: close',
        ];
    }

    /**
     * @return array{
     *   html:?string,
     *   http_code:?int,
     *   error:?string,
     *   effective_url:string,
     *   content_type:?string,
     *   response_length:int,
     *   user_agent:string,
     *   transport:string
     * }
     */
    private function fetchCurl(string $url): array
    {
        $result = [
            'html' => null,
            'http_code' => null,
            'error' => null,
            'effective_url' => $url,
            'content_type' => null,
            'response_length' => 0,
            'user_agent' => $this->userAgent,
            'transport' => 'curl',
        ];

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
            CURLOPT_HTTPHEADER => self::defaultRequestHeaders(),
            CURLOPT_SSL_VERIFYPEER => !$this->allowInsecureSsl,
            CURLOPT_SSL_VERIFYHOST => $this->allowInsecureSsl ? 0 : 2,
            CURLOPT_ENCODING => '',
        ]);

        $html = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        unset($ch);

        if ($errno !== 0) {
            $result['error'] = $error !== '' ? $error : 'cURL Fehler ' . $errno;
        }

        $result['http_code'] = is_int($httpCode) && $httpCode > 0 ? $httpCode : null;
        $result['effective_url'] = is_string($effectiveUrl) && $effectiveUrl !== '' ? $effectiveUrl : $url;
        $result['content_type'] = is_string($contentType) && $contentType !== '' ? $contentType : null;
        $result['html'] = is_string($html) && $html !== '' ? $html : null;
        $result['response_length'] = is_string($html) ? strlen($html) : 0;

        return $result;
    }

    /**
     * @return array{
     *   html:?string,
     *   http_code:?int,
     *   error:?string,
     *   effective_url:string,
     *   content_type:?string,
     *   response_length:int,
     *   user_agent:string,
     *   transport:string
     * }
     */
    private function fetchStream(string $url): array
    {
        $result = [
            'html' => null,
            'http_code' => null,
            'error' => null,
            'effective_url' => $url,
            'content_type' => null,
            'response_length' => 0,
            'user_agent' => $this->userAgent,
            'transport' => 'stream',
        ];

        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            $result['error'] = 'allow_url_fopen deaktiviert';

            return $result;
        }

        $ssl = $this->allowInsecureSsl
            ? ['verify_peer' => false, 'verify_peer_name' => false]
            : ['verify_peer' => true, 'verify_peer_name' => true];

        $headerLines = array_merge(
            ['User-Agent: ' . $this->userAgent],
            self::defaultRequestHeaders(),
        );

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeoutSeconds,
                'header' => implode("\r\n", $headerLines) . "\r\n",
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
        if (is_array($headers)) {
            foreach ($headers as $line) {
                if (stripos($line, 'Content-Type:') === 0) {
                    $result['content_type'] = trim(substr($line, strlen('Content-Type:')));
                }
                if (preg_match('/\s(\d{3})\s/', $line, $m) === 1) {
                    $result['http_code'] = (int) $m[1];
                }
            }
        }

        if (!is_string($html) || $html === '') {
            $result['error'] = is_array($phpError) && isset($phpError['message'])
                ? (string) $phpError['message']
                : 'Leere Stream-Antwort';

            return $result;
        }

        $result['html'] = $html;
        $result['response_length'] = strlen($html);

        return $result;
    }
}
