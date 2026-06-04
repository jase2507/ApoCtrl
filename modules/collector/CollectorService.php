<?php

declare(strict_types=1);

class CollectorService
{
    public function __construct(
        private readonly CollectorRepository $repository,
        private readonly MedizinfuchsCollector $collector,
    ) {
    }

    /**
     * Vollständiger Lauf für alle aktiven Produkte (Cronjob-Vorbereitung).
     *
     * @return array{
     *   run_id:int,
     *   products_processed:int,
     *   snapshots_created:int,
     *   errors:int,
     *   status:string,
     *   details:list<array<string,mixed>>
     * }
     */
    public function runAll(): array
    {
        $runId = $this->repository->startCollectionRun();
        $products = $this->repository->getActiveProducts(false);

        $productsProcessed = 0;
        $snapshotsCreated = 0;
        $errors = 0;
        $details = [];

        $this->collector->setRunId($runId);

        foreach ($products as $product) {
            $result = $this->collector->collectProduct($product);
            $productsProcessed++;
            $snapshotsCreated += (int) $result['snapshots_created'];

            if (!$result['success']) {
                $errors++;
            }

            $details[] = $result;
        }

        $this->collector->setRunId(null);

        $status = $this->resolveStatus($productsProcessed, $errors);
        $this->repository->finishCollectionRun($runId, [
            'products_processed' => $productsProcessed,
            'snapshots_created' => $snapshotsCreated,
            'errors' => $errors,
            'status' => $status,
        ]);

        return [
            'run_id' => $runId,
            'products_processed' => $productsProcessed,
            'snapshots_created' => $snapshotsCreated,
            'errors' => $errors,
            'status' => $status,
            'details' => $details,
        ];
    }

    /**
     * Einzelprodukt per PZN (UI / manueller Lauf).
     *
     * @return array{
     *   run_id:int,
     *   success:bool,
     *   product_id:int,
     *   pzn:string,
     *   snapshots_created:int,
     *   message:string
     * }
     */
    public function runForPzn(string $pzn): array
    {
        $pzn = trim($pzn);
        $product = $this->repository->findProductByPzn($pzn);

        if ($product === null) {
            $runId = $this->repository->saveCollectionRun(0, 0, 1, 'failed');

            return [
                'run_id' => $runId,
                'success' => false,
                'product_id' => 0,
                'pzn' => $pzn,
                'snapshots_created' => 0,
                'message' => 'Produkt mit PZN ' . $pzn . ' nicht gefunden.',
            ];
        }

        if ((int) ($product['active'] ?? 0) !== 1) {
            $runId = $this->repository->saveCollectionRun(0, 0, 1, 'failed');

            return [
                'run_id' => $runId,
                'success' => false,
                'product_id' => (int) $product['id'],
                'pzn' => $pzn,
                'snapshots_created' => 0,
                'message' => 'Produkt ist nicht aktiv.',
            ];
        }

        $runId = $this->repository->startCollectionRun();
        $this->collector->setRunId($runId);
        $result = $this->collector->collectProduct($product);
        $this->collector->setRunId(null);

        $status = $result['success'] ? 'success' : 'failed';
        $this->repository->finishCollectionRun($runId, [
            'products_processed' => 1,
            'snapshots_created' => (int) $result['snapshots_created'],
            'errors' => $result['success'] ? 0 : 1,
            'status' => $status,
        ]);

        return [
            'run_id' => $runId,
            'success' => $result['success'],
            'product_id' => (int) $result['product_id'],
            'pzn' => (string) $result['pzn'],
            'snapshots_created' => (int) $result['snapshots_created'],
            'message' => (string) $result['message'],
        ];
    }

    public function getLastRun(): ?array
    {
        return $this->repository->getLastRun();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRecentRuns(int $limit = 10): array
    {
        return $this->repository->getRecentRuns($limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getLatestCollectorLogs(int $limit = 20): array
    {
        return $this->repository->getLatestCollectorLogs($limit);
    }

    private function resolveStatus(int $productsProcessed, int $errors): string
    {
        if ($productsProcessed === 0) {
            return 'failed';
        }

        if ($errors === 0) {
            return 'success';
        }

        if ($errors >= $productsProcessed) {
            return 'failed';
        }

        return 'partial';
    }
}
