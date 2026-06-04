<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/snapshots/SnapshotRepository.php';
require_once dirname(__DIR__) . '/snapshots/SnapshotService.php';

class ImportRepository
{
    private readonly SnapshotService $snapshotService;

    public function __construct(
        private readonly PDO $pdo,
        ?SnapshotService $snapshotService = null,
    ) {
        $this->snapshotService = $snapshotService
            ?? new SnapshotService(new SnapshotRepository($pdo));
    }

    public function startImportLog(string $filename): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO import_logs (filename, started_at, status, created_at)
             VALUES (:filename, :started_at, :status, :created_at)'
        );
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            'filename' => $filename,
            'started_at' => $now,
            'status' => 'running',
            'created_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function finishImportLog(int $importLogId, int $recordCount, int $errorCount): void
    {
        $status = $errorCount === 0 ? 'success' : ($recordCount > $errorCount ? 'partial' : 'failed');

        $stmt = $this->pdo->prepare(
            'UPDATE import_logs
             SET finished_at = :finished_at,
                 record_count = :record_count,
                 error_count = :error_count,
                 status = :status
             WHERE id = :id'
        );

        $stmt->execute([
            'id' => $importLogId,
            'finished_at' => date('Y-m-d H:i:s'),
            'record_count' => $recordCount,
            'error_count' => $errorCount,
            'status' => $status,
        ]);
    }

    public function findProductByPzn(string $pzn): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, pzn FROM products WHERE pzn = :pzn LIMIT 1');
        $stmt->execute(['pzn' => $pzn]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function createImportedProduct(string $pzn): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (pzn, name, active, is_test, created_at, updated_at)
             VALUES (:pzn, :name, :active, :is_test, :created_at, :updated_at)'
        );
        $stmt->execute([
            'pzn' => $pzn,
            'name' => 'Importiertes Produkt',
            'active' => 1,
            'is_test' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findCompetitorByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name FROM competitors WHERE LOWER(name) = LOWER(:name) AND is_test = 0 LIMIT 1'
        );
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function insertSnapshot(
        int $productId,
        int $competitorId,
        float $price,
        float $shippingCost,
        ?string $availability,
    ): void {
        $this->snapshotService->captureSnapshot(
            $productId,
            $competitorId,
            $price,
            $shippingCost,
            is_string($availability) ? $availability : '',
        );
    }
}
