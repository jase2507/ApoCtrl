<?php

declare(strict_types=1);

class PricingEngine
{
    /** @var list<string> */
    private const UNAVAILABLE = [
        'nicht lieferbar',
        'unavailable',
        'out of stock',
    ];

    /** @var list<string> */
    private const AVAILABLE = [
        'lieferbar',
        'available',
        'sofort verfügbar',
        'in stock',
    ];

    public function __construct(private readonly PricingRepository $repository)
    {
    }

    /**
     * @return array{
     *   product_id:int,
     *   pzn:?string,
     *   product_name:?string,
     *   current_price:?float,
     *   suggested_price:?float,
     *   target_rank:?int,
     *   current_rank:?int,
     *   minimum_price:?float,
     *   reason:string
     * }
     */
    public function suggestPrice(int $productId): array
    {
        $product = $this->repository->findProductById($productId);
        if ($product === null) {
            return $this->emptySuggestion($productId, 'Produkt nicht gefunden.');
        }

        $targetRank = isset($product['target_rank']) ? (int) $product['target_rank'] : null;
        $minimumPrice = $product['min_price'] !== null && $product['min_price'] !== ''
            ? round((float) $product['min_price'], 2)
            : null;
        $currentSalePrice = $product['sale_price'] !== null && $product['sale_price'] !== ''
            ? round((float) $product['sale_price'], 2)
            : null;

        $base = [
            'product_id' => $productId,
            'pzn' => isset($product['pzn']) ? (string) $product['pzn'] : null,
            'product_name' => isset($product['name']) ? (string) $product['name'] : null,
            'current_price' => $currentSalePrice,
            'suggested_price' => $currentSalePrice,
            'target_rank' => $targetRank,
            'current_rank' => null,
            'minimum_price' => $minimumPrice,
            'reason' => '',
        ];

        if ($targetRank === null || $targetRank <= 0) {
            $base['reason'] = 'Kein Ziel-Ranking gesetzt.';

            return $base;
        }

        $snapshots = $this->repository->findLatestSnapshotsForProduct($productId);
        if ($snapshots === []) {
            $base['reason'] = 'Kein Snapshot-Stand vorhanden.';

            return $base;
        }

        $ownCompetitor = $this->repository->findOwnCompetitor();
        if ($ownCompetitor === null) {
            $base['reason'] = 'Eigener Shop (type=own) nicht konfiguriert.';

            return $base;
        }

        $ownSnapshot = $this->findOwnSnapshot($snapshots, (int) $ownCompetitor['id']);
        if ($ownSnapshot === null) {
            $base['reason'] = 'Kein eigener Preis vorhanden';

            return $base;
        }

        $ownPrice = round((float) ($ownSnapshot['price'] ?? 0), 2);
        $ownShipping = round((float) ($ownSnapshot['shipping_cost'] ?? $product['own_shipping_cost'] ?? 0), 2);
        $ownEndPrice = $ownPrice + $ownShipping;

        $base['current_price'] = $ownPrice;
        $base['current_rank'] = $ownSnapshot['ranking'] !== null ? (int) $ownSnapshot['ranking'] : null;

        if ($base['current_rank'] !== null && $base['current_rank'] <= $targetRank) {
            $base['suggested_price'] = $ownPrice;
            $base['reason'] = 'Zielranking bereits erreicht';

            return $base;
        }

        $rankable = $this->buildRankableList($snapshots);
        if ($rankable === []) {
            $base['reason'] = 'Keine vergleichbaren Wettbewerberpreise im letzten Stand.';

            return $base;
        }

        usort($rankable, static function (array $a, array $b): int {
            if ($a['end_price'] === $b['end_price']) {
                return $a['id'] <=> $b['id'];
            }

            return $a['end_price'] <=> $b['end_price'];
        });

        $benchmarkIndex = $targetRank - 1;
        if ($benchmarkIndex >= count($rankable)) {
            $base['reason'] = 'Zu wenige Wettbewerber für Ziel-Ranking ' . $targetRank . '.';

            return $base;
        }

        $benchmarkEnd = (float) $rankable[$benchmarkIndex]['end_price'];
        $suggestedEnd = round($benchmarkEnd - 0.01, 2);
        $suggestedPrice = round($suggestedEnd - $ownShipping, 2);

        if ($minimumPrice !== null && $suggestedPrice < $minimumPrice) {
            $suggestedPrice = $minimumPrice;
            $base['suggested_price'] = $suggestedPrice;
            $base['reason'] = 'Mindestpreis begrenzt Vorschlag';

            return $base;
        }

        if ($suggestedPrice < 0) {
            $suggestedPrice = 0.0;
        }

        $base['suggested_price'] = $suggestedPrice;

        if (abs($suggestedPrice - $ownPrice) < 0.0001) {
            $base['reason'] = 'Zielranking bereits erreicht';
        } else {
            $base['reason'] = 'Preis senken um Rang ' . $targetRank . ' zu erreichen';
        }

        return $base;
    }

    /**
     * @param list<array<string, mixed>> $snapshots
     * @return list<array{id:int,end_price:float}>
     */
    private function buildRankableList(array $snapshots): array
    {
        $rankable = [];

        foreach ($snapshots as $row) {
            if ($this->isUnavailableStatus($this->normalizeStatus($row['delivery_status'] ?? null))) {
                continue;
            }

            $price = (float) ($row['price'] ?? 0);
            $shipping = (float) ($row['shipping_cost'] ?? 0);

            $rankable[] = [
                'id' => (int) $row['id'],
                'end_price' => round($price + $shipping, 2),
            ];
        }

        return $rankable;
    }

    /**
     * @param list<array<string, mixed>> $snapshots
     * @return array<string, mixed>|null
     */
    private function findOwnSnapshot(array $snapshots, int $ownCompetitorId): ?array
    {
        foreach ($snapshots as $row) {
            if ((int) ($row['competitor_id'] ?? 0) === $ownCompetitorId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array{
     *   product_id:int,
     *   pzn:?string,
     *   product_name:?string,
     *   current_price:?float,
     *   suggested_price:?float,
     *   target_rank:?int,
     *   current_rank:?int,
     *   minimum_price:?float,
     *   reason:string
     * }
     */
    private function emptySuggestion(int $productId, string $reason): array
    {
        return [
            'product_id' => $productId,
            'pzn' => null,
            'product_name' => null,
            'current_price' => null,
            'suggested_price' => null,
            'target_rank' => null,
            'current_rank' => null,
            'minimum_price' => null,
            'reason' => $reason,
        ];
    }

    private function normalizeStatus(mixed $status): string
    {
        if (!is_string($status)) {
            return '';
        }

        return strtolower(trim($status));
    }

    private function isUnavailableStatus(string $status): bool
    {
        if ($status === '') {
            return false;
        }

        if (in_array($status, self::AVAILABLE, true)) {
            return false;
        }

        return in_array($status, self::UNAVAILABLE, true);
    }
}
