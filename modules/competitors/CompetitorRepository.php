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
    public function findAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM competitors ORDER BY active DESC, priority ASC, name ASC'
        );

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM competitors WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO competitors (name, url, priority, active, notes, created_at, updated_at)
             VALUES (:name, :url, :priority, :active, :notes, :created_at, :updated_at)'
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
}
