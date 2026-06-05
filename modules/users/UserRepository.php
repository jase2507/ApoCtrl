<?php

declare(strict_types=1);

class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        return $this->pdo->query(
            'SELECT id, username, role, active, created_at, updated_at
             FROM users
             ORDER BY username ASC'
        )->fetchAll() ?: [];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, username, password_hash, role, active, created_at, updated_at
             FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function usernameExists(string $username, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM users WHERE LOWER(TRIM(username)) = LOWER(TRIM(:username))';
        $params = ['username' => $username];

        if ($excludeId !== null) {
            $sql .= ' AND id != :id';
            $params['id'] = $excludeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function countActiveAdmins(): int
    {
        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM users WHERE active = 1 AND LOWER(TRIM(role)) = 'admin'"
        );

        return (int) $stmt->fetchColumn();
    }

    public function canDeactivate(int $targetUserId, int $actorUserId): ?string
    {
        if ($targetUserId === $actorUserId) {
            return 'Der eigene Account kann nicht deaktiviert werden.';
        }

        $target = $this->findById($targetUserId);
        if ($target === null) {
            return 'Benutzer nicht gefunden.';
        }

        if ((int) ($target['active'] ?? 0) !== 1) {
            return null;
        }

        if (strcasecmp((string) ($target['role'] ?? ''), 'Admin') === 0 && $this->countActiveAdmins() <= 1) {
            return 'Der letzte aktive Administrator kann nicht deaktiviert werden.';
        }

        return null;
    }

    /**
     * @param array{username:string,password_hash:string,role:string,active:int} $data
     */
    public function create(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, password_hash, role, active, created_at, updated_at)
             VALUES (:username, :password_hash, :role, :active, :created_at, :updated_at)'
        );
        $stmt->execute([
            'username' => $data['username'],
            'password_hash' => $data['password_hash'],
            'role' => $data['role'],
            'active' => (int) $data['active'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array{username:string,role:string,active:int} $data
     */
    public function update(int $id, array $data): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET
                username = :username,
                role = :role,
                active = :active,
                updated_at = :updated_at
             WHERE id = :id'
        );

        return $stmt->execute([
            'id' => $id,
            'username' => $data['username'],
            'role' => $data['role'],
            'active' => (int) $data['active'],
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function updatePassword(int $id, string $passwordHash): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id'
        );

        return $stmt->execute([
            'id' => $id,
            'password_hash' => $passwordHash,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function setActive(int $id, int $active): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET active = :active, updated_at = :updated_at WHERE id = :id'
        );

        return $stmt->execute([
            'id' => $id,
            'active' => $active,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
