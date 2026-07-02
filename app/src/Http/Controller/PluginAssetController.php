<?php

declare(strict_types=1);

namespace EditFront\Http\Controller;

use EditFront\Support\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Serves a plugin's client assets (editor_js/css, icons) under the admin
 * session. Path-guarded to plugins/<slug>/ with a mime whitelist — a plugin
 * cannot use this to leak arbitrary files (no traversal, no symlink escape).
 */
final class PluginAssetController
{
    private const MIME = [
        'js' => 'application/javascript; charset=utf-8',
        'mjs' => 'application/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'svg' => 'image/svg+xml',
        'json' => 'application/json; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];

    public function __construct(private readonly Config $config)
    {
    }

    /** @param array<string, string> $args */
    public function serve(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug = $args['slug'] ?? '';
        $rel = $args['path'] ?? '';
        if (preg_match('/^[a-z][a-z0-9-]{1,39}$/', $slug) !== 1) {
            throw new HttpNotFoundException($request);
        }
        if ($rel === '' || str_contains($rel, "\0") || str_contains($rel, '..')) {
            throw new HttpNotFoundException($request);
        }

        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if (!isset(self::MIME[$ext])) {
            throw new HttpNotFoundException($request);
        }

        $pluginRoot = realpath($this->config->cmsDir() . '/plugins/' . $slug);
        $file = realpath($this->config->cmsDir() . '/plugins/' . $slug . '/' . $rel);
        if ($pluginRoot === false || $file === false || !str_starts_with($file, $pluginRoot . '/') || !is_file($file)) {
            throw new HttpNotFoundException($request);
        }
        // never serve server-side code or the manifest/fixtures through this route
        if (str_starts_with($rel, 'src/') || str_starts_with($rel, 'fixtures/') || basename($rel) === 'plugin.json') {
            throw new HttpNotFoundException($request);
        }

        $response->getBody()->write((string) file_get_contents($file));
        return $response
            ->withHeader('Content-Type', self::MIME[$ext])
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
