<?php

declare(strict_types=1);

class MaintenanceService
{
    public function __construct(private readonly MaintenanceRepository $repository)
    {
    }

    /**
     * @return array{test_products:int,price_snapshots:int,own_price_snapshots:int}
     */
    public function getTestDataStats(): array
    {
        return $this->repository->getTestDataStats();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findTestProducts(): array
    {
        return $this->repository->findTestProducts();
    }

    /**
     * @return array{products_deleted:int,snapshots_deleted:int,own_snapshots_deleted:int}|null
     */
    public function cleanupTestData(): ?array
    {
        if (!Auth::isAdmin()) {
            return null;
        }

        return $this->repository->cleanupTestProducts();
    }
}
