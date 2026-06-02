<?php

declare(strict_types=1);

/**
 * ApoCtrl – CSRF-Schutz (Grundlage)
 */

class Csrf
{
    private static string $tokenName = '_csrf_token';
    private static int $tokenLength = 32;

    public static function init(array $config): void
    {
        self::$tokenName = $config['csrf']['token_name'];
        self::$tokenLength = (int) $config['csrf']['token_length'];

        if (!isset($_SESSION[self::$tokenName])) {
            self::regenerateToken();
        }
    }

    public static function regenerateToken(): string
    {
        $token = bin2hex(random_bytes(self::$tokenLength));
        $_SESSION[self::$tokenName] = $token;

        return $token;
    }

    public static function getToken(): string
    {
        if (!isset($_SESSION[self::$tokenName])) {
            return self::regenerateToken();
        }

        return $_SESSION[self::$tokenName];
    }

    public static function getTokenName(): string
    {
        return self::$tokenName;
    }

    public static function field(): string
    {
        $name = htmlspecialchars(self::$tokenName, ENT_QUOTES, 'UTF-8');
        $value = htmlspecialchars(self::getToken(), ENT_QUOTES, 'UTF-8');

        return '<input type="hidden" name="' . $name . '" value="' . $value . '">';
    }

    public static function validate(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        if (!isset($_SESSION[self::$tokenName])) {
            return false;
        }

        return hash_equals($_SESSION[self::$tokenName], $token);
    }

    public static function validateRequest(): bool
    {
        $token = $_POST[self::$tokenName] ?? null;

        return self::validate(is_string($token) ? $token : null);
    }
}
