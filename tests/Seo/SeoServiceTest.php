<?php

declare(strict_types=1);

namespace EditFront\Tests\Seo;

use EditFront\Seo\SeoException;
use PHPUnit\Framework\TestCase;

/**
 * §9 per-page SEO: read pulls the head fields, save rewrites them in place
 * (title/description/canonical/robots), index+follow default emits NO robots
 * tag, and an unsafe canonical is rejected.
 */
final class SeoServiceTest extends TestCase
{
    private string $site;

    private function service(): \EditFront\Seo\SeoService
    {
        $this->site = ef2_temp_dir('seo-site');
        $config = ef2_test_config([
            'site_root' => $this->site,
            'storage_dir' => ef2_temp_dir('seo-storage'),
        ]);
        return ef2_seo_service($config);
    }

    private function writePage(string $head): void
    {
        file_put_contents(
            $this->site . '/p.html',
            "<!doctype html><html lang=\"ru\"><head>\n<meta charset=\"utf-8\">\n" . $head . "\n</head><body><h1>Hi</h1></body></html>"
        );
    }

    public function test_read_extracts_head_fields(): void
    {
        $seo = $this->service();
        $this->writePage(
            '<title>Old Title</title>'
            . '<meta name="description" content="Old desc">'
            . '<link rel="canonical" href="https://old.example/x">'
        );
        $r = $seo->read('p.html');
        $this->assertSame('Old Title', $r['title']);
        $this->assertSame('Old desc', $r['description']);
        $this->assertSame('https://old.example/x', $r['canonical']);
        $this->assertTrue($r['index']);
        $this->assertTrue($r['follow']);
    }

    public function test_save_round_trips(): void
    {
        $seo = $this->service();
        $this->writePage('<title>Old</title>');
        $seo->save('p.html', [
            'title' => 'New Title', 'description' => 'New description',
            'canonical' => 'https://new.example/p', 'index' => true, 'follow' => true,
        ]);
        $r = $seo->read('p.html');
        $this->assertSame('New Title', $r['title']);
        $this->assertSame('New description', $r['description']);
        $this->assertSame('https://new.example/p', $r['canonical']);
        $this->assertTrue($r['index']);
    }

    public function test_default_robots_emits_no_tag_but_noindex_does(): void
    {
        $seo = $this->service();
        $this->writePage('<title>T</title>');

        $seo->save('p.html', ['title' => 'T', 'index' => true, 'follow' => true]);
        $this->assertStringNotContainsString('name="robots"', file_get_contents($this->site . '/p.html'));

        $seo->save('p.html', ['title' => 'T', 'index' => false, 'follow' => true]);
        $html = file_get_contents($this->site . '/p.html');
        $this->assertStringContainsString('noindex,follow', $html);
        $this->assertFalse($seo->read('p.html')['index']);
        $this->assertFalse($seo->isIndexable('p.html'));
    }

    public function test_empty_description_and_canonical_remove_tags(): void
    {
        $seo = $this->service();
        $this->writePage(
            '<title>T</title>'
            . '<meta name="description" content="d">'
            . '<link rel="canonical" href="https://x/y">'
        );
        $seo->save('p.html', ['title' => 'T', 'description' => '', 'canonical' => '']);
        $html = file_get_contents($this->site . '/p.html');
        $this->assertStringNotContainsString('name="description"', $html);
        $this->assertStringNotContainsString('rel="canonical"', $html);
    }

    public function test_save_strips_control_chars_in_title(): void
    {
        $seo = $this->service();
        $this->writePage('<title>T</title>');
        $seo->save('p.html', ['title' => "A\r\nB\tC"]);
        $this->assertSame('A B C', $seo->read('p.html')['title']);
    }

    public function test_save_rejects_unsafe_canonical(): void
    {
        $seo = $this->service();
        $this->writePage('<title>T</title>');
        $this->expectException(SeoException::class);
        $seo->save('p.html', ['title' => 'T', 'canonical' => 'javascript:alert(1)']);
    }

    public function test_isindexable_defaults_true_when_no_robots(): void
    {
        $seo = $this->service();
        $this->writePage('<title>T</title>');
        $this->assertTrue($seo->isIndexable('p.html'));
    }

    public function test_robots_none_is_read_as_noindex_nofollow(): void
    {
        $seo = $this->service();
        $this->writePage('<title>T</title><meta name="robots" content="none">');
        $r = $seo->read('p.html');
        $this->assertFalse($r['index']);
        $this->assertFalse($r['follow']);
        $this->assertFalse($seo->isIndexable('p.html'));
    }

    public function test_combines_split_robots_metas(): void
    {
        $seo = $this->service();
        // two separate robots metas — crawlers merge them; so must we
        $this->writePage('<title>T</title><meta name="robots" content="noindex"><meta name="robots" content="nofollow">');
        $r = $seo->read('p.html');
        $this->assertFalse($r['index']);
        $this->assertFalse($r['follow']);
    }

    public function test_empty_title_removes_tag_not_an_empty_one(): void
    {
        $seo = $this->service();
        $this->writePage('<title>Old</title>');
        $seo->save('p.html', ['title' => '']);
        $this->assertStringNotContainsString('<title>', file_get_contents($this->site . '/p.html'));
        $this->assertSame('', $seo->read('p.html')['title']);
    }
}
