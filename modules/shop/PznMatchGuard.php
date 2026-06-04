<?php

declare(strict_types=1);

class PznMatchGuard
{
    public const MISMATCH_MESSAGE = 'PZN-Abgleich fehlgeschlagen: angefragte PZN passt nicht zur Produktseite.';

    public static function normalize(string $pzn): string
    {
        return ShopHtmlParser::normalizePzn($pzn);
    }

    public static function matches(string $requestedPzn, ?string $parsedPzn): bool
    {
        $requested = self::normalize($requestedPzn);
        if ($requested === '' || $parsedPzn === null || trim($parsedPzn) === '') {
            return false;
        }

        $parsed = self::normalize($parsedPzn);

        return $parsed === $requested
            || ltrim($parsed, '0') === ltrim($requested, '0');
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
     * @return array{ok:bool,message:?string,requested_pzn:string,parsed_pzn:?string}
     */
    public static function validateParsedProduct(array $parsed, string $requestedPzn): array
    {
        $requested = self::normalize(trim($requestedPzn));
        $parsedPzn = isset($parsed['pzn']) && $parsed['pzn'] !== null && $parsed['pzn'] !== ''
            ? self::normalize((string) $parsed['pzn'])
            : null;

        if ($parsedPzn === null || $parsedPzn === '') {
            return [
                'ok' => false,
                'message' => self::MISMATCH_MESSAGE,
                'requested_pzn' => $requested,
                'parsed_pzn' => null,
            ];
        }

        if (!self::matches($requested, $parsedPzn)) {
            return [
                'ok' => false,
                'message' => self::MISMATCH_MESSAGE,
                'requested_pzn' => $requested,
                'parsed_pzn' => $parsedPzn,
            ];
        }

        return [
            'ok' => true,
            'message' => null,
            'requested_pzn' => $requested,
            'parsed_pzn' => $parsedPzn,
        ];
    }

    /**
     * @param array<string, mixed> $debug
     * @return array<string, mixed>
     */
    public static function enrichDebug(
        array $debug,
        string $requestedPzn,
        ?string $parsedPzn,
        bool $applied,
        ?string $productUrl = null,
        ?string $source = null,
        ?bool $cacheHit = null,
    ): array {
        $debug['requested_pzn'] = self::normalize($requestedPzn);
        $debug['parsed_pzn'] = $parsedPzn !== null && $parsedPzn !== ''
            ? self::normalize($parsedPzn)
            : null;
        $debug['applied'] = $applied;
        $debug['übernommen'] = $applied ? 'ja' : 'nein';

        if ($productUrl !== null) {
            $debug['product_url'] = $productUrl;
        }

        if ($source !== null) {
            $debug['source'] = $source;
        }

        if ($cacheHit !== null) {
            $debug['cache_hit'] = $cacheHit;
        }

        return $debug;
    }
}
