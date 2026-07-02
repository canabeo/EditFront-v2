<?php

declare(strict_types=1);

namespace EditFront\Http\Controller;

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
    ) {
    }

    public function serve(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
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
