<?php

declare(strict_types=1);

/**
 * ApoCtrl – Login-Rate-Limit (Session-basiert)
 */

class LoginThrottle
{
    private const SESSION_KEY = 'login_throttle';
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_SECONDS = 900;

    public static function isLocked(): bool
    {
        $data = self::getData();

        if ($data === null) {
            return false;
        }

        if ($data['count'] < self::MAX_ATTEMPTS) {
            return false;
        }

        if (time() - $data['first_attempt'] > self::WINDOW_SECONDS) {
            self::reset();

            return false;
        }

        return true;
    }

    public static function recordFailure(): void
    {
        $data = self::getData();
        $now = time();

        if ($data === null || ($now - $data['first_attempt']) > self::WINDOW_SECONDS) {
            $data = ['count' => 0, 'first_attempt' => $now];
        }

        $data['count']++;
        $_SESSION[self::SESSION_KEY] = $data;
    }

    public static function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    public static function remainingSeconds(): int
    {
        $data = self::getData();

        if ($data === null) {
            return 0;
        }

        $elapsed = time() - $data['first_attempt'];
        $remaining = self::WINDOW_SECONDS - $elapsed;

        return max(0, $remaining);
    }

    public static function maxAttempts(): int
    {
        return self::MAX_ATTEMPTS;
    }

    private static function getData(): ?array
    {
        $data = $_SESSION[self::SESSION_KEY] ?? null;

        return is_array($data) ? $data : null;
    }

    private static function reset(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }
}
