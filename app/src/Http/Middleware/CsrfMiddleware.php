<?php

declare(strict_types=1);

namespace EditFront\Http\Middleware;

use EditFront\Http\UrlHelper;
use EditFront\Plugin\PluginManager;
use EditFront\Security\Csrf;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Rejects any write-method request without a valid CSRF token
 * (body field "csrf" or X-CSRF-Token header). Applies to ALL POSTs (§13).
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** A plugin module endpoint: {base}/api/p/{slug}/{action} — see process(). */
    private const MODULE_PATH_RE = '#/api/p/([a-z][a-z0-9-]{1,39})/([a-z][a-z0-9-]{0,39})$#';

    public function __construct(
        private readonly Csrf $csrf,
        private readonly UrlHelper $url,
        private readonly PluginManager $plugins,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!in_array(strtoupper($request->getMethod()), self::WRITE_METHODS, true)) {
            return $handler->handle($request);
        }

        // The one exemption: a plugin module action its manifest marks public
        // (§6.9). A form on the site's own pages carries no CMS session and no
        // token — a visitor never loads a CMS page — so such an endpoint is
        // guarded by a honeypot and a per-IP rate limit instead.
        //
        // The core keeps NO list of its own: the pattern below confines the
        // exemption to /api/p/<slug>/<action>, so nothing a plugin declares can
        // open a core path. An action not declared public — or one belonging to
        // a disabled or degraded plugin — falls through to the token check and
        // is refused like any other write.
        $path = $request->getUri()->getPath();
        if (preg_match(self::MODULE_PATH_RE, $path, $m) === 1
            && $this->plugins->moduleActionIsPublic($m[1], $m[2])
        ) {
            return $handler->handle($request);
        }

        $body = $request->getParsedBody();
        $token = is_array($body) && isset($body['csrf']) ? $body['csrf'] : null;
        if (!is_string($token) || $token === '') {
            $header = $request->getHeaderLine('X-CSRF-Token');
            $token = $header !== '' ? $header : null;
        }

        if ($this->csrf->verify($token)) {
            return $handler->handle($request);
        }

        $response = new Response(403);
        if ($this->url->isApiPath($request->getUri()->getPath())) {
            $response->getBody()->write((string) json_encode(['error' => 'Invalid CSRF token']));
            return $response->withHeader('Content-Type', 'application/json');
        }
        $response->getBody()->write('403 — недействительный CSRF-токен. Обновите страницу и попробуйте снова.');
        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}
