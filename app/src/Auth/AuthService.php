<?php

declare(strict_types=1);

namespace EditFront\Auth;

/**
 * Session-based auth state. Sliding expiry: every authenticated request
 * extends the session lifetime.
 */
final class AuthService
{
    private const KEY_USER = 'cms_user';
    private const KEY_EXPIRES = 'cms_expires_at';
    private const TTL_SEC = 28800; // 8 hours, sliding

    public function __construct(private readonly AdminStore $admins)
    {
    }

    public function attempt(string $username, string $password): bool
    {
        $this->admins->ensureBootstrap();
        if (!$this->admins->verify($username, $password)) {
            return false;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION[self::KEY_USER] = $username;
        $_SESSION[self::KEY_EXPIRES] = time() + self::TTL_SEC;
        return true;
    }

    /**
     * Change the logged-in admin's password. Verifies the current password
     * first; the new hash uses bcrypt cost 12 (same as the verification dummy
     * hash, keeps timing consistent). Returns false if no one is logged in or
     * the current password is wrong.
     */
    public function changePassword(string $current, string $new): bool
    {
        $username = $this->user();
        if ($username === null || !$this->admins->verify($username, $current)) {
            return false;
        }
        $this->admins->setPassword(password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]));
        return true;
    }

    public function check(): bool
    {
        $user = $_SESSION[self::KEY_USER] ?? null;
        $expires = $_SESSION[self::KEY_EXPIRES] ?? 0;
        if (!is_string($user) || $user === '' || !is_int($expires) || time() >= $expires) {
            return false;
        }
        $_SESSION[self::KEY_EXPIRES] = time() + self::TTL_SEC;
        return true;
    }

    public function user(): ?string
    {
        $user = $_SESSION[self::KEY_USER] ?? null;
        return is_string($user) && $user !== '' ? $user : null;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
            session_destroy();
        }
    }
}
