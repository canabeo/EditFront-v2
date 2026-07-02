<?php

declare(strict_types=1);

namespace EditFront\Http;

use EditFront\Support\Config;

/**
 * The ONLY place that builds CMS URLs and cookie paths from basePath
 * (invariant 0.2.5). Anything that emits a URL — redirects, assets, cookies,
 * postMessage origin — must go through this helper.
 */
final class UrlHelper
{
    public function __construct(private readonly Config $config)
    {
    }

    /** url path inside the CMS: path('login') → '/cms/login'; path('') → '/cms/' */
    public function path(string $p = ''): string
    {
        $bp = $this->config->basePath();
        $p = ltrim($p, '/');
        if ($p === '') {
            return $bp === '' ? '/' : $bp . '/';
        }
        return $bp . '/' . $p;
    }

    /** asset URL with mtime cache-busting (?v=) */
    public function asset(string $p): string
    {
        $rel = 'assets/' . ltrim($p, '/');
        $abs = $this->config->cmsDir() . '/' . $rel;
        $v = (string) (@filemtime($abs) ?: 0);
        return $this->path($rel) . '?v=' . $v;
    }

    /**
     * URL on the SITE the CMS manages (uploads, preview <base>): the site
     * prefix is basePath minus its last segment — '/cms' → '', '/x/cms' → '/x'.
     */
    public function siteUrl(string $p = ''): string
    {
        $bp = $this->config->basePath();
        $sitePrefix = $bp === '' ? '' : (string) preg_replace('~/[^/]+$~', '', $bp);
        return $sitePrefix . '/' . ltrim($p, '/');
    }

    /** cookie Path attribute — scoped to the CMS subfolder */
    public function cookiePath(): string
    {
        $bp = $this->config->basePath();
        return $bp === '' ? '/' : $bp;
    }

    /** true when $urlPath belongs to the CMS API namespace */
    public function isApiPath(string $urlPath): bool
    {
        return str_starts_with($urlPath, $this->path('api/'));
    }
}
