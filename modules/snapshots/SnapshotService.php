<?php

declare(strict_types=1);

class SnapshotService
{
    public function __construct(private readonly SnapshotRepository $repository)
    {
    }

    /**
     * Erzeugt einen neuen Preis-Snapshot (kein Update, keine Überschreibung).
     */
    public function captureSnapshot(
        int $productId,
        int $competitorId,
        float $price,
        float $shippingCost,
        string $deliveryStatus = '',
        ?string $capturedAt = null,
    ): int {
        return $this->repository->create(
            $productId,
            $competitorId,
            $price,
            $shippingCost,
            $deliveryStatus,
            $capturedAt,
        );
    }
}
