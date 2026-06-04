<?php

declare(strict_types=1);

class RankingRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array{id:int,pzn:string,name:string}>
     */
    public function listProducts(bool $includeTest = false): array
    {
        $sql = 'SELECT id, pzn, name FROM products';
        if (!$includeTest) {
            $sql .= ' WHERE is_test = 0';
        }
        $sql .= ' ORDER BY pzn ASC';

        return $this->pdo->query($sql)->fetchAll();
    }

    public function findProductByPzn(string $pzn): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, pzn, name FROM products WHERE pzn = :pzn LIMIT 1'
        );
        $stmt->execute(['pzn' => $pzn]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array{product_id:int,captured_at:string}>
     */
    public function fetchGroups(?int $productId = null, bool $includeTest = false): array
    {
        $sql = 'SELECT ps.product_id, ps.captured_at
                FROM price_snapshots ps
                INNER JOIN products p ON p.id = ps.product_id
                WHERE ps.captured_at IS NOT NULL';
        $params = [];

        if (!$includeTest && $productId === null) {
            $sql .= ' AND p.is_test = 0';
        }

        if ($productId !== null) {
            $sql .= ' AND ps.product_id = :product_id';
            $params['product_id'] = $productId;
        }

        $sql .= ' GROUP BY ps.product_id, ps.captured_at
                  ORDER BY ps.captured_at DESC, ps.product_id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchSnapshotsForGroup(int $productId, string $capturedAt): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                ps.id,
                ps.product_id,
                ps.competitor_id,
                ps.price,
                ps.shipping_cost,
                ps.delivery_status,
                ps.ranking,
                ps.captured_at,
                p.pzn,
                p.name AS product_name,
                p.is_test AS product_is_test,
                c.name AS competitor_name,
                c.type AS competitor_type
             FROM price_snapshots ps
             INNER JOIN products p ON p.id = ps.product_id
             INNER JOIN competitors c ON c.id = ps.competitor_id
             WHERE ps.product_id = :product_id
               AND ps.captured_at = :captured_at
             ORDER BY ps.id ASC'
        );
        $stmt->execute([
            'product_id' => $productId,
            'captured_at' => $capturedAt,
        ]);

        return $stmt->fetchAll();
    }

    public function clearGroupRankings(int $productId, string $capturedAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE price_snapshots
             SET ranking = NULL
             WHERE product_id = :product_id
               AND captured_at = :captured_at'
        );
        $stmt->execute([
            'product_id' => $productId,
            'captured_at' => $capturedAt,
        ]);
    }

    public function updateSnapshotRanking(int $snapshotId, int $ranking): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE price_snapshots SET ranking = :ranking WHERE id = :id'
        );
        $stmt->execute([
            'id' => $snapshotId,
            'ranking' => $ranking,
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function fetchLatestRankingRows(?string $filter, bool $includeTest = false): array
    {
        $testFilter = $includeTest ? '' : ' AND p.is_test = 0';
        $latestTestFilter = $includeTest ? '' : ' AND p2.is_test = 0';

        $sql = '
            SELECT
                ps.id,
                ps.product_id,
                ps.competitor_id,
                ps.price,
                ps.shipping_cost,
                ps.delivery_status,
                ps.ranking,
                ps.captured_at,
                p.pzn,
                p.name AS product_name,
                p.is_test AS product_is_test,
                c.name AS competitor_name,
                c.type AS competitor_type
            FROM price_snapshots ps
            INNER JOIN (
                SELECT ps2.product_id, MAX(ps2.captured_at) AS latest_captured_at
                FROM price_snapshots ps2
                INNER JOIN products p2 ON p2.id = ps2.product_id
                WHERE 1=1' . $latestTestFilter . '
                GROUP BY ps2.product_id
            ) latest ON latest.product_id = ps.product_id
                    AND latest.latest_captured_at = ps.captured_at
            INNER JOIN products p ON p.id = ps.product_id
            INNER JOIN competitors c ON c.id = ps.competitor_id
            WHERE 1=1' . $testFilter;
        $params = [];

        if ($filter !== null && $filter !== '') {
            $sql .= ' AND (p.pzn LIKE :filter OR p.name LIKE :filter)';
            $params['filter'] = '%' . $filter . '%';
        }

        $sql .= ' ORDER BY p.pzn ASC, ps.captured_at DESC, c.name ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}
