<?php

declare(strict_types=1);

namespace EditFront\Tests\News;

use EditFront\News\NewsSlug;
use EditFront\Storage\PagesIndex;
use EditFront\Support\Config;
use PHPUnit\Framework\TestCase;

final class NewsSlugTest extends TestCase
{
    private string $storage;
    private string $siteRoot;

    protected function setUp(): void
    {
        $this->storage  = ef2_temp_dir('news-slug-storage');
        $this->siteRoot = ef2_temp_dir('news-slug-site');
    }

    private function slug(): NewsSlug
    {
        $config = ef2_test_config([
            'storage_dir' => $this->storage,
            'site_root'   => $this->siteRoot,
        ]);
        return new NewsSlug(new PagesIndex($config));
    }

    /** Drop a real .html file into the site root so PagesIndex sees it. */
    private function seedPage(string $name): void
    {
        file_put_contents($this->siteRoot . '/' . $name, '<!doctype html><title>x</title>');
    }

    // ---- slugify -----------------------------------------------------------

    public function test_ascii_title_becomes_kebab(): void
    {
        $this->assertSame('hello-world', $this->slug()->slugify('Hello World'));
    }

    public function test_russian_title_transliterates(): void
    {
        $this->assertSame(
            'grafik-raboty-v-den-rossii',
            $this->slug()->slugify('График работы в День России'),
        );
    }

    public function test_full_russian_alphabet_lower(): void
    {
        $this->assertSame(
            'abvgdeezhziyklmnoprstufhcchshschyeyuya',
            $this->slug()->slugify('абвгдеёжзийклмнопрстуфхцчшщъыьэюя'),
        );
    }

    public function test_hard_and_soft_signs_drop(): void
    {
        // ъ → '', ь → '' : "подъезд" => "podezd", "соль" => "sol"
        $this->assertSame('podezd', $this->slug()->slugify('подъезд'));
        $this->assertSame('sol', $this->slug()->slugify('соль'));
    }

    public function test_mixed_cyrillic_latin_digits(): void
    {
        $this->assertSame('novosti-2026-news', $this->slug()->slugify('Новости 2026 News'));
    }

    public function test_collapses_separators_and_trims_dashes(): void
    {
        $this->assertSame('a-b-c', $this->slug()->slugify('  --A_/_B   c-- '));
    }

    public function test_drops_punctuation(): void
    {
        $this->assertSame('whats-new', $this->slug()->slugify("What's new?!"));
    }

    public function test_empty_or_symbol_only_falls_back(): void
    {
        $this->assertSame('news', $this->slug()->slugify('   '));
        $this->assertSame('news', $this->slug()->slugify('!!! ??? ...'));
    }

    // ---- unique ------------------------------------------------------------

    public function test_unique_no_collision_returns_base(): void
    {
        $this->assertSame('alpha', $this->slug()->unique('alpha', []));
    }

    public function test_unique_collides_with_other_item_slugs(): void
    {
        $this->assertSame('alpha-2', $this->slug()->unique('alpha', ['alpha']));
        $this->assertSame('alpha-3', $this->slug()->unique('alpha', ['alpha', 'alpha-2']));
    }

    public function test_unique_collides_with_existing_site_page(): void
    {
        $this->seedPage('alpha.html');
        // collision is against '<slug>.html' on the live site, not just items
        $this->assertSame('alpha-2', $this->slug()->unique('alpha', []));
    }

    public function test_unique_collides_with_both_sources(): void
    {
        $this->seedPage('alpha.html');     // alpha.html taken on disk
        // alpha (page) + alpha-2 (item) taken => next free is alpha-3
        $this->assertSame('alpha-3', $this->slug()->unique('alpha', ['alpha-2']));
    }

    public function test_unique_template_page_not_special_cased(): void
    {
        // a leading-underscore template page does not affect a normal slug base
        $this->seedPage('_news-template.html');
        $this->assertSame('alpha', $this->slug()->unique('alpha', []));
    }
}
