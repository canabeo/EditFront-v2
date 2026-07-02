<?php

declare(strict_types=1);

namespace EditFront\Security;

/**
 * Session-bound CSRF token. The token is exposed to JS via
 * <body data-cms-csrf> and the csrf_token() Twig function (§13).
 */
final class Csrf
{
    private const SESSION_KEY = 'cms_csrf';

    public function token(): string
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(16));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public function verify(?string $token): bool
    {
        $known = $_SESSION[self::SESSION_KEY] ?? null;
        return is_string($token) && $token !== ''
            && is_string($known) && $known !== ''
            && hash_equals($known, $token);
    }
}
