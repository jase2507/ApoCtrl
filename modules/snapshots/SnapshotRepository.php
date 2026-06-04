<?php

declare(strict_types=1);

class SnapshotRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Neuer Snapshot – ausschließlich INSERT, keine Updates.
     */
    public function create(
        int $productId,
        int $competitorId,
        float $price,
        float $shippingCost,
        string $deliveryStatus,
        ?string $capturedAt = null,
    ): int {
        $captured = $capturedAt ?? date('Y-m-d H:i:s');
        $now = date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'INSERT INTO price_snapshots (
                product_id, competitor_id, price, shipping_cost, delivery_status,
                ranking, captured_at, created_at
            ) VALUES (
                :product_id, :competitor_id, :price, :shipping_cost, :delivery_status,
                :ranking, :captured_at, :created_at
            )'
        );

        $stmt->execute([
            'product_id' => $productId,
            'competitor_id' => $competitorId,
            'price' => $price,
            'shipping_cost' => $shippingCost,
            'delivery_status' => $deliveryStatus !== '' ? $deliveryStatus : null,
            'ranking' => null,
            'captured_at' => $captured,
            'created_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByProduct(int $productId, ?int $limit = null): array
    {
        $sql = $this->selectWithJoins() . '
            WHERE ps.product_id = :product_id
            ORDER BY ps.captured_at DESC, ps.id DESC';

        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['product_id' => $productId]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findLatestByProduct(int $productId, int $limit = 50): array
    {
        return $this->findByProduct($productId, $limit);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function findPaginated(int $page, int $perPage = 50, bool $includeTest = false): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $total = $this->countAll($includeTest);
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $sql = $this->selectWithJoins() . ' WHERE 1=1';
        if (!$includeTest) {
            $sql .= ' AND p.is_test = 0';
        }
        $sql .= '
            ORDER BY ps.captured_at DESC, ps.id DESC
            LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'rows' => $stmt->fetchAll() ?: [],
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
        ];
    }

    public function countAll(bool $includeTest = false): int
    {
        if ($includeTest) {
            return (int) $this->pdo->query('SELECT COUNT(*) FROM price_snapshots')->fetchColumn();
        }

        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM price_snapshots ps
             INNER JOIN products p ON p.id = ps.product_id
             WHERE p.is_test = 0'
        )->fetchColumn();
    }

    public function countToday(bool $includeTest = false): int
    {
        $sql = "SELECT COUNT(*) FROM price_snapshots ps
                INNER JOIN products p ON p.id = ps.product_id
                WHERE date(ps.captured_at) = date('now', 'localtime')";
        if (!$includeTest) {
            $sql .= ' AND p.is_test = 0';
        }

        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    public function countObservedProducts(bool $includeTest = false): int
    {
        $sql = 'SELECT COUNT(DISTINCT ps.product_id) FROM price_snapshots ps
                INNER JOIN products p ON p.id = ps.product_id
                WHERE 1=1';
        if (!$includeTest) {
            $sql .= ' AND p.is_test = 0';
        }

        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    public function averageRanking(bool $includeTest = false): ?float
    {
        $sql = 'SELECT AVG(ps.ranking) FROM price_snapshots ps
                INNER JOIN products p ON p.id = ps.product_id
                WHERE ps.ranking IS NOT NULL';
        if (!$includeTest) {
            $sql .= ' AND p.is_test = 0';
        }

        $value = $this->pdo->query($sql)->fetchColumn();

        if ($value === false || $value === null) {
            return null;
        }

        return round((float) $value, 2);
    }

    private function selectWithJoins(): string
    {
        return 'SELECT
            ps.id,
            ps.product_id,
            ps.competitor_id,
            ps.price,
            ps.shipping_cost,
            ps.delivery_status,
            ps.ranking,
            ps.captured_at,
            ps.created_at,
            p.pzn AS product_pzn,
            p.name AS product_name,
            p.is_test AS product_is_test,
            c.name AS competitor_name
        FROM price_snapshots ps
        INNER JOIN products p ON p.id = ps.product_id
        INNER JOIN competitors c ON c.id = ps.competitor_id';
    }
}
