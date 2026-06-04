<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/snapshots/SnapshotRepository.php';
require_once dirname(__DIR__) . '/snapshots/SnapshotService.php';

class OwnShopRepository
{
    private readonly SnapshotService $snapshotService;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $ownCompetitorName = 'Eigener Shop',
        ?SnapshotService $snapshotService = null,
    ) {
        $this->snapshotService = $snapshotService
            ?? new SnapshotService(new SnapshotRepository($pdo));
    }

    public function getOwnCompetitorId(): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM competitors WHERE type = 'own' ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute();
        $id = $stmt->fetchColumn();

        if ($id === false) {
            throw new RuntimeException('Eigener Shop (type=own) ist nicht konfiguriert.');
        }

        return (int) $id;
    }

    public function insertOwnSnapshot(
        int $productId,
        float $price,
        float $shippingCost,
        ?string $deliveryStatus
    ): void {
        $this->snapshotService->captureSnapshot(
            $productId,
            $this->getOwnCompetitorId(),
            $price,
            $shippingCost,
            is_string($deliveryStatus) ? $deliveryStatus : '',
        );
    }

    public function isOwnCompetitorName(string $name): bool
    {
        return strcasecmp(trim($name), $this->ownCompetitorName) === 0;
    }
}
