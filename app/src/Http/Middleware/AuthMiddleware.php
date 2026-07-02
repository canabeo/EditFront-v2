<?php

declare(strict_types=1);

namespace EditFront\Http\Middleware;

use EditFront\Auth\AuthService;
use EditFront\Http\UrlHelper;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Route-group guard: browser requests are redirected to login,
 * API requests get 401 JSON (never a 302 HTML page).
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly UrlHelper $url,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->auth->check()) {
            return $handler->handle($request);
        }

        if ($this->url->isApiPath($request->getUri()->getPath())) {
            $response = new Response(401);
            $response->getBody()->write((string) json_encode(['error' => 'Unauthorized']));
            return $response->withHeader('Content-Type', 'application/json');
        }

        return (new Response(302))->withHeader('Location', $this->url->path('login'));
    }
}
