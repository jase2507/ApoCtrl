<?php

declare(strict_types=1);

class CompetitorRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(bool $includeTest = false): array
    {
        $sql = 'SELECT * FROM competitors';
        if (!$includeTest) {
            $sql .= ' WHERE is_test = 0';
        }
        $sql .= ' ORDER BY priority ASC, name ASC';

        return $this->pdo->query($sql)->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM competitors WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function existsByName(string $name, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM competitors WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name)) AND is_test = 0';
        $params = ['name' => $name];

        if ($excludeId !== null) {
            $sql .= ' AND id != :id';
            $params['id'] = $excludeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function hasReferences(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM price_snapshots WHERE competitor_id = :id'
        );
        $stmt->execute(['id' => $id]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function deleteById(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM competitors WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }

    /**
     * @return list<array{name:string,count:int,ids:string}>
     */
    public function findDuplicates(): array
    {
        $stmt = $this->pdo->query(
            "SELECT
                MIN(name) AS name,
                COUNT(*) AS count,
                GROUP_CONCAT(id, ',') AS ids
             FROM competitors
             WHERE is_test = 0
             GROUP BY LOWER(TRIM(name))
             HAVING COUNT(*) > 1
             ORDER BY name ASC"
        );

        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO competitors (name, url, priority, active, is_test, notes, created_at, updated_at)
             VALUES (:name, :url, :priority, :active, 0, :notes, :created_at, :updated_at)'
        );

        $stmt->execute([
            'name' => $data['name'],
            'url' => $data['url'],
            'priority' => $data['priority'],
            'active' => $data['active'],
            'notes' => $data['notes'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE competitors SET
                name = :name,
                url = :url,
                priority = :priority,
                active = :active,
                notes = :notes,
                updated_at = :updated_at
             WHERE id = :id'
        );

        return $stmt->execute([
            'id' => $id,
            'name' => $data['name'],
            'url' => $data['url'],
            'priority' => $data['priority'],
            'active' => $data['active'],
            'notes' => $data['notes'],
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function setActive(int $id, int $active): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE competitors SET active = :active, updated_at = :updated_at WHERE id = :id'
        );

        return $stmt->execute([
            'id' => $id,
            'active' => $active,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Bereinigt Testdaten und führt doppelte DocMorris-Einträge zusammen.
     *
     * @return array{deleted_phase4:int, deactivated_phase4:int, merged_docmorris:int, kept_docmorris_id:int|null}
     */
    public function cleanupTestDataAndDuplicates(): array
    {
        $deletedPhase4 = 0;
        $mergedDocmorris = 0;
        $keptDocmorrisId = null;
        $deactivatedPhase4 = 0;

        $phase4Stmt = $this->pdo->prepare(
            "SELECT id FROM competitors WHERE name IN ('Phase4-A', 'Phase4-B', 'Phase4-C', 'Phase4-D')"
        );
        $phase4Stmt->execute();
        $phase4Ids = array_map(static fn(array $r): int => (int) $r['id'], $phase4Stmt->fetchAll());

        if ($phase4Ids !== []) {
            foreach ($phase4Ids as $phase4Id) {
                if ($this->hasReferences($phase4Id)) {
                    $mark = $this->pdo->prepare(
                        'UPDATE competitors SET is_test = 1, active = 0, updated_at = :updated_at WHERE id = :id'
                    );
                    $mark->execute([
                        'id' => $phase4Id,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    $deactivatedPhase4++;
                    continue;
                }

                $delete = $this->pdo->prepare('DELETE FROM competitors WHERE id = :id');
                $delete->execute(['id' => $phase4Id]);
                $deletedPhase4 += $delete->rowCount();
            }
        }

        $docStmt = $this->pdo->prepare(
            "SELECT id FROM competitors WHERE LOWER(TRIM(name)) = 'docmorris' AND is_test = 0 ORDER BY id ASC"
        );
        $docStmt->execute();
        $docIds = array_map(static fn(array $r): int => (int) $r['id'], $docStmt->fetchAll());

        if (count($docIds) > 1) {
            $preferred = $this->pdo->prepare(
                "SELECT id
                 FROM competitors
                 WHERE LOWER(TRIM(name)) = 'docmorris' AND is_test = 0
                 ORDER BY (url IS NULL OR TRIM(url) = '') ASC, id ASC
                 LIMIT 1"
            );
            $preferred->execute();
            $preferredId = $preferred->fetchColumn();
            $keptDocmorrisId = $preferredId !== false ? (int) $preferredId : array_shift($docIds);

            $docIds = array_values(array_filter($docIds, static fn(int $id): bool => $id !== $keptDocmorrisId));
            $mergedDocmorris = count($docIds);

            foreach ($docIds as $dupId) {
                $upd = $this->pdo->prepare(
                    'UPDATE price_snapshots SET competitor_id = :keep WHERE competitor_id = :dup'
                );
                $upd->execute(['keep' => $keptDocmorrisId, 'dup' => $dupId]);
            }

            $placeholders = implode(',', array_fill(0, count($docIds), '?'));
            $del = $this->pdo->prepare("DELETE FROM competitors WHERE id IN ({$placeholders})");
            $del->execute($docIds);
        } elseif (count($docIds) === 1) {
            $keptDocmorrisId = $docIds[0];
        }

        return [
            'deleted_phase4' => $deletedPhase4,
            'deactivated_phase4' => $deactivatedPhase4,
            'merged_docmorris' => $mergedDocmorris,
            'kept_docmorris_id' => $keptDocmorrisId,
        ];
    }
}
