<?php

declare(strict_types=1);

require_once __DIR__ . '/MedizinfuchsHttpClient.php';

if (!function_exists('createMedizinfuchsHttpClient')) {
    /**
     * @param array<string, mixed> $config
     */
    function createMedizinfuchsHttpClient(array $config): MedizinfuchsHttpClient
    {
        $collectorConfig = is_array($config['collector'] ?? null) ? $config['collector'] : [];
        $timeout = (int) ($collectorConfig['timeout'] ?? $collectorConfig['fetch_timeout'] ?? 15);
        $userAgent = (string) (
            $collectorConfig['user_agent']
            ?? MedizinfuchsHttpClient::DEFAULT_USER_AGENT
        );
        $allowInsecureSsl = filter_var(
            $collectorConfig['allow_insecure_ssl'] ?? false,
            FILTER_VALIDATE_BOOL,
        );
        $debugMode = filter_var($collectorConfig['debug'] ?? false, FILTER_VALIDATE_BOOL);
        $debugPath = dirname(__DIR__, 2) . '/storage/debug/medizinfuchs_last_response.html';

        return new MedizinfuchsHttpClient(
            $userAgent,
            max(1, $timeout),
            $allowInsecureSsl,
            $debugMode,
            $debugPath,
        );
    }
}

if (!function_exists('buildMedizinfuchsSearchUrl')) {
    /**
     * @param array<string, mixed> $config
     */
    function buildMedizinfuchsSearchUrl(array $config, string $pzn): string
    {
        $collectorConfig = is_array($config['collector'] ?? null) ? $config['collector'] : [];
        $template = (string) (
            $collectorConfig['medizinfuchs_search_url_template']
            ?? 'https://www.medizinfuchs.de/?params[search]={PZN}&params[search_cat]=1'
        );

        return str_replace('{PZN}', rawurlencode(trim($pzn)), $template);
    }
}
