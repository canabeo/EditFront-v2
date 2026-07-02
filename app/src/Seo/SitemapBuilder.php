<?php

declare(strict_types=1);

namespace EditFront\Seo;

use EditFront\Http\UrlHelper;
use EditFront\Storage\PagesIndex;

/**
 * On-the-fly sitemap.xml (§9 C5, optional). Walks the pages index and emits a
 * valid <urlset>; URLs are built site-relative via UrlHelper (so a subfolder
 * install keeps its prefix) joined to the request/base origin. Priority is a
 * simple depth heuristic; lastmod comes from the file mtime.
 *
 * Pages whose per-page robots meta says `noindex` (via SeoService) are skipped —
 * we never advertise to a crawler a page we asked it not to index.
 */
final class SitemapBuilder
{
    private const MAX_URLS = 50000;

    public function __construct(
        private readonly PagesIndex $pages,
        private readonly UrlHelper $url,
        private readonly SeoService $seo,
    ) {
    }

    /** @param string $baseUrl scheme://host (no trailing slash) */
    public function build(string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        $count = 0;
        foreach ($this->pages->list() as $page) {
            if ($count >= self::MAX_URLS) {
                break;
            }
            // a page the admin marked noindex must not be advertised in the sitemap
            if (!$this->seo->isIndexable($page['path'])) {
                continue;
            }
            $count++;
            // existing sites may have spaces / non-ASCII in filenames — each path
            // segment must be URL-encoded for a valid <loc> (review M5)
            $encoded = implode('/', array_map('rawurlencode', explode('/', $page['path'])));
            $loc = $baseUrl . $this->url->siteUrl($encoded);
            $out .= '  <url>'
                . '<loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc>'
                . '<lastmod>' . date('Y-m-d', (int) $page['mtime']) . '</lastmod>'
                . '<priority>' . $this->priority($page['path']) . '</priority>'
                . '</url>' . "\n";
        }
        return $out . '</urlset>' . "\n";
    }

    private function priority(string $path): string
    {
        $base = strtolower(basename($path));
        if ($base === 'index.html' || $base === 'index.htm') {
            return '1.0';
        }
        if (str_contains($base, '404') || str_contains($base, 'privacy') || str_contains($base, 'terms')) {
            return '0.3';
        }
        // top-level vs nested
        return str_contains(str_replace('\\', '/', $path), '/') ? '0.6' : '0.8';
    }
}
