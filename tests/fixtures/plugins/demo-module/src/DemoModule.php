<?php

declare(strict_types=1);

namespace EditFront\Tests\Fixtures;

use EditFront\Plugin\ModuleCtx;
use EditFront\Plugin\PluginModule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The smallest thing that is a module (§6.9): one public action, one that needs
 * a login. The core's tests use this instead of a real plugin so removing every
 * plugin from a checkout still leaves the suite green.
 */
final class DemoModule implements PluginModule
{
    public function handle(string $action, ServerRequestInterface $request, ResponseInterface $response, ModuleCtx $ctx): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'ok' => true,
            'action' => $action,
            'storage' => $ctx->storageDir(),
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function page(ServerRequestInterface $request, ResponseInterface $response, ModuleCtx $ctx): ResponseInterface
    {
        $response->getBody()->write('<h1>demo</h1>');

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
