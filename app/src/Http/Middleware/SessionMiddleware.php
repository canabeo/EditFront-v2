<?php

declare(strict_types=1);

namespace EditFront\Http\Middleware;

use EditFront\Http\UrlHelper;
use EditFront\Support\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Starts the PHP session with cookie scoped to basePath (invariant 0.2.5),
 * HttpOnly + SameSite=Lax + Secure auto-detected behind proxies.
 */
final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly UrlHelper $url,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (session_status() === PHP_SESSION_NONE) {
            $dir = $this->config->storageDir() . '/sessions';
            if (!is_dir($dir)) {
                @mkdir($dir, 0770, true);
            }
            // Reject an attacker-supplied, never-initialised session id (session
            // fixation defence at the engine level). We also regenerate the id on
            // login (AuthService::attempt) — this is defence-in-depth on top.
            ini_set('session.use_strict_mode', '1');
            session_save_path($dir);
            session_name($this->config->sessionName());
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => $this->url->cookiePath(),
                'domain' => '',
                'secure' => $this->isHttps($request),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
        return $handler->handle($request);
    }

    private function isHttps(ServerRequestInterface $request): bool
    {
        if ($request->getUri()->getScheme() === 'https') {
            return true;
        }
        if (strtolower($request->getHeaderLine('X-Forwarded-Proto')) === 'https') {
            return true;
        }
        $https = $request->getServerParams()['HTTPS'] ?? '';
        return $https !== '' && strtolower((string) $https) !== 'off';
    }
}
