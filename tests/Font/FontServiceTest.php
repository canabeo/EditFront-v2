<?php

declare(strict_types=1);

namespace EditFront\Tests\Font;

use EditFront\Font\FontException;
use EditFront\Font\FontService;
use EditFront\Http\UrlHelper;
use PHPUnit\Framework\TestCase;

final class FontServiceTest extends TestCase
{
    private string $site;
    private FontService $svc;

    private const PRESET_SLUGS = ['inter', 'roboto', 'open-sans', 'montserrat', 'nunito', 'pt-sans', 'lora', 'pt-serif', 'merriweather', 'jetbrains-mono'];

    protected function setUp(): void
    {
        $this->site = ef2_temp_dir('font-site');
        $cms = ef2_temp_dir('font-cms');
        @mkdir($cms . '/storage', 0777, true);
        // stub the bundled preset files so activePresets() sees all 10
        @mkdir($cms . '/assets/fonts', 0777, true);
        foreach (self::PRESET_SLUGS as $slug) {
            file_put_contents($cms . '/assets/fonts/' . $slug . '-latin.woff2', 'wOF2stub');
            file_put_contents($cms . '/assets/fonts/' . $slug . '-cyrillic.woff2', 'wOF2stub');
        }
        $config = ef2_test_config(['site_root' => $this->site, 'cms_dir' => $cms, 'base_path' => '/cms']);
        $this->svc = new FontService($config, new UrlHelper($config));
    }

    /** minimal byte blob with a valid woff2 magic header */
    private function woff2(string $salt = ''): string
    {
        return "wOF2" . 'fake-font-payload' . $salt;
    }

    public function test_store_and_list_woff2(): void
    {
        $entry = $this->svc->store($this->woff2(), 'Brandic');
        $this->assertSame('Brandic', $entry['family']);
        $this->assertSame('woff2', $entry['format']);
        $this->assertStringEndsWith('.woff2', $entry['file']);
        $this->assertFileExists($this->site . '/fonts/' . $entry['file']);

        $list = $this->svc->list();
        $this->assertCount(1, $list);
        $this->assertStringContainsString($entry['file'], $list[0]['url']);
        $this->assertSame(['Brandic'], $this->svc->families());
    }

    public function test_store_rejects_bad_family(): void
    {
        $this->expectException(FontException::class);
        $this->svc->store($this->woff2(), "Bad'Name<>");
    }

    public function test_store_rejects_unknown_type(): void
    {
        try {
            $this->svc->store('not a font at all', 'X');
            $this->fail('expected FontException');
        } catch (FontException $e) {
            $this->assertSame(415, $e->status);
        }
    }

    public function test_store_rejects_duplicate_family(): void
    {
        $this->svc->store($this->woff2('a'), 'Brand');
        try {
            $this->svc->store($this->woff2('b'), 'brand'); // case-insensitive clash
            $this->fail('expected FontException');
        } catch (FontException $e) {
            $this->assertSame(409, $e->status);
        }
    }

    public function test_user_fontface_rule_built(): void
    {
        $this->svc->store($this->woff2(), 'My Brand');
        $rules = $this->svc->userFontFaceRules();
        $this->assertArrayHasKey('My Brand', $rules);
        $this->assertStringContainsString("font-family: 'My Brand'", $rules['My Brand']);
        $this->assertStringContainsString("format('woff2')", $rules['My Brand']);
        $this->assertStringContainsString('font-display: swap', $rules['My Brand']);
        $this->assertStringNotContainsString('unicode-range', $rules['My Brand']); // user fonts: single rule, no subset split
    }

    public function test_presets_always_available(): void
    {
        $presets = $this->svc->presetFamilies();
        $this->assertSame(10, count($presets));
        $this->assertContains('Inter', $presets);
        $this->assertSame(10, $this->svc->presetCount());
    }

    public function test_fontface_rules_include_presets_with_unicode_range(): void
    {
        $all = $this->svc->fontFaceRules();
        $this->assertSame(20, count($all)); // 10 presets × (latin + cyrillic), no uploads
        $css = implode("\n", $all);
        $this->assertStringContainsString("font-family: 'Inter'", $css);
        $this->assertStringContainsString('unicode-range:', $css);
    }

    public function test_fontface_rules_include_user_fonts_too(): void
    {
        $this->svc->store($this->woff2(), 'My Brand');
        $all = $this->svc->fontFaceRules();
        $this->assertSame(21, count($all)); // 20 preset subset rules + 1 user rule
        $this->assertStringContainsString("'My Brand'", implode("\n", $all));
    }

    public function test_store_rejects_preset_name_collision(): void
    {
        try {
            $this->svc->store($this->woff2(), 'inter'); // clashes with built-in 'Inter'
            $this->fail('expected FontException');
        } catch (FontException $e) {
            $this->assertSame(409, $e->status);
        }
    }

    public function test_delete_removes_entry_and_file(): void
    {
        $entry = $this->svc->store($this->woff2(), 'Gone');
        $this->assertTrue($this->svc->delete('Gone'));
        $this->assertSame([], $this->svc->families());
        $this->assertFileDoesNotExist($this->site . '/fonts/' . $entry['file']);
        $this->assertFalse($this->svc->delete('Gone')); // already gone
    }

    public function test_dedup_keeps_file_while_another_family_uses_it(): void
    {
        // identical bytes under two names → one physical file (content-addressable)
        $a = $this->svc->store($this->woff2('same'), 'Alpha');
        $b = $this->svc->store($this->woff2('same'), 'Beta');
        $this->assertSame($a['file'], $b['file']);

        $this->svc->delete('Alpha');
        $this->assertFileExists($this->site . '/fonts/' . $a['file']); // still used by Beta
        $this->svc->delete('Beta');
        $this->assertFileDoesNotExist($this->site . '/fonts/' . $a['file']);
    }
}
