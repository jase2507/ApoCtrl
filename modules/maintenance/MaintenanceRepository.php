<?php

declare(strict_types=1);

class MaintenanceRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{test_products:int,price_snapshots:int,own_price_snapshots:int}
     */
    public function getTestDataStats(): array
    {
        $testProducts = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM products WHERE is_test = 1'
        )->fetchColumn();

        $priceSnapshots = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM price_snapshots ps
             INNER JOIN products p ON p.id = ps.product_id
             WHERE p.is_test = 1'
        )->fetchColumn();

        $ownSnapshots = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM own_price_snapshots ops
             INNER JOIN products p ON p.id = ops.product_id
             WHERE p.is_test = 1'
        )->fetchColumn();

        return [
            'test_products' => $testProducts,
            'price_snapshots' => $priceSnapshots,
            'own_price_snapshots' => $ownSnapshots,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findTestProducts(): array
    {
        return $this->pdo->query(
            'SELECT id, pzn, name, active, created_at
             FROM products
             WHERE is_test = 1
             ORDER BY id ASC'
        )->fetchAll() ?: [];
    }

    /**
     * @return array{products_deleted:int,snapshots_deleted:int,own_snapshots_deleted:int}
     */
    public function cleanupTestProducts(): array
    {
        $this->pdo->beginTransaction();

        try {
            $priceStmt = $this->pdo->prepare(
                'DELETE FROM price_snapshots
                 WHERE product_id IN (SELECT id FROM products WHERE is_test = 1)'
            );
            $priceStmt->execute();
            $snapshotsDeleted = $priceStmt->rowCount();

            $ownStmt = $this->pdo->prepare(
                'DELETE FROM own_price_snapshots
                 WHERE product_id IN (SELECT id FROM products WHERE is_test = 1)'
            );
            $ownStmt->execute();
            $ownSnapshotsDeleted = $ownStmt->rowCount();

            $productStmt = $this->pdo->prepare('DELETE FROM products WHERE is_test = 1');
            $productStmt->execute();
            $productsDeleted = $productStmt->rowCount();

            $this->pdo->commit();

            return [
                'products_deleted' => $productsDeleted,
                'snapshots_deleted' => $snapshotsDeleted,
                'own_snapshots_deleted' => $ownSnapshotsDeleted,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }
}
