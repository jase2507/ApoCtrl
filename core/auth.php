<?php

declare(strict_types=1);

/**
 * ApoCtrl – Authentifizierung und Session-Verwaltung
 */

class Auth
{
    private const SESSION_USER_KEY = 'auth_user';
    private const SESSION_LAST_ACTIVITY = 'auth_last_activity';

    /** @var array<string, mixed>|null */
    private static ?array $config = null;

    /**
     * @param array<string, mixed> $config
     */
    public static function setConfig(array $config): void
    {
        self::$config = $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function initSession(array $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');

        $sessionConfig = $config['session'];

        session_name($sessionConfig['name']);
        session_set_cookie_params([
            'lifetime' => $sessionConfig['cookie_lifetime'],
            'path' => $sessionConfig['cookie_path'],
            'httponly' => $sessionConfig['cookie_httponly'],
            'samesite' => $sessionConfig['cookie_samesite'],
            'secure' => self::isHttps(),
        ]);

        session_start();
    }

    public static function login(string $username, string $password): bool
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare(
            'SELECT id, username, password_hash, role, created_at
             FROM users
             WHERE username = :username
             LIMIT 1'
        );
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            LoginThrottle::recordFailure();
            self::logAudit(null, 'login_failed', 'Benutzername: ' . $username);

            return false;
        }

        LoginThrottle::clear();
        clearFlashes();

        session_regenerate_id(true);

        $_SESSION[self::SESSION_USER_KEY] = [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
        ];
        $_SESSION[self::SESSION_LAST_ACTIVITY] = time();

        Csrf::regenerateToken();

        self::logAudit((int) $user['id'], 'login_success', 'Erfolgreiche Anmeldung');

        return true;
    }

    public static function logout(): void
    {
        $user = self::getUser();

        if ($user !== null) {
            self::logAudit($user['id'], 'logout', 'Benutzer abgemeldet');
        }

        $sessionName = session_name();
        $params = session_get_cookie_params();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            setcookie($sessionName, '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'] !== '' ? $params['domain'] : '',
                'secure' => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }

    public static function isLoggedIn(): bool
    {
        return self::getUser() !== null;
    }

    public static function getUser(): ?array
    {
        if (!isset($_SESSION[self::SESSION_USER_KEY])) {
            return null;
        }

        return $_SESSION[self::SESSION_USER_KEY];
    }

    public static function isSessionTimedOut(int $timeout): bool
    {
        if (!self::isLoggedIn()) {
            return false;
        }

        $lastActivity = (int) ($_SESSION[self::SESSION_LAST_ACTIVITY] ?? 0);

        return $lastActivity > 0 && (time() - $lastActivity) > $timeout;
    }

    public static function touchSession(): void
    {
        if (self::isLoggedIn()) {
            $_SESSION[self::SESSION_LAST_ACTIVITY] = time();
        }
    }

    public static function requireAuth(int $timeout): void
    {
        if (!self::isLoggedIn()) {
            clearFlashes();
            redirect('login.php');
        }

        if (self::isSessionTimedOut($timeout)) {
            self::logAudit(
                self::getUser()['id'] ?? null,
                'session_timeout',
                'Session abgelaufen nach ' . $timeout . ' Sekunden'
            );

            unset(
                $_SESSION[self::SESSION_USER_KEY],
                $_SESSION[self::SESSION_LAST_ACTIVITY]
            );

            session_regenerate_id(true);
            Csrf::regenerateToken();

            flash('error', 'Ihre Sitzung ist abgelaufen. Bitte melden Sie sich erneut an.');
            redirect('login.php');
        }

        self::touchSession();
    }

    public static function requireGuest(): void
    {
        if (self::isLoggedIn()) {
            redirect('index.php');
        }
    }

    public static function isAdmin(): bool
    {
        $user = self::getUser();

        return $user !== null
            && strcasecmp((string) ($user['role'] ?? ''), 'Admin') === 0;
    }

    public static function requireAdmin(): void
    {
        if (!self::isAdmin()) {
            flash('error', 'Keine Berechtigung für diese Aktion.');
            redirect('products.php');
        }
    }

    /**
     * Prüft, ob der Admin noch das bekannte Standard-Passwort (admin/admin123) nutzt.
     */
    public static function hasDefaultAdminCredentials(): bool
    {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare(
                'SELECT password_hash FROM users WHERE username = :username LIMIT 1'
            );
            $stmt->execute(['username' => 'admin']);
            $hash = $stmt->fetchColumn();

            if ($hash === false || !is_string($hash)) {
                return false;
            }

            $knownWeak = ['admin123', 'admin', 'password', 'changeme'];

            foreach ($knownWeak as $weak) {
                if (password_verify($weak, $hash)) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            return false;
        }
    }

    public static function logAudit(?int $userId, string $action, ?string $details = null): void
    {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare(
                'INSERT INTO audit_logs (user_id, action, details, created_at)
                 VALUES (:user_id, :action, :details, :created_at)'
            );
            $stmt->execute([
                'user_id' => $userId,
                'action' => $action,
                'details' => $details,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            logError('Audit-Log fehlgeschlagen: ' . $e->getMessage());
        }
    }

    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return true;
        }

        return false;
    }
}
