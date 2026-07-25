<?php

declare(strict_types=1);

namespace EditFront\Seo;

use EditFront\Http\UrlHelper;
use EditFront\Storage\PagesIndex;
use EditFront\Support\Config;

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

    /** how long a generated sitemap may be reused (matches the Cache-Control) */
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly PagesIndex $pages,
        private readonly UrlHelper $url,
        private readonly SeoService $seo,
        private readonly Config $config,
    ) {
    }

    /**
     * Build the sitemap, reusing a cached copy when nothing relevant changed.
     *
     * Without this every anonymous GET walked and parsed the entire site (up to
     * PagesIndex::MAX_PAGES files), holding a PHP-FPM worker for the duration —
     * and a query string was enough to slip past any shared HTTP cache.
     *
     * @param string $baseUrl scheme://host (no trailing slash)
     */
    public function build(string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl, '/');

        $cacheFile = $this->cacheFile($baseUrl);
        $rootMtime = (int) @filemtime($this->config->siteRoot());
        $cached = is_file($cacheFile)
            ? json_decode((string) @file_get_contents($cacheFile), true)
            : null;
        if (
            is_array($cached)
            && is_string($cached['xml'] ?? null)
            && (int) ($cached['root_mtime'] ?? -1) === $rootMtime
            && time() - (int) ($cached['built_at'] ?? 0) < self::CACHE_TTL
        ) {
            return $cached['xml'];
        }

        $xml = $this->render($baseUrl);
        $this->writeCache($cacheFile, $xml, $rootMtime);
        return $xml;
    }

    private function cacheFile(string $baseUrl): string
    {
        return $this->config->storageDir() . '/cache/sitemap-' . sha1($baseUrl) . '.json';
    }

    private function writeCache(string $file, string $xml, int $rootMtime): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            return; // caching is best-effort; a failure must not break the sitemap
        }
        $payload = json_encode(['built_at' => time(), 'root_mtime' => $rootMtime, 'xml' => $xml]);
        if ($payload === false) {
            return;
        }
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $payload) !== false && @rename($tmp, $file)) {
            @chmod($file, 0640);
            return;
        }
        @unlink($tmp);
    }

    private function render(string $baseUrl): string
    {
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
