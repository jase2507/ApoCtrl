<?php

declare(strict_types=1);

class MedizinfuchsProvider implements CollectorProviderInterface
{
    public function __construct(
        private readonly bool $mockMode,
        private readonly string $fixturesDir,
        private readonly string $urlTemplate,
        private readonly int $timeoutSeconds = 15,
    ) {
    }

    public function fetchByPzn(string $pzn): string
    {
        $pzn = trim($pzn);
        if ($pzn === '') {
            throw new RuntimeException('PZN fehlt für Medizinfuchs-Abruf.');
        }

        if ($this->mockMode) {
            return $this->loadFixture($pzn);
        }

        throw new RuntimeException(
            'Live-Abruf Medizinfuchs ist noch nicht implementiert (collector.mock_mode=false). Provider später erweitern.'
        );
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
