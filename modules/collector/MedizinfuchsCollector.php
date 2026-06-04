<?php

declare(strict_types=1);

class MedizinfuchsCollector
{
    public function __construct(
        private readonly CollectorProviderInterface $provider,
        private readonly MedizinfuchsParser $parser,
        private readonly CollectorRepository $collectorRepository,
        private readonly SnapshotService $snapshotService,
        private readonly RankingEngine $rankingEngine,
    ) {
    }

    /**
     * @param array{id:int,pzn:string,name?:string} $product
     * @return array{
     *   success:bool,
     *   product_id:int,
     *   pzn:string,
     *   snapshots_created:int,
     *   competitors_seen:int,
     *   message:string
     * }
     */
    public function collectProduct(array $product): array
    {
        $productId = (int) ($product['id'] ?? 0);
        $pzn = trim((string) ($product['pzn'] ?? ''));

        if ($productId <= 0 || $pzn === '') {
            return $this->result($productId, $pzn, false, 0, 0, 'Ungültiges Produkt.');
        }

        if ((int) ($product['active'] ?? 0) !== 1) {
            return $this->result($productId, $pzn, false, 0, 0, 'Produkt ist nicht aktiv.');
        }

        try {
            $html = $this->provider->fetchByPzn($pzn);
        } catch (Throwable $e) {
            return $this->result($productId, $pzn, false, 0, 0, 'Abruf: ' . $e->getMessage());
        }

        try {
            $offers = $this->parser->parse($html);
        } catch (Throwable $e) {
            return $this->result($productId, $pzn, false, 0, 0, 'Parser: ' . $e->getMessage());
        }

        if ($offers === []) {
            return $this->result($productId, $pzn, false, 0, 0, 'Keine Angebote im Abruf gefunden.');
        }

        $ownCompetitor = $this->collectorRepository->getOwnCompetitor();
        $ownCompetitorId = $ownCompetitor !== null ? (int) $ownCompetitor['id'] : null;
        $capturedAt = date('Y-m-d H:i:s');
        $snapshotsCreated = 0;
        $competitorsSeen = 0;

        foreach ($offers as $offer) {
            $competitorName = trim((string) ($offer['competitor'] ?? ''));
            if ($competitorName === '') {
                continue;
            }

            if ($ownCompetitor !== null
                && strcasecmp($competitorName, (string) $ownCompetitor['name']) === 0) {
                continue;
            }

            try {
                $competitorId = $this->collectorRepository->createCompetitorIfMissing($competitorName);
                if ($ownCompetitorId !== null && $competitorId === $ownCompetitorId) {
                    continue;
                }

                $existing = $this->collectorRepository->getCompetitorByName($competitorName);
                if ($existing !== null && ($existing['type'] ?? '') === 'own') {
                    continue;
                }

                $this->snapshotService->captureSnapshot(
                    $productId,
                    $competitorId,
                    (float) $offer['price'],
                    (float) ($offer['shipping_cost'] ?? 0),
                    (string) ($offer['delivery_status'] ?? ''),
                    $capturedAt,
                );
                $snapshotsCreated++;
                $competitorsSeen++;
            } catch (Throwable $e) {
                logError('Collector Snapshot PZN ' . $pzn . ': ' . $e->getMessage());
            }
        }

        if ($snapshotsCreated === 0) {
            return $this->result($productId, $pzn, false, 0, $competitorsSeen, 'Keine Snapshots gespeichert.');
        }

        try {
            $this->rankingEngine->runForProduct($productId);
        } catch (Throwable $e) {
            return $this->result(
                $productId,
                $pzn,
                false,
                $snapshotsCreated,
                $competitorsSeen,
                'Snapshots OK, Ranking: ' . $e->getMessage(),
            );
        }

        return $this->result(
            $productId,
            $pzn,
            true,
            $snapshotsCreated,
            $competitorsSeen,
            $snapshotsCreated . ' Snapshot(s), Ranking aktualisiert.',
        );
    }

    /**
     * @return array{
     *   success:bool,
     *   product_id:int,
     *   pzn:string,
     *   snapshots_created:int,
     *   competitors_seen:int,
     *   message:string
     * }
     */
    private function result(
        int $productId,
        string $pzn,
        bool $success,
        int $snapshotsCreated,
        int $competitorsSeen,
        string $message,
    ): array {
        return [
            'success' => $success,
            'product_id' => $productId,
            'pzn' => $pzn,
            'snapshots_created' => $snapshotsCreated,
            'competitors_seen' => $competitorsSeen,
            'message' => $message,
        ];
    }
}
