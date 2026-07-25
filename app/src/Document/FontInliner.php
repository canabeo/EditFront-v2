<?php

declare(strict_types=1);

namespace EditFront\Document;

use EditFront\Support\Config;

/**
 * Makes the site's own fonts work inside the sandboxed editor preview.
 *
 * The preview runs with an opaque origin, and fonts are always fetched under
 * CORS rules, so the browser refuses every @font-face file unless the server
 * sends Access-Control-Allow-Origin. On a host that serves static files from a
 * front-end proxy — sprinthost does — no .htaccess can add that header, and the
 * admin ends up editing a page with fallback type and blank icon glyphs.
 *
 * Rather than depend on server configuration we cannot reach, the preview
 * re-declares the @font-face rules with the font bytes inlined as data: URIs.
 * A data: URI has no origin to check, so this works everywhere. It costs the
 * preview a few hundred KB, is never written to disk, and only the logged-in
 * administrator ever loads it.
 */
final class FontInliner
{
    private const FONT_EXT = ['woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf', 'otf' => 'font/otf'];

    /** a single font file bigger than this is left alone (it would bloat the preview) */
    private const MAX_FONT_BYTES = 400_000;

    /** total inlined budget; past it we stop and leave the rest to the browser */
    private const MAX_TOTAL_BYTES = 3_000_000;

    /** stylesheets are small, but never read something absurd */
    private const MAX_CSS_BYTES = 2_000_000;

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Collect @font-face rules from the page's local stylesheets, inline their
     * sources, and return the CSS to inject. Empty string when there is nothing
     * to do — the caller then injects nothing at all.
     */
    public function buildCss(\DOMDocument $doc, string $page): string
    {
        $siteRoot = realpath($this->config->siteRoot());
        if ($siteRoot === false) {
            return '';
        }
        $pageDir = str_replace('\\', '/', \dirname($page));
        $pageDir = ($pageDir === '.' || $pageDir === '') ? '' : $pageDir;

        $out = [];
        $budget = self::MAX_TOTAL_BYTES;

        foreach ($doc->getElementsByTagName('link') as $link) {
            if (!$link instanceof \DOMElement) {
                continue;
            }
            $rel = strtolower(trim($link->getAttribute('rel')));
            if ($rel !== 'stylesheet') {
                continue;
            }
            $cssPath = $this->resolve($link->getAttribute('href'), $pageDir, $siteRoot);
            if ($cssPath === null || !is_file($cssPath) || filesize($cssPath) > self::MAX_CSS_BYTES) {
                continue;
            }
            $css = (string) @file_get_contents($cssPath);
            if ($css === '') {
                continue;
            }
            $cssDir = str_replace('\\', '/', \dirname(substr($cssPath, strlen($siteRoot) + 1)));
            $cssDir = ($cssDir === '.' ) ? '' : $cssDir;

            foreach ($this->fontFaceBlocks($css) as $block) {
                $rewritten = $this->inlineSources($block, $cssDir, $siteRoot, $budget);
                if ($rewritten !== null) {
                    $out[] = $rewritten;
                }
                if ($budget <= 0) {
                    break 2;
                }
            }
        }

        return $out === [] ? '' : implode("\n", $out);
    }

    /** @return list<string> the raw text of every @font-face block */
    private function fontFaceBlocks(string $css): array
    {
        if (preg_match_all('/@font-face\s*\{[^}]*\}/i', $css, $m) !== false && isset($m[0])) {
            return $m[0];
        }
        return [];
    }

    /**
     * Replace every local url(...) in one @font-face block with a data: URI.
     * Returns null when nothing could be inlined, so we do not emit a rule that
     * would override a working one with a broken source.
     */
    private function inlineSources(string $block, string $cssDir, string $siteRoot, int &$budget): ?string
    {
        $inlined = 0;
        $result = preg_replace_callback(
            '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
            function (array $m) use ($cssDir, $siteRoot, &$budget, &$inlined): string {
                $raw = trim($m[2]);
                if ($raw === '' || str_starts_with($raw, 'data:') || preg_match('~^[a-z]+://~i', $raw) === 1) {
                    return $m[0];
                }
                $ext = strtolower(pathinfo(parse_url($raw, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
                if (!isset(self::FONT_EXT[$ext])) {
                    return $m[0];
                }
                $abs = $this->resolve($raw, $cssDir, $siteRoot);
                if ($abs === null || !is_file($abs)) {
                    return $m[0];
                }
                $size = (int) filesize($abs);
                if ($size <= 0 || $size > self::MAX_FONT_BYTES || $size > $budget) {
                    return $m[0];
                }
                $bytes = @file_get_contents($abs);
                if ($bytes === false) {
                    return $m[0];
                }
                $budget -= $size;
                $inlined++;
                return 'url(data:' . self::FONT_EXT[$ext] . ';base64,' . base64_encode($bytes) . ')';
            },
            $block
        );

        return ($inlined > 0 && is_string($result)) ? $result : null;
    }

    /**
     * Resolve a URL found in HTML or CSS to an absolute path, refusing anything
     * that leaves the site root. Absolute URLs and protocol-relative ones are
     * not ours to serve.
     */
    private function resolve(string $url, string $baseDir, string $siteRoot): ?string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '//') || preg_match('~^[a-z]+:~i', $url) === 1) {
            return null;
        }
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }
        $path = rawurldecode($path);
        $rel = str_starts_with($path, '/') ? ltrim($path, '/') : ($baseDir === '' ? $path : $baseDir . '/' . $path);

        $abs = realpath($siteRoot . '/' . $rel);
        if ($abs === false || !str_starts_with($abs, $siteRoot . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $abs;
    }
}
