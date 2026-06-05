<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/modules/users/UserRepository.php';
require_once dirname(__DIR__) . '/modules/users/UserValidator.php';

Auth::requireAuth($config['session']['timeout']);
Auth::requireAdmin();

$pdo = Database::getConnection();
$repository = new UserRepository($pdo);
$validator = new UserValidator($repository);

$action = query('action', 'list') ?? 'list';
$currentNav = 'users';
$user = Auth::getUser();
$actorId = (int) ($user['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
    $postAction = post('action', '');

    if ($postAction === 'store') {
        $result = $validator->validateCreate($_POST);
        if ($result['errors'] !== []) {
            renderLayout('modules/users/form.php', [
                'pageTitle' => 'Benutzer anlegen',
                'currentNav' => $currentNav,
                'user' => $user,
                'config' => $config,
                'account' => null,
                'errors' => $result['errors'],
                'formAction' => 'store',
                'isEdit' => false,
            ]);
            exit;
        }

        $data = $result['data'];
        $id = $repository->create([
            'username' => $data['username'],
            'password_hash' => password_hash((string) $data['password'], PASSWORD_DEFAULT),
            'role' => $data['role'],
            'active' => (int) $data['active'],
        ]);

        Auth::logAudit(
            $actorId,
            'user_created',
            'user_id=' . $id . ', username=' . $data['username'] . ', role=' . $data['role'],
        );
        flash('success', 'Benutzer wurde erfolgreich angelegt.');
        redirect('users.php');
    }

    if ($postAction === 'update') {
        $id = (int) post('id', '0');
        $existing = $repository->findById($id);

        if ($existing === null) {
            flash('error', 'Benutzer nicht gefunden.');
            redirect('users.php');
        }

        $result = $validator->validateUpdate($_POST, $id);
        if ($result['errors'] !== []) {
            renderLayout('modules/users/form.php', [
                'pageTitle' => 'Benutzer bearbeiten',
                'currentNav' => $currentNav,
                'user' => $user,
                'config' => $config,
                'account' => array_merge($existing, $_POST, ['id' => $id]),
                'errors' => $result['errors'],
                'formAction' => 'update',
                'isEdit' => true,
            ]);
            exit;
        }

        $data = $result['data'];

        if ((int) $data['active'] === 0) {
            $blockReason = $repository->canDeactivate($id, $actorId);
            if ($blockReason !== null) {
                flash('error', $blockReason);
                redirect('users.php?action=edit&id=' . $id);
            }
        }

        $repository->update($id, [
            'username' => $data['username'],
            'role' => $data['role'],
            'active' => (int) $data['active'],
        ]);

        $auditParts = ['user_id=' . $id, 'username=' . $data['username'], 'role=' . $data['role']];
        if ($data['password'] !== null) {
            $repository->updatePassword($id, password_hash((string) $data['password'], PASSWORD_DEFAULT));
            Auth::logAudit($actorId, 'user_password_changed', 'user_id=' . $id);
            $auditParts[] = 'password_changed=1';
        }

        Auth::logAudit($actorId, 'user_updated', implode(', ', $auditParts));
        flash('success', 'Benutzer wurde gespeichert.');
        redirect('users.php');
    }

    if ($postAction === 'toggle-active') {
        $id = (int) post('id', '0');
        $existing = $repository->findById($id);

        if ($existing === null) {
            flash('error', 'Benutzer nicht gefunden.');
            redirect('users.php');
        }

        $isActive = (int) ($existing['active'] ?? 0) === 1;
        $newActive = $isActive ? 0 : 1;

        if ($newActive === 0) {
            $blockReason = $repository->canDeactivate($id, $actorId);
            if ($blockReason !== null) {
                flash('error', $blockReason);
                redirect('users.php');
            }
        }

        $repository->setActive($id, $newActive);
        Auth::logAudit(
            $actorId,
            $newActive === 1 ? 'user_activated' : 'user_deactivated',
            'user_id=' . $id . ', username=' . ($existing['username'] ?? ''),
        );

        flash('success', $newActive === 1 ? 'Benutzer wurde aktiviert.' : 'Benutzer wurde deaktiviert.');
        redirect('users.php');
    }

    flash('error', 'Unbekannte Aktion.');
    redirect('users.php');
}

match ($action) {
    'list' => (function () use ($repository, $config, $currentNav, $user, $actorId): void {
        renderLayout('modules/users/list.php', [
            'pageTitle' => 'Benutzer',
            'currentNav' => $currentNav,
            'user' => $user,
            'config' => $config,
            'users' => $repository->findAll(),
            'currentUserId' => $actorId,
        ]);
    })(),

    'create' => (function () use ($config, $currentNav, $user): void {
        renderLayout('modules/users/form.php', [
            'pageTitle' => 'Benutzer anlegen',
            'currentNav' => $currentNav,
            'user' => $user,
            'config' => $config,
            'account' => null,
            'errors' => [],
            'formAction' => 'store',
            'isEdit' => false,
        ]);
    })(),

    'edit' => (function () use ($repository, $config, $currentNav, $user): void {
        $id = (int) (query('id', '0') ?? '0');
        $account = $repository->findById($id);

        if ($account === null) {
            flash('error', 'Benutzer nicht gefunden.');
            redirect('users.php');
        }

        unset($account['password_hash']);

        renderLayout('modules/users/form.php', [
            'pageTitle' => 'Benutzer bearbeiten',
            'currentNav' => $currentNav,
            'user' => $user,
            'config' => $config,
            'account' => $account,
            'errors' => [],
            'formAction' => 'update',
            'isEdit' => true,
        ]);
    })(),

    default => (function (): void {
        flash('error', 'Unbekannte Aktion.');
        redirect('users.php');
    })(),
};
