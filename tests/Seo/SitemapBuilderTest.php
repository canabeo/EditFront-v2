<?php

declare(strict_types=1);

namespace EditFront\Tests\Seo;

use EditFront\Http\UrlHelper;
use EditFront\Seo\SitemapBuilder;
use EditFront\Storage\PagesIndex;
use EditFront\Support\Config;
use PHPUnit\Framework\TestCase;

final class SitemapBuilderTest extends TestCase
{
    private function builder(string $basePath, string $site): SitemapBuilder
    {
        $config = ef2_test_config([
            'base_path' => $basePath,
            'site_root' => $site,
            'cms_dir' => $site . '/cms',
            'storage_dir' => ef2_temp_dir('sm-storage'),
        ]);
        @mkdir($config->cmsDir(), 0777, true);
        return new SitemapBuilder(new PagesIndex($config), new UrlHelper($config), ef2_seo_service($config), $config);
    }

    public function test_emits_valid_urlset_with_pages(): void
    {
        $site = ef2_temp_dir('sm-site');
        file_put_contents($site . '/index.html', '<html></html>');
        @mkdir($site . '/blog', 0777, true);
        file_put_contents($site . '/blog/post.html', '<html></html>');

        $xml = $this->builder('/cms', $site)->build('https://example.test');

        $this->assertStringContainsString('<urlset', $xml);
        $this->assertStringContainsString('<loc>https://example.test/index.html</loc>', $xml);
        $this->assertStringContainsString('<loc>https://example.test/blog/post.html</loc>', $xml);
        $this->assertStringContainsString('<priority>1.0</priority>', $xml); // index
        // valid XML
        $this->assertInstanceOf(\SimpleXMLElement::class, simplexml_load_string($xml));
    }

    public function test_subfolder_install_keeps_prefix(): void
    {
        $site = ef2_temp_dir('sm-site');
        file_put_contents($site . '/index.html', '<html></html>');
        // site at /demo, cms at /demo/cms → loc must be /demo/index.html
        $xml = $this->builder('/demo/cms', $site)->build('https://example.test');
        $this->assertStringContainsString('<loc>https://example.test/demo/index.html</loc>', $xml);
    }

    public function test_filename_with_space_is_url_encoded(): void
    {
        $site = ef2_temp_dir('sm-site');
        file_put_contents($site . '/my page.html', '<html></html>');
        $xml = $this->builder('/cms', $site)->build('https://example.test');
        $this->assertStringContainsString('my%20page.html', $xml);
        $this->assertStringNotContainsString('my page.html', $xml);
    }

    public function test_trailing_slash_in_base_is_normalized(): void
    {
        $site = ef2_temp_dir('sm-site');
        file_put_contents($site . '/index.html', '<html></html>');
        $xml = $this->builder('/cms', $site)->build('https://example.test/');
        $this->assertStringContainsString('https://example.test/index.html', $xml);
        $this->assertStringNotContainsString('test//index', $xml);
    }

    public function test_noindex_pages_are_skipped_when_seo_present(): void
    {
        $site = ef2_temp_dir('sm-site');
        file_put_contents($site . '/keep.html', '<html><head><title>k</title></head><body></body></html>');
        file_put_contents(
            $site . '/hidden.html',
            '<html><head><title>h</title><meta name="robots" content="noindex,follow"></head><body></body></html>'
        );
        $config = ef2_test_config([
            'base_path' => '/cms',
            'site_root' => $site,
            'cms_dir' => $site . '/cms',
            'storage_dir' => ef2_temp_dir('sm-storage'),
        ]);
        @mkdir($config->cmsDir(), 0777, true);
        $builder = new SitemapBuilder(new PagesIndex($config), new UrlHelper($config), ef2_seo_service($config), $config);
        $xml = $builder->build('https://example.test');
        $this->assertStringContainsString('/keep.html</loc>', $xml);
        $this->assertStringNotContainsString('/hidden.html</loc>', $xml);
    }

    /**
     * Bug 3 (MEDIUM, CWE-770): sitemap.xml is anonymous and used to walk AND
     * HTML-parse every page in the site on every single request, holding a
     * PHP-FPM worker for the whole scan — and `?x=1` was enough to miss any
     * shared HTTP cache and reach the origin again. The result is now cached on
     * disk, so repeated requests cost a single file read.
     */
    public function test_sitemap_is_served_from_cache_on_the_second_call(): void
    {
        $site = ef2_temp_dir('sm-cache-site');
        file_put_contents($site . '/a.html', '<html><body>a</body></html>');
        $config = ef2_test_config([
            'base_path' => '/cms',
            'site_root' => $site,
            'cms_dir' => $site . '/cms',
            'storage_dir' => ef2_temp_dir('sm-cache-storage'),
        ]);
        @mkdir($config->cmsDir(), 0777, true);
        $builder = new SitemapBuilder(new PagesIndex($config), new UrlHelper($config), ef2_seo_service($config), $config);

        $first = $builder->build('https://example.com');
        $this->assertStringContainsString('a.html', $first);

        // Change page CONTENT so it would now be excluded on a real rebuild.
        // The site-root mtime is untouched, so the cache stays valid and the
        // second call must return the very same bytes without rescanning.
        file_put_contents($site . '/a.html', '<html><head><meta name="robots" content="noindex"></head><body>a</body></html>');

        $this->assertSame($first, $builder->build('https://example.com'));
    }
}
