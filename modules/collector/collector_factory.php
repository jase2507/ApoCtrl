<?php

declare(strict_types=1);

require_once __DIR__ . '/CollectorProviderInterface.php';
require_once __DIR__ . '/MedizinfuchsProvider.php';
require_once __DIR__ . '/MedizinfuchsParser.php';
require_once __DIR__ . '/CollectorRepository.php';
require_once __DIR__ . '/MedizinfuchsCollector.php';
require_once __DIR__ . '/CollectorService.php';
require_once dirname(__DIR__) . '/snapshots/SnapshotRepository.php';
require_once dirname(__DIR__) . '/snapshots/SnapshotService.php';
require_once dirname(__DIR__) . '/rankings/RankingRepository.php';
require_once dirname(__DIR__) . '/rankings/RankingEngine.php';

if (!function_exists('createCollectorService')) {
    /**
     * @param array<string, mixed> $config
     */
    function createCollectorService(PDO $pdo, array $config): CollectorService
    {
        $collectorConfig = is_array($config['collector'] ?? null) ? $config['collector'] : [];
        $mockMode = filter_var($collectorConfig['mock_mode'] ?? true, FILTER_VALIDATE_BOOL);
        $fixturesDir = dirname(__DIR__, 2) . '/docs/examples';
        $cacheDir = dirname(__DIR__, 2) . '/storage/cache/collector';
        $urlTemplate = (string) (
            $collectorConfig['medizinfuchs_url_template']
            ?? 'https://www.medizinfuchs.de/pzn/{PZN}'
        );
        $timeout = (int) ($collectorConfig['timeout'] ?? $collectorConfig['fetch_timeout'] ?? 15);
        $requestDelayMs = (int) ($collectorConfig['request_delay_ms'] ?? 1000);
        $cacheTtlMinutes = (int) ($collectorConfig['cache_ttl_minutes'] ?? 15);
        $userAgent = (string) (
            $collectorConfig['user_agent']
            ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ApoCtrl Collector'
        );
        $allowInsecureSsl = filter_var(
            $collectorConfig['allow_insecure_ssl'] ?? false,
            FILTER_VALIDATE_BOOL,
        );
        $fetchAjaxOffers = filter_var(
            $collectorConfig['fetch_ajax_offers'] ?? true,
            FILTER_VALIDATE_BOOL,
        );

        $collectorRepo = new CollectorRepository($pdo);
        $snapshotService = new SnapshotService(new SnapshotRepository($pdo));
        $rankingEngine = new RankingEngine(new RankingRepository($pdo));

        $provider = new MedizinfuchsProvider(
            $mockMode,
            $fixturesDir,
            $cacheDir,
            $urlTemplate,
            max(1, $timeout),
            max(0, $requestDelayMs),
            max(1, $cacheTtlMinutes),
            $userAgent,
            $allowInsecureSsl,
            $fetchAjaxOffers,
            $collectorRepo,
        );
        $medCollector = new MedizinfuchsCollector(
            $provider,
            new MedizinfuchsParser(),
            $collectorRepo,
            $snapshotService,
            $rankingEngine,
        );

        return new CollectorService($collectorRepo, $medCollector);
    }
}
