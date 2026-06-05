<?php

declare(strict_types=1);

class UserValidator
{
    public function __construct(private readonly UserRepository $repository)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{data: array<string, mixed>, errors: list<string>}
     */
    public function validateCreate(array $input): array
    {
        return $this->validate($input, null, true);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{data: array<string, mixed>, errors: list<string>}
     */
    public function validateUpdate(array $input, int $excludeId): array
    {
        return $this->validate($input, $excludeId, false);
    }

    /**
     * @return array{data: array<string, mixed>, errors: list<string>}
     */
    private function validate(array $input, ?int $excludeId, bool $requirePassword): array
    {
        $errors = [];

        $username = trim((string) ($input['username'] ?? ''));
        if ($username === '') {
            $errors[] = 'Benutzername ist ein Pflichtfeld.';
        } elseif ($this->repository->usernameExists($username, $excludeId)) {
            $errors[] = 'Dieser Benutzername ist bereits vergeben.';
        }

        $role = self::normalizeRole((string) ($input['role'] ?? ''), $errors);
        $active = !empty($input['active']) ? 1 : 0;

        $password = (string) ($input['password'] ?? '');
        $passwordConfirm = (string) ($input['password_confirm'] ?? '');

        if ($requirePassword) {
            if ($password === '') {
                $errors[] = 'Passwort ist bei der Neuanlage ein Pflichtfeld.';
            }
        }

        if ($password !== '' || $passwordConfirm !== '') {
            if ($password === '') {
                $errors[] = 'Bitte ein neues Passwort eingeben.';
            } elseif (strlen($password) < 6) {
                $errors[] = 'Passwort muss mindestens 6 Zeichen haben.';
            } elseif ($password !== $passwordConfirm) {
                $errors[] = 'Passwort und Bestätigung stimmen nicht überein.';
            }
        }

        $data = [
            'username' => $username,
            'role' => $role,
            'active' => $active,
            'password' => $password !== '' ? $password : null,
        ];

        return ['data' => $data, 'errors' => $errors];
    }

    /**
     * @param list<string> $errors
     */
    public static function normalizeRole(string $role, array &$errors): string
    {
        $normalized = strtolower(trim($role));

        return match ($normalized) {
            'admin' => 'Admin',
            'user' => 'User',
            '' => 'User',
            default => (function () use (&$errors, $role): string {
                $errors[] = 'Rolle muss Admin oder User sein.';
                return 'User';
            })(),
        };
    }
}
