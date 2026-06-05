<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/modules/users/UserRepository.php';
require_once dirname(__DIR__) . '/modules/users/UserValidator.php';

$pdo = Database::getConnection();
Database::initializeSchema($pdo);

$repo = new UserRepository($pdo);
$validator = new UserValidator($repo);

$failures = 0;

function check(bool $condition, string $label, int &$failures): void
{
    if ($condition) {
        echo "[OK] {$label}\n";
        return;
    }

    $failures++;
    echo "[FAIL] {$label}\n";
}

$admin = $pdo->query("SELECT id FROM users WHERE LOWER(role) = 'admin' AND active = 1 ORDER BY id ASC LIMIT 1")->fetchColumn();
$actorId = is_numeric($admin) ? (int) $admin : 1;

$username = 'testuser_' . bin2hex(random_bytes(3));
$result = $validator->validateCreate([
    'username' => $username,
    'password' => 'secret99',
    'password_confirm' => 'secret99',
    'role' => 'user',
    'active' => '1',
]);
check($result['errors'] === [], 'Validierung Neuanlage', $failures);

$userId = $repo->create([
    'username' => $result['data']['username'],
    'password_hash' => password_hash((string) $result['data']['password'], PASSWORD_DEFAULT),
    'role' => $result['data']['role'],
    'active' => 1,
]);
check($userId > 0, 'Benutzer anlegen', $failures);

$row = $repo->findById($userId);
check(
    $row !== null
    && password_verify('secret99', (string) ($row['password_hash'] ?? ''))
    && ($row['password_hash'] ?? '') !== 'secret99',
    'Passwort wird gehasht',
    $failures,
);

$dup = $validator->validateCreate([
    'username' => $username,
    'password' => 'other99',
    'password_confirm' => 'other99',
    'role' => 'user',
]);
check($dup['errors'] !== [], 'Doppelter Username blockiert', $failures);

$adminUserId = $repo->create([
    'username' => 'admintest_' . bin2hex(random_bytes(2)),
    'password_hash' => password_hash('adminpass1', PASSWORD_DEFAULT),
    'role' => 'Admin',
    'active' => 1,
]);
$repo->update($adminUserId, [
    'username' => $repo->findById($adminUserId)['username'],
    'role' => 'Admin',
    'active' => 1,
]);
check(strcasecmp((string) $repo->findById($adminUserId)['role'], 'Admin') === 0, 'Rolle wird gespeichert', $failures);

$onlyAdminId = (int) $pdo->query(
    "SELECT id FROM users WHERE LOWER(role) = 'admin' AND active = 1 ORDER BY id ASC LIMIT 1"
)->fetchColumn();
if ($onlyAdminId > 0) {
    $pdo->exec("UPDATE users SET active = 0 WHERE LOWER(role) = 'admin' AND id != {$onlyAdminId}");
    $pdo->exec("UPDATE users SET active = 1, role = 'Admin' WHERE id = {$onlyAdminId}");
}

$lastAdminBlock = $repo->canDeactivate($onlyAdminId, $actorId === $onlyAdminId ? $onlyAdminId + 999 : $actorId);
check(
    $lastAdminBlock !== null && str_contains($lastAdminBlock, 'letzte'),
    'Letzter Admin kann nicht deaktiviert werden',
    $failures,
);

$selfBlock = $repo->canDeactivate($actorId, $actorId);
check(
    $selfBlock !== null && str_contains($selfBlock, 'eigene'),
    'Eigener Account kann nicht deaktiviert werden',
    $failures,
);

$pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
$pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $adminUserId]);

echo $failures === 0 ? "USER MANAGEMENT TESTS BESTANDEN\n" : "USER MANAGEMENT TESTS FEHLGESCHLAGEN ({$failures})\n";
exit($failures === 0 ? 0 : 1);
