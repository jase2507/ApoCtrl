<?php

declare(strict_types=1);

class PricingRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Aktive Produkte mit gesetztem Ziel-Ranking.
     *
     * @return list<array<string, mixed>>
     */
    public function findProductsWithTargetRanking(bool $includeTest = false): array
    {
        $sql = 'SELECT *
                FROM products
                WHERE active = 1
                  AND target_rank IS NOT NULL
                  AND target_rank > 0';
        if (!$includeTest) {
            $sql .= ' AND is_test = 0';
        }
        $sql .= ' ORDER BY name ASC';

        return $this->pdo->query($sql)->fetchAll() ?: [];
    }

    /**
     * Snapshots der jüngsten Erfassungsgruppe für ein Produkt.
     *
     * @return list<array<string, mixed>>
     */
    public function findLatestSnapshotsForProduct(int $productId): array
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
                c.name AS competitor_name,
                c.type AS competitor_type
             FROM price_snapshots ps
             INNER JOIN competitors c ON c.id = ps.competitor_id
             WHERE ps.product_id = :product_id
               AND ps.captured_at = (
                   SELECT MAX(captured_at)
                   FROM price_snapshots
                   WHERE product_id = :product_id_inner
               )
             ORDER BY ps.ranking ASC, (ps.price + ps.shipping_cost) ASC, ps.id ASC'
        );
        $stmt->execute([
            'product_id' => $productId,
            'product_id_inner' => $productId,
        ]);

        return $stmt->fetchAll() ?: [];
    }

    public function findProductById(int $productId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $productId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function findOwnCompetitor(): ?array
    {
        $stmt = $this->pdo->query(
            "SELECT id, name, type FROM competitors WHERE type = 'own' ORDER BY id ASC LIMIT 1"
        );
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function countProductsNeedingAction(bool $includeTest = false): int
    {
        $products = $this->findProductsWithTargetRanking($includeTest);
        $count = 0;

        foreach ($products as $product) {
            $targetRank = (int) ($product['target_rank'] ?? 0);
            if ($targetRank <= 0) {
                continue;
            }

            $snapshots = $this->findLatestSnapshotsForProduct((int) $product['id']);
            $ownRank = $this->resolveOwnCurrentRank($snapshots, (int) ($product['id'] ?? 0));

            if ($ownRank === null) {
                continue;
            }

            if ($ownRank > $targetRank) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param list<array<string, mixed>> $snapshots
     */
    public function resolveOwnCurrentRank(array $snapshots, int $productId): ?int
    {
        $own = $this->findOwnCompetitor();
        if ($own === null) {
            return null;
        }

        foreach ($snapshots as $row) {
            if ((int) ($row['competitor_id'] ?? 0) === (int) $own['id']) {
                $rank = $row['ranking'] ?? null;

                return $rank !== null ? (int) $rank : null;
            }
        }

        return null;
    }
}
