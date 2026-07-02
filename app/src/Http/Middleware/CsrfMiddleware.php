<?php

declare(strict_types=1);

namespace EditFront\Http\Middleware;

use EditFront\Http\UrlHelper;
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

    /** Anonymous public POST endpoints exempt from CSRF (see process()). */
    private const PUBLIC_PATHS = ['/api/reviews/submit'];

    public function __construct(
        private readonly Csrf $csrf,
        private readonly UrlHelper $url,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!in_array(strtoupper($request->getMethod()), self::WRITE_METHODS, true)) {
            return $handler->handle($request);
        }

        // Anonymous public endpoints that legitimately carry no CMS session/
        // token (a site visitor submitting a review never loads a CMS page).
        // Unprivileged (only enqueues a pending review); protected by a
        // honeypot + per-IP rate-limit instead. Match by exact path suffix so
        // basePath does not matter.
        $path = $request->getUri()->getPath();
        foreach (self::PUBLIC_PATHS as $suffix) {
            if (str_ends_with($path, $suffix)) {
                return $handler->handle($request);
            }
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
