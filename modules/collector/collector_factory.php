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
        $urlTemplate = (string) ($collectorConfig['medizinfuchs_url_template'] ?? 'https://www.medizinfuchs.de/pzn/{PZN}');
        $timeout = (int) ($collectorConfig['fetch_timeout'] ?? 15);

        $collectorRepo = new CollectorRepository($pdo);
        $snapshotService = new SnapshotService(new SnapshotRepository($pdo));
        $rankingEngine = new RankingEngine(new RankingRepository($pdo));

        $provider = new MedizinfuchsProvider($mockMode, $fixturesDir, $urlTemplate, $timeout);
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
