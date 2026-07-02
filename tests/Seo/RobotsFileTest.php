<?php

declare(strict_types=1);

namespace EditFront\Tests\Seo;

use EditFront\Http\UrlHelper;
use EditFront\Seo\RobotsFile;
use EditFront\Storage\FileStorage;
use EditFront\Storage\PathGuard;
use PHPUnit\Framework\TestCase;

/**
 * §9 robots.txt: created at the site root when missing (advertises the sitemap),
 * a Sitemap line appended to an existing file that lacks one, and left untouched
 * when one is already present (never clobbers the owner's rules).
 */
final class RobotsFileTest extends TestCase
{
    private string $site;

    private function robots(): RobotsFile
    {
        $this->site = ef2_temp_dir('robots-site');
        $config = ef2_test_config(['base_path' => '/cms', 'site_root' => $this->site]);
        $guard = new PathGuard();
        return new RobotsFile(new FileStorage($config, $guard), new UrlHelper($config));
    }

    public function test_creates_when_missing(): void
    {
        $this->robots()->ensure('https://example.test');
        $txt = file_get_contents($this->site . '/robots.txt');
        $this->assertStringContainsString('User-agent: *', $txt);
        $this->assertStringContainsString('Sitemap: https://example.test/cms/sitemap.xml', $txt);
    }

    public function test_appends_sitemap_to_existing_without_one(): void
    {
        file_put_contents($this->siteSeed(), "User-agent: *\nDisallow: /private\n");
        $this->robotsForSeed()->ensure('https://example.test');
        $txt = file_get_contents($this->site . '/robots.txt');
        $this->assertStringContainsString('Disallow: /private', $txt); // preserved
        $this->assertStringContainsString('Sitemap: https://example.test/cms/sitemap.xml', $txt);
    }

    public function test_leaves_existing_sitemap_untouched(): void
    {
        file_put_contents($this->siteSeed(), "User-agent: *\nSitemap: https://custom/sm.xml\n");
        $this->robotsForSeed()->ensure('https://example.test');
        $txt = file_get_contents($this->site . '/robots.txt');
        $this->assertStringContainsString('https://custom/sm.xml', $txt);
        $this->assertStringNotContainsString('example.test', $txt);
    }

    /* helpers that keep one site dir across seed + ensure */
    private RobotsFile $seeded;

    private function siteSeed(): string
    {
        $this->site = ef2_temp_dir('robots-site');
        $config = ef2_test_config(['base_path' => '/cms', 'site_root' => $this->site]);
        $guard = new PathGuard();
        $this->seeded = new RobotsFile(new FileStorage($config, $guard), new UrlHelper($config));
        return $this->site . '/robots.txt';
    }

    private function robotsForSeed(): RobotsFile
    {
        return $this->seeded;
    }
}
