<?php

declare(strict_types=1);

class ProductRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(?string $search, string $activeFilter): array
    {
        $sql = 'SELECT * FROM products WHERE 1=1';
        $params = [];

        if ($activeFilter === 'active') {
            $sql .= ' AND active = 1';
        } elseif ($activeFilter === 'inactive') {
            $sql .= ' AND active = 0';
        }

        if ($search !== null && $search !== '') {
            $sql .= ' AND (pzn LIKE :q OR name LIKE :q OR manufacturer LIKE :q)';
            $params['q'] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY active DESC, name ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function pznExists(string $pzn, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM products WHERE pzn = :pzn';
        $params = ['pzn' => $pzn];

        if ($excludeId !== null) {
            $sql .= ' AND id != :id';
            $params['id'] = $excludeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (
                pzn, name, manufacturer, cost_price, sale_price, min_price,
                target_rank, strategy, category, active, created_at, updated_at
            ) VALUES (
                :pzn, :name, :manufacturer, :cost_price, :sale_price, :min_price,
                :target_rank, :strategy, :category, :active, :created_at, :updated_at
            )'
        );

        $stmt->execute([
            'pzn' => $data['pzn'],
            'name' => $data['name'],
            'manufacturer' => $data['manufacturer'],
            'cost_price' => $data['cost_price'],
            'sale_price' => $data['sale_price'],
            'min_price' => $data['min_price'],
            'target_rank' => $data['target_rank'],
            'strategy' => $data['strategy'],
            'category' => $data['category'],
            'active' => $data['active'],
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
            'UPDATE products SET
                pzn = :pzn,
                name = :name,
                manufacturer = :manufacturer,
                cost_price = :cost_price,
                sale_price = :sale_price,
                min_price = :min_price,
                target_rank = :target_rank,
                strategy = :strategy,
                category = :category,
                active = :active,
                updated_at = :updated_at
             WHERE id = :id'
        );

        return $stmt->execute([
            'id' => $id,
            'pzn' => $data['pzn'],
            'name' => $data['name'],
            'manufacturer' => $data['manufacturer'],
            'cost_price' => $data['cost_price'],
            'sale_price' => $data['sale_price'],
            'min_price' => $data['min_price'],
            'target_rank' => $data['target_rank'],
            'strategy' => $data['strategy'],
            'category' => $data['category'],
            'active' => $data['active'],
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function setActive(int $id, int $active): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE products SET active = :active, updated_at = :updated_at WHERE id = :id'
        );

        return $stmt->execute([
            'id' => $id,
            'active' => $active,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
