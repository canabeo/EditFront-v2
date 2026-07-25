<?php

declare(strict_types=1);

namespace EditFront\Http\Controller;

use EditFront\Security\RateLimiter;
use EditFront\Seo\SitemapBuilder;
use EditFront\Support\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Public GET {basePath}/sitemap.xml (no auth). The origin comes from
 * SITE_BASE_URL when set, otherwise it is derived from the request — so the
 * sitemap works out of the box and can be pointed at the real domain via .env.
 */
final class SitemapController
{
    public function __construct(
        private readonly SitemapBuilder $builder,
        private readonly Config $config,
        private readonly RateLimiter $limiter,
    ) {
    }

    public function serve(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // This endpoint is anonymous and, on a cache miss, walks the whole site.
        // Every other public entry point is throttled; this one was not, so one
        // client could pin PHP-FPM workers by varying the query string to slip
        // past any shared HTTP cache.
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!$this->limiter->allow('sitemap', $ip, 30, 60)) {
            $response->getBody()->write('Too many requests');
            return $response->withStatus(429)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $base = trim((string) $this->config->get('site_base_url', ''));
        if ($base === '') {
            $uri = $request->getUri();
            $base = $uri->getScheme() . '://' . $uri->getAuthority();
        }

        $response->getBody()->write($this->builder->build($base));
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }
}
