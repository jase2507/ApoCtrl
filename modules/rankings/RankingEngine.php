<?php

declare(strict_types=1);

class RankingEngine
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

    public function __construct(private readonly RankingRepository $repository)
    {
    }

    /**
     * @return array{
     *   groups:int,
     *   rows:int,
     *   ranked:int,
     *   ignored:int,
     *   errors:int,
     *   details:list<array<string,mixed>>
     * }
     */
    public function runAll(): array
    {
        $groups = $this->repository->fetchGroups(null);
        return $this->processGroups($groups);
    }

    /**
     * @return array{
     *   groups:int,
     *   rows:int,
     *   ranked:int,
     *   ignored:int,
     *   errors:int,
     *   details:list<array<string,mixed>>
     * }
     */
    public function runForProduct(int $productId): array
    {
        $groups = $this->repository->fetchGroups($productId);
        return $this->processGroups($groups);
    }

    /**
     * @param list<array{product_id:int,captured_at:string}> $groups
     * @return array{
     *   groups:int,
     *   rows:int,
     *   ranked:int,
     *   ignored:int,
     *   errors:int,
     *   details:list<array<string,mixed>>
     * }
     */
    private function processGroups(array $groups): array
    {
        $summary = [
            'groups' => 0,
            'rows' => 0,
            'ranked' => 0,
            'ignored' => 0,
            'errors' => 0,
            'details' => [],
        ];

        foreach ($groups as $group) {
            $summary['groups']++;
            $productId = (int) $group['product_id'];
            $capturedAt = (string) $group['captured_at'];

            try {
                $groupResult = $this->rankGroup($productId, $capturedAt);
                $summary['rows'] += $groupResult['rows'];
                $summary['ranked'] += $groupResult['ranked'];
                $summary['ignored'] += $groupResult['ignored'];
                $summary['details'][] = $groupResult;
            } catch (Throwable $e) {
                $summary['errors']++;
                logError('Ranking-Fehler Gruppe product_id=' . $productId . ', captured_at=' . $capturedAt . ': ' . $e->getMessage());
            }
        }

        return $summary;
    }

    /**
     * @return array{
     *   product_id:int,
     *   pzn:string,
     *   product_name:string,
     *   captured_at:string,
     *   rows:int,
     *   ranked:int,
     *   ignored:int
     * }
     */
    private function rankGroup(int $productId, string $capturedAt): array
    {
        $rows = $this->repository->fetchSnapshotsForGroup($productId, $capturedAt);

        $this->repository->clearGroupRankings($productId, $capturedAt);

        if ($rows === []) {
            return [
                'product_id' => $productId,
                'pzn' => '',
                'product_name' => '',
                'captured_at' => $capturedAt,
                'rows' => 0,
                'ranked' => 0,
                'ignored' => 0,
            ];
        }

        $rankable = [];
        $ignoredCount = 0;

        foreach ($rows as $row) {
            $status = $this->normalizeStatus($row['delivery_status'] ?? null);
            if ($this->isUnavailableStatus($status)) {
                $ignoredCount++;
                continue;
            }

            $price = (float) ($row['price'] ?? 0);
            $shipping = (float) ($row['shipping_cost'] ?? 0);
            $rankable[] = [
                'id' => (int) $row['id'],
                'end_price' => $price + $shipping,
            ];
        }

        usort($rankable, static function (array $a, array $b): int {
            if ($a['end_price'] === $b['end_price']) {
                return $a['id'] <=> $b['id'];
            }
            return $a['end_price'] <=> $b['end_price'];
        });

        $lastEndPrice = null;
        $lastRank = 0;

        foreach ($rankable as $index => $item) {
            if ($lastEndPrice !== null && abs((float) $item['end_price'] - (float) $lastEndPrice) < 0.0000001) {
                $rank = $lastRank;
            } else {
                $rank = $index + 1;
                $lastRank = $rank;
                $lastEndPrice = (float) $item['end_price'];
            }

            $this->repository->updateSnapshotRanking((int) $item['id'], $rank);
        }

        return [
            'product_id' => $productId,
            'pzn' => (string) ($rows[0]['pzn'] ?? ''),
            'product_name' => (string) ($rows[0]['product_name'] ?? ''),
            'captured_at' => $capturedAt,
            'rows' => count($rows),
            'ranked' => count($rankable),
            'ignored' => $ignoredCount,
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
