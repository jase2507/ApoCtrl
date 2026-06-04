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
                target_rank, strategy, category, active,
                shop_url, package_size, avp_price, own_shipping_cost,
                created_at, updated_at
            ) VALUES (
                :pzn, :name, :manufacturer, :cost_price, :sale_price, :min_price,
                :target_rank, :strategy, :category, :active,
                :shop_url, :package_size, :avp_price, :own_shipping_cost,
                :created_at, :updated_at
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
            'shop_url' => $data['shop_url'],
            'package_size' => $data['package_size'],
            'avp_price' => $data['avp_price'],
            'own_shipping_cost' => $data['own_shipping_cost'],
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
                shop_url = :shop_url,
                package_size = :package_size,
                avp_price = :avp_price,
                own_shipping_cost = :own_shipping_cost,
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
            'shop_url' => $data['shop_url'],
            'package_size' => $data['package_size'],
            'avp_price' => $data['avp_price'],
            'own_shipping_cost' => $data['own_shipping_cost'],
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

    /**
     * @param array{
     *   product_name:?string,
     *   manufacturer:?string,
     *   package_size:?string,
     *   pzn:?string,
     *   price:?float,
     *   avp_price:?float,
     *   delivery_status:?string
     * } $parsed
     * @param array<string, mixed> $existing
     */
    public function applyShopSyncSuccess(int $id, array $parsed, array $existing): void
    {
        $now = date('Y-m-d H:i:s');
        $fields = [
            'name' => $parsed['product_name'],
            'manufacturer' => $parsed['manufacturer'],
            'package_size' => $parsed['package_size'],
            'avp_price' => $parsed['avp_price'],
            'sale_price' => $parsed['price'],
            'pzn' => $parsed['pzn'],
        ];

        $setParts = [
            'last_shop_sync_at = :last_shop_sync_at',
            'shop_sync_status = :shop_sync_status',
            'shop_sync_error = NULL',
            'updated_at = :updated_at',
        ];
        $params = [
            'id' => $id,
            'last_shop_sync_at' => $now,
            'shop_sync_status' => 'ok',
            'updated_at' => $now,
        ];

        foreach ($fields as $column => $value) {
            if ($value === null) {
                continue;
            }

            if ($column === 'pzn') {
                $current = trim((string) ($existing['pzn'] ?? ''));
                if ($current !== '') {
                    continue;
                }
            } elseif (is_string($value)) {
                $current = trim((string) ($existing[$column] ?? ''));
                if ($current !== '') {
                    continue;
                }
            } elseif (is_float($value) || is_int($value)) {
                $current = $existing[$column] ?? null;
                if ($current !== null && $current !== '') {
                    continue;
                }
            }

            $setParts[] = $column . ' = :' . $column;
            $params[$column] = $value;
        }

        $sql = 'UPDATE products SET ' . implode(', ', $setParts) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * @param array<string, mixed> $patch
     */
    public function applyAutofillPatch(int $id, array $patch): void
    {
        if ($patch === []) {
            $this->touchShopAutofillOk($id);

            return;
        }

        $allowed = ['name', 'manufacturer', 'package_size', 'avp_price', 'sale_price', 'shop_url'];
        $setParts = [
            'last_shop_sync_at = :last_shop_sync_at',
            'shop_sync_status = :shop_sync_status',
            'shop_sync_error = NULL',
            'updated_at = :updated_at',
        ];
        $params = [
            'id' => $id,
            'last_shop_sync_at' => date('Y-m-d H:i:s'),
            'shop_sync_status' => 'ok',
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        foreach ($allowed as $column) {
            if (!array_key_exists($column, $patch)) {
                continue;
            }
            $setParts[] = $column . ' = :' . $column;
            $value = $patch[$column];
            if (in_array($column, ['avp_price', 'sale_price'], true) && $value !== null && $value !== '') {
                $value = (float) $value;
            }
            $params[$column] = $value;
        }

        $sql = 'UPDATE products SET ' . implode(', ', $setParts) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function touchShopAutofillOk(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE products SET
                shop_sync_status = :shop_sync_status,
                shop_sync_error = NULL,
                last_shop_sync_at = :last_shop_sync_at,
                updated_at = :updated_at
             WHERE id = :id'
        );
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            'id' => $id,
            'shop_sync_status' => 'ok',
            'last_shop_sync_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function recordShopSyncError(int $id, string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE products SET
                shop_sync_status = :shop_sync_status,
                shop_sync_error = :shop_sync_error,
                last_shop_sync_at = :last_shop_sync_at,
                updated_at = :updated_at
             WHERE id = :id'
        );

        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            'id' => $id,
            'shop_sync_status' => 'error',
            'shop_sync_error' => substr($error, 0, 500),
            'last_shop_sync_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
