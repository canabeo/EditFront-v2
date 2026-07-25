<?php

declare(strict_types=1);

namespace EditFront\Tests\Document;

use EditFront\Document\FontInliner;
use PHPUnit\Framework\TestCase;

/**
 * The editor preview is sandboxed, so it has an opaque origin, and fonts are
 * always fetched under CORS rules — every @font-face file is refused unless the
 * server sends Access-Control-Allow-Origin for it. On a host whose front-end
 * proxy serves static files (as some shared hosts do), no .htaccess can add that
 * header, and the administrator edits the page in fallback type with blank icon
 * glyphs. Inlining the bytes as data: URIs sidesteps origins entirely.
 */
final class FontInlinerTest extends TestCase
{
    private string $site;

    protected function setUp(): void
    {
        $this->site = ef2_temp_dir('fontinline');
        @mkdir($this->site . '/assets/fonts', 0777, true);
        file_put_contents($this->site . '/assets/fonts/x.woff2', "wOF2\x00fake-font-bytes");
        file_put_contents($this->site . '/assets/site.css', <<<CSS
            @font-face { font-family: "Brand"; src: url(fonts/x.woff2) format("woff2"); font-weight: 700; }
            body { color: red; }
            CSS);
    }

    private function inliner(): FontInliner
    {
        return new FontInliner(ef2_test_config([
            'site_root' => $this->site,
            'cms_dir' => $this->site . '/cms',
        ]));
    }

    private function doc(string $html): \DOMDocument
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        return $doc;
    }

    public function test_font_sources_become_data_uris(): void
    {
        $css = $this->inliner()->buildCss(
            $this->doc('<html><head><link rel="stylesheet" href="assets/site.css"></head><body></body></html>'),
            'index.html'
        );

        $this->assertStringContainsString('@font-face', $css);
        $this->assertStringContainsString('font-family: "Brand"', $css);
        $this->assertStringContainsString('url(data:font/woff2;base64,', $css);
        $this->assertStringContainsString(base64_encode("wOF2\x00fake-font-bytes"), $css);
        // rules that are not @font-face have no business being copied
        $this->assertStringNotContainsString('color: red', $css);
    }

    public function test_nothing_to_inline_produces_nothing(): void
    {
        file_put_contents($this->site . '/assets/plain.css', 'body { color: blue; }');
        $css = $this->inliner()->buildCss(
            $this->doc('<html><head><link rel="stylesheet" href="assets/plain.css"></head><body></body></html>'),
            'index.html'
        );
        $this->assertSame('', $css);
    }

    public function test_remote_and_escaping_urls_are_left_alone(): void
    {
        file_put_contents($this->site . '/assets/remote.css',
            '@font-face { font-family: "R"; src: url(https://cdn.example.com/f.woff2); }' .
            '@font-face { font-family: "E"; src: url(../../../../etc/passwd.woff2); }');

        $css = $this->inliner()->buildCss(
            $this->doc('<html><head><link rel="stylesheet" href="assets/remote.css"></head><body></body></html>'),
            'index.html'
        );

        // neither could be inlined, so no overriding rule is emitted at all
        $this->assertSame('', $css);
        $this->assertStringNotContainsString('passwd', $css);
    }

    public function test_a_stylesheet_outside_the_site_is_ignored(): void
    {
        $css = $this->inliner()->buildCss(
            $this->doc('<html><head><link rel="stylesheet" href="../../outside.css"></head><body></body></html>'),
            'index.html'
        );
        $this->assertSame('', $css);
    }
}
