<?php

declare(strict_types=1);

class CollectorRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getActiveProducts(bool $includeTest = false): array
    {
        $sql = 'SELECT id, pzn, name, active, is_test
                FROM products
                WHERE active = 1';
        if (!$includeTest) {
            $sql .= ' AND is_test = 0';
        }
        $sql .= ' ORDER BY pzn ASC';

        return $this->pdo->query($sql)->fetchAll() ?: [];
    }

    public function findProductByPzn(string $pzn): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, pzn, name, active, is_test FROM products WHERE pzn = :pzn LIMIT 1'
        );
        $stmt->execute(['pzn' => $pzn]);

        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function getCompetitorByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, name, type, active FROM competitors
             WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name))
             LIMIT 1"
        );
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function getOwnCompetitor(): ?array
    {
        $stmt = $this->pdo->query(
            "SELECT id, name FROM competitors WHERE type = 'own' ORDER BY id ASC LIMIT 1"
        );
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function createCompetitorIfMissing(string $name): int
    {
        $existing = $this->getCompetitorByName($name);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "INSERT INTO competitors (name, url, type, priority, active, is_test, created_at, updated_at)
             VALUES (:name, NULL, 'competitor', 0, 1, 0, :created_at, :updated_at)"
        );
        $stmt->execute([
            'name' => trim($name),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function startCollectionRun(): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "INSERT INTO collector_runs (started_at, status, products_processed, snapshots_created, errors)
             VALUES (:started_at, 'running', 0, 0, 0)"
        );
        $stmt->execute(['started_at' => $now]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array{products_processed:int,snapshots_created:int,errors:int,status:string} $data
     */
    public function finishCollectionRun(int $runId, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE collector_runs
             SET finished_at = :finished_at,
                 products_processed = :products_processed,
                 snapshots_created = :snapshots_created,
                 errors = :errors,
                 status = :status
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $runId,
            'finished_at' => date('Y-m-d H:i:s'),
            'products_processed' => (int) $data['products_processed'],
            'snapshots_created' => (int) $data['snapshots_created'],
            'errors' => (int) $data['errors'],
            'status' => (string) $data['status'],
        ]);
    }

    public function saveCollectionRun(
        int $productsProcessed,
        int $snapshotsCreated,
        int $errors,
        string $status,
        ?string $startedAt = null,
        ?string $finishedAt = null,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO collector_runs (
                started_at, finished_at, products_processed, snapshots_created, errors, status
            ) VALUES (
                :started_at, :finished_at, :products_processed, :snapshots_created, :errors, :status
            )'
        );
        $stmt->execute([
            'started_at' => $startedAt ?? date('Y-m-d H:i:s'),
            'finished_at' => $finishedAt,
            'products_processed' => $productsProcessed,
            'snapshots_created' => $snapshotsCreated,
            'errors' => $errors,
            'status' => $status,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function getLastRun(): ?array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM collector_runs ORDER BY id DESC LIMIT 1'
        );
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRecentRuns(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = $this->pdo->query(
            'SELECT * FROM collector_runs ORDER BY id DESC LIMIT ' . $limit
        );

        return $stmt->fetchAll() ?: [];
    }

    public function saveCollectorLog(
        ?int $runId,
        string $pzn,
        string $url,
        ?int $httpCode,
        int $durationMs,
        string $status,
        ?string $errorMessage,
        ?string $resolvedUrl = null,
        ?string $sourceUrl = null,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO collector_logs (
                run_id, pzn, url, resolved_url, source_url, http_code, duration_ms, status, error_message
            ) VALUES (
                :run_id, :pzn, :url, :resolved_url, :source_url, :http_code, :duration_ms, :status, :error_message
            )'
        );
        $stmt->execute([
            'run_id' => $runId,
            'pzn' => trim($pzn),
            'url' => $url,
            'resolved_url' => $resolvedUrl,
            'source_url' => $sourceUrl,
            'http_code' => $httpCode,
            'duration_ms' => max(0, $durationMs),
            'status' => $status,
            'error_message' => $errorMessage,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getLatestCollectorLogs(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT id, run_id, pzn, url, resolved_url, source_url, http_code, duration_ms, status, error_message, created_at
             FROM collector_logs
             ORDER BY id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }
}
