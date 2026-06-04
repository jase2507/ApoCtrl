<?php

declare(strict_types=1);

class OwnShopFeedFetcher
{
    private const USER_AGENT = 'Mozilla/5.0 (compatible; ApoCtrl-OwnShopFeed/4.2)';

    public function __construct(private readonly int $timeoutSeconds = 15)
    {
    }

    /**
     * @return array{ok:bool,body:?string,error:?string,http_code:?int}
     */
    public function fetch(string $url): array
    {
        $curl = $this->fetchWithCurl($url);
        if ($curl['body'] !== null && $curl['body'] !== '') {
            return [
                'ok' => true,
                'body' => $curl['body'],
                'error' => null,
                'http_code' => $curl['http_code'],
            ];
        }

        $stream = $this->fetchWithStream($url);
        if ($stream['body'] !== null && $stream['body'] !== '') {
            return [
                'ok' => true,
                'body' => $stream['body'],
                'error' => null,
                'http_code' => $stream['http_code'],
            ];
        }

        $code = $curl['http_code'] ?? $stream['http_code'];
        $message = 'Feed konnte nicht abgerufen werden.';
        if ($code !== null) {
            $message .= ' HTTP ' . $code;
        }

        return ['ok' => false, 'body' => null, 'error' => $message, 'http_code' => $code];
    }

    /**
     * @return array{body:?string,http_code:?int}
     */
    private function fetchWithCurl(string $url): array
    {
        if (!function_exists('curl_init')) {
            return ['body' => null, 'http_code' => null];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['body' => null, 'http_code' => null];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: text/plain,text/csv,*/*'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!is_string($body) || $body === '') {
            return ['body' => null, 'http_code' => is_int($httpCode) && $httpCode > 0 ? $httpCode : null];
        }

        if (is_int($httpCode) && ($httpCode < 200 || $httpCode >= 400)) {
            return ['body' => null, 'http_code' => $httpCode];
        }

        return ['body' => $body, 'http_code' => is_int($httpCode) && $httpCode > 0 ? $httpCode : null];
    }

    /**
     * @return array{body:?string,http_code:?int}
     */
    private function fetchWithStream(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeoutSeconds,
                'header' => 'User-Agent: ' . self::USER_AGENT . "\r\nAccept: text/plain,text/csv,*/*\r\n",
                'follow_location' => 1,
                'max_redirects' => 5,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $previous = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', (string) $this->timeoutSeconds);

        $body = @file_get_contents($url, false, $context);

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

        if (!is_string($body) || $body === '') {
            return ['body' => null, 'http_code' => $httpCode];
        }

        if ($httpCode !== null && ($httpCode < 200 || $httpCode >= 400)) {
            return ['body' => null, 'http_code' => $httpCode];
        }

        return ['body' => $body, 'http_code' => $httpCode];
    }
}
