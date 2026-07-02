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
        return new SitemapBuilder(new PagesIndex($config), new UrlHelper($config), ef2_seo_service($config));
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
        $builder = new SitemapBuilder(new PagesIndex($config), new UrlHelper($config), ef2_seo_service($config));
        $xml = $builder->build('https://example.test');
        $this->assertStringContainsString('/keep.html</loc>', $xml);
        $this->assertStringNotContainsString('/hidden.html</loc>', $xml);
    }
}
