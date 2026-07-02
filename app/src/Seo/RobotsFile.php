<?php

declare(strict_types=1);

namespace EditFront\Seo;

use EditFront\Http\UrlHelper;
use EditFront\Storage\FileStorage;

/**
 * Keeps a robots.txt at the SITE ROOT (where crawlers look) that advertises the
 * sitemap — so pages become discoverable for indexing. Non-destructive: if the
 * site already has a robots.txt we only APPEND a Sitemap: line when none is
 * present, never touching the owner's existing rules. Best-effort by contract
 * (the caller never lets a robots.txt failure break an SEO save).
 */
final class RobotsFile
{
    public function __construct(
        private readonly FileStorage $storage,
        private readonly UrlHelper $url,
    ) {
    }

    /** @param string $baseUrl scheme://host (trailing slash tolerated) */
    public function ensure(string $baseUrl): void
    {
        $sitemapUrl = rtrim($baseUrl, '/') . $this->url->path('sitemap.xml');
        $line = 'Sitemap: ' . $sitemapUrl;

        if (!$this->storage->exists('robots.txt')) {
            $this->storage->atomicWrite('robots.txt', "User-agent: *\nAllow: /\n" . $line . "\n");
            return;
        }

        $current = $this->storage->read('robots.txt');
        if (preg_match('/^\s*Sitemap\s*:/im', $current) === 1) {
            return; // a Sitemap line is already declared — leave the file untouched
        }
        $sep = ($current === '' || str_ends_with($current, "\n")) ? '' : "\n";
        $this->storage->atomicWrite('robots.txt', $current . $sep . $line . "\n");
    }
}
