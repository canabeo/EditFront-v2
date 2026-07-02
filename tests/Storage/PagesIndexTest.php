<?php

declare(strict_types=1);

namespace EditFront\Tests\Storage;

use EditFront\Storage\PagesIndex;
use PHPUnit\Framework\TestCase;

final class PagesIndexTest extends TestCase
{
    private string $root;
    private string $storage;
    private string $cmsDir;

    protected function setUp(): void
    {
        $this->root = ef2_temp_dir('site');
        $this->storage = ef2_temp_dir('idxstorage');
        $this->cmsDir = $this->root . '/cms';

        file_put_contents($this->root . '/index.html', '<html></html>');
        file_put_contents($this->root . '/about.html', '<html></html>');
        mkdir($this->root . '/sub');
        file_put_contents($this->root . '/sub/page.html', '<html></html>');
        file_put_contents($this->root . '/notes.txt', 'not a page');
        // CMS dir and hidden dirs must be excluded from the listing
        mkdir($this->cmsDir);
        file_put_contents($this->cmsDir . '/internal.html', '<html></html>');
        mkdir($this->root . '/.hidden');
        file_put_contents($this->root . '/.hidden/skip.html', '<html></html>');
    }

    private function index(int $ttl = 300): PagesIndex
    {
        return new PagesIndex(ef2_test_config([
            'site_root' => $this->root,
            'cms_dir' => $this->cmsDir,
            'storage_dir' => $this->storage,
            'pages_index_ttl' => $ttl,
        ]));
    }

    public function test_lists_html_pages_excluding_cms_and_hidden(): void
    {
        $paths = array_column($this->index()->list(), 'path');
        $this->assertSame(['about.html', 'index.html', 'sub/page.html'], $paths);
    }

    public function test_entries_have_size_and_mtime(): void
    {
        $first = $this->index()->list()[0];
        $this->assertGreaterThan(0, $first['size']);
        $this->assertGreaterThan(0, $first['mtime']);
    }

    public function test_cache_file_is_created(): void
    {
        $this->index()->list();
        $this->assertFileExists($this->storage . '/cache/pages-index.json');
    }

    public function test_cached_result_served_until_invalidated(): void
    {
        $idx = $this->index();
        $idx->list();
        // new file in a SUBdir does not bump the root mtime → cache stays
        file_put_contents($this->root . '/sub/late.html', '<html></html>');
        $this->assertNotContains('sub/late.html', array_column($idx->list(), 'path'));

        $idx->invalidate();
        $this->assertContains('sub/late.html', array_column($idx->list(), 'path'));
    }

    public function test_root_level_change_invalidates_cache(): void
    {
        $idx = $this->index();
        $idx->list();
        sleep(1); // ensure the directory mtime actually changes
        file_put_contents($this->root . '/fresh.html', '<html></html>');
        clearstatcache();
        $this->assertContains('fresh.html', array_column($idx->list(), 'path'));
    }
}
