<?php

declare(strict_types=1);

namespace EditFront\Tests\Document;

use EditFront\Document\FontFaceRenderer;
use EditFront\Font\FontService;
use EditFront\Http\UrlHelper;
use PHPUnit\Framework\TestCase;

final class FontFaceRendererTest extends TestCase
{
    private FontService $fonts;
    private FontFaceRenderer $renderer;

    protected function setUp(): void
    {
        $site = ef2_temp_dir('ff-site');
        $cms = ef2_temp_dir('ff-cms');
        @mkdir($cms . '/storage', 0777, true);
        // stub one preset file so activePresets() reports a built-in (Inter)
        @mkdir($cms . '/assets/fonts', 0777, true);
        file_put_contents($cms . '/assets/fonts/inter-latin.woff2', 'wOF2stub');
        file_put_contents($cms . '/assets/fonts/inter-cyrillic.woff2', 'wOF2stub');
        $config = ef2_test_config(['site_root' => $site, 'cms_dir' => $cms, 'base_path' => '/cms']);
        $this->fonts = new FontService($config, new UrlHelper($config));
        $this->renderer = new FontFaceRenderer($this->fonts);
    }

    private function doc(): \DOMDocument
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<!doctype html><html><head><title>t</title></head><body><p>hi</p></body></html>');
        return $doc;
    }

    private function styleBlocks(\DOMDocument $doc): array
    {
        $out = [];
        foreach ($doc->getElementsByTagName('style') as $s) {
            if ($s->getAttribute('id') === 'ef-fonts') {
                $out[] = $s->textContent;
            }
        }
        return $out;
    }

    public function test_injects_preset_block_always(): void
    {
        // no usage scan: the built-in Inter @font-face is emitted regardless
        $doc = $this->doc();
        $this->renderer->apply($doc);
        $blocks = $this->styleBlocks($doc);
        $this->assertCount(1, $blocks);
        $this->assertStringContainsString("@font-face", $blocks[0]);
        $this->assertStringContainsString("'Inter'", $blocks[0]);
    }

    public function test_includes_uploaded_font_alongside_presets(): void
    {
        $this->fonts->store("wOF2payload", 'Brand');
        $doc = $this->doc();
        $this->renderer->apply($doc);
        $css = $this->styleBlocks($doc)[0];
        $this->assertStringContainsString("'Inter'", $css);
        $this->assertStringContainsString("'Brand'", $css);
    }

    public function test_idempotent_rebuild(): void
    {
        $doc = $this->doc();
        $this->renderer->apply($doc);
        $this->renderer->apply($doc); // run twice — still exactly one block
        $this->assertCount(1, $this->styleBlocks($doc));
    }

    public function test_deleted_user_font_disappears_presets_remain(): void
    {
        $this->fonts->store("wOF2payload", 'Brand');
        $doc = $this->doc();
        $this->renderer->apply($doc);
        $this->assertStringContainsString("'Brand'", $this->styleBlocks($doc)[0]);

        $this->fonts->delete('Brand');
        $this->renderer->apply($doc);
        $css = $this->styleBlocks($doc)[0];
        $this->assertStringNotContainsString("'Brand'", $css);
        $this->assertStringContainsString("'Inter'", $css); // preset still there
    }
}
