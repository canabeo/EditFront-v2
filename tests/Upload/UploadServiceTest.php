<?php

declare(strict_types=1);

namespace EditFront\Tests\Upload;

use EditFront\Http\UrlHelper;
use EditFront\Security\SvgSanitizer;
use EditFront\Storage\FileStorage;
use EditFront\Storage\PathGuard;
use EditFront\Support\Config;
use EditFront\Upload\UploadException;
use EditFront\Upload\UploadService;
use PHPUnit\Framework\TestCase;

final class UploadServiceTest extends TestCase
{
    // 1×1 PNG / GIF — valid raster bytes finfo recognizes without GD
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private string $site;

    /** A small PNG built via GD with one fully-transparent pixel (alpha). */
    private function pngBytes(): string
    {
        $im = imagecreatetruecolor(4, 4);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        $opaque = imagecolorallocatealpha($im, 200, 30, 30, 0);
        $clear = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefilledrectangle($im, 0, 0, 3, 3, $opaque);
        imagesetpixel($im, 0, 0, $clear); // transparent pixel
        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);
        return $bytes;
    }

    /** A small JPEG built via GD. */
    private function jpegBytes(): string
    {
        $im = imagecreatetruecolor(4, 4);
        imagefilledrectangle($im, 0, 0, 3, 3, imagecolorallocate($im, 10, 120, 200));
        ob_start();
        imagejpeg($im, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);
        return $bytes;
    }

    /** A small GIF built via GD. */
    private function gifBytes(): string
    {
        $im = imagecreatetruecolor(4, 4);
        imagefilledrectangle($im, 0, 0, 3, 3, imagecolorallocate($im, 30, 200, 90));
        ob_start();
        imagegif($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);
        return $bytes;
    }

    /** A real WebP built via GD. */
    private function webpBytes(): string
    {
        $im = imagecreatetruecolor(4, 4);
        imagefilledrectangle($im, 0, 0, 3, 3, imagecolorallocate($im, 90, 90, 220));
        ob_start();
        imagewebp($im, null, 82);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);
        return $bytes;
    }

    /** True if the bytes are a RIFF/WEBP container. */
    private static function isWebp(string $bytes): bool
    {
        return strlen($bytes) >= 12
            && substr($bytes, 0, 4) === 'RIFF'
            && substr($bytes, 8, 4) === 'WEBP';
    }

    private function svc(array $overrides = []): UploadService
    {
        $this->site = ef2_temp_dir('upload-site');
        $config = new Config(array_merge([
            'base_path' => '/cms',
            'site_root' => $this->site,
            'cms_dir' => $this->site . '/cms',
            'storage_dir' => ef2_temp_dir('upload-storage'),
        ], $overrides, ['site_root' => $overrides['site_root'] ?? $this->site]));
        @mkdir($config->cmsDir(), 0777, true);
        $guard = new PathGuard();
        return new UploadService($config, new FileStorage($config, $guard), new UrlHelper($config), new SvgSanitizer());
    }

    public function test_store_png_writes_content_addressable_file(): void
    {
        $svc = $this->svc();
        // PNG is auto-converted to WebP on store → file/url/ext reflect webp
        $res = $svc->store($this->pngBytes(), 'photo.png');

        $this->assertSame('webp', $res['ext']);
        $this->assertSame(1, preg_match('/^[0-9a-f]{16}$/', $res['hash']));
        $this->assertStringContainsString('images/uploads/' . $res['hash'] . '.webp', $res['url']);
        $this->assertFileExists($this->site . '/images/uploads/' . $res['hash'] . '.webp');
    }

    public function test_dedup_same_bytes_one_file(): void
    {
        $svc = $this->svc();
        $png = $this->pngBytes();
        $a = $svc->store($png, 'a.png');
        $b = $svc->store($png, 'b.png');
        $this->assertSame($a['hash'], $b['hash']);
        $files = glob($this->site . '/images/uploads/*.webp');
        $this->assertCount(1, $files);
    }

    public function test_undecodable_raster_falls_back_to_original(): void
    {
        // a tiny PNG that finfo/getimagesize accept but GD cannot decode →
        // graceful fallback stores the original bytes untouched (.png)
        $svc = $this->svc();
        $res = $svc->store(base64_decode(self::PNG), 'tiny.png');
        $this->assertSame('png', $res['ext']);
        $this->assertSame('image/png', $res['mime']);
        $this->assertFileExists($this->site . '/images/uploads/' . $res['hash'] . '.png');
    }

    public function test_svg_is_sanitized_on_store(): void
    {
        $svc = $this->svc();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="4" height="4"/></svg>';
        $res = $svc->store($svg, 'icon.svg');
        $this->assertSame('svg', $res['ext']);
        $stored = (string) file_get_contents($this->site . '/images/uploads/' . $res['hash'] . '.svg');
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringContainsString('<rect', $stored);
    }

    public function test_unsupported_type_rejected(): void
    {
        $svc = $this->svc();
        try {
            $svc->store('this is just text, not an image', 'note.txt');
            $this->fail('expected rejection');
        } catch (UploadException $e) {
            $this->assertSame(415, $e->status);
        }
    }

    public function test_too_large_rejected(): void
    {
        $svc = $this->svc(['upload_max_bytes' => 10]);
        try {
            $svc->store(base64_decode(self::PNG), 'big.png');
            $this->fail('expected 413');
        } catch (UploadException $e) {
            $this->assertSame(413, $e->status);
        }
    }

    public function test_url_keeps_subfolder_prefix(): void
    {
        // site at /demo, cms at /demo/cms → uploads must resolve under /demo (v1 F4-bugfix8)
        $svc = $this->svc(['base_path' => '/demo/cms']);
        $res = $svc->store(base64_decode(self::PNG), 'x.png');
        $this->assertStringStartsWith('/demo/images/uploads/', $res['url']);
    }

    public function test_list_returns_stored_images(): void
    {
        $svc = $this->svc();
        $svc->store(base64_decode(self::PNG), 'one.png');
        $list = $svc->list();
        $this->assertCount(1, $list);
        $this->assertArrayHasKey('url', $list[0]);
        $this->assertArrayHasKey('mtime', $list[0]);
    }

    /* --- gallery: the site's own pictures (gallery_dirs) ------------------ */

    /** Writes a real WebP of the given size into the site under $rel. */
    private function putWebp(string $rel, int $w = 4, int $h = 4): void
    {
        $abs = $this->site . '/' . $rel;
        @mkdir(dirname($abs), 0777, true);
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 90, 90, 220));
        imagewebp($im, $abs, 82);
        imagedestroy($im);
    }

    /** @return array<string, array<string, mixed>> list() entries keyed by name */
    private static function byName(array $list): array
    {
        $out = [];
        foreach ($list as $item) {
            $out[$item['name']] = $item;
        }
        return $out;
    }

    public function test_list_without_gallery_dirs_shows_uploads_only(): void
    {
        $svc = $this->svc();
        $this->putWebp('assets/img/hero.webp');
        $svc->store(base64_decode(self::PNG), 'one.png');

        $list = $svc->list();
        $this->assertCount(1, $list);
        $this->assertSame('upload', $list[0]['source']);
    }

    public function test_list_includes_site_pictures_from_gallery_dirs(): void
    {
        $svc = $this->svc(['gallery_dirs' => ['assets/img']]);
        $this->putWebp('assets/img/hero.webp', 40, 20);
        $svc->store(base64_decode(self::PNG), 'one.png');

        $list = $svc->list();
        $this->assertSame('upload', $list[0]['source'], 'uploads come first');
        $site = self::byName($list)['hero.webp'];
        $this->assertSame('site', $site['source']);
        $this->assertSame('/assets/img/hero.webp', $site['url']);
        $this->assertSame(40, $site['w']);
        $this->assertSame(20, $site['h']);
    }

    public function test_responsive_copies_fold_into_their_original(): void
    {
        $svc = $this->svc(['gallery_dirs' => ['assets/img']]);
        foreach (['hero.webp', 'hero-400.webp', 'hero-800.webp', 'hero-1280.webp', 'card-200.webp'] as $f) {
            $this->putWebp('assets/img/' . $f);
        }

        $names = array_keys(self::byName($svc->list()));
        sort($names);
        // hero-* fold into hero.webp; card-200.webp has no original → listed as is
        $this->assertSame(['card-200.webp', 'hero.webp'], $names);
    }

    public function test_thumbnail_is_the_smallest_copy_that_is_still_sharp(): void
    {
        $svc = $this->svc(['gallery_dirs' => ['assets/img']]);
        foreach (['hero.webp', 'hero-120.webp', 'hero-400.webp', 'hero-800.webp', 'plain.webp'] as $f) {
            $this->putWebp('assets/img/' . $f);
        }

        $items = self::byName($svc->list());
        // 120 is too small for a tile, 400 is the first one ≥ 300
        $this->assertSame('/assets/img/hero-400.webp', $items['hero.webp']['thumb']);
        // no copies → the picture is its own thumbnail
        $this->assertSame('/assets/img/plain.webp', $items['plain.webp']['thumb']);
    }

    public function test_gallery_dirs_outside_the_site_or_into_the_cms_are_ignored(): void
    {
        $outside = ef2_temp_dir('upload-outside');
        $svc = $this->svc(['gallery_dirs' => ['../' . basename($outside), 'cms', 'images/uploads', 'missing']]);
        file_put_contents($outside . '/secret.webp', 'x');
        $this->putWebp('cms/assets/logo.webp');

        $this->assertSame([], $svc->list());
    }

    public function test_store_reports_natural_size(): void
    {
        $svc = $this->svc();
        $res = $svc->store($this->jpegBytes(), 'photo.jpg');
        $this->assertSame(4, $res['w']);
        $this->assertSame(4, $res['h']);
    }

    /* --- WebP auto-conversion (jpeg/png/gif → webp) ----------------------- */

    public function test_png_with_alpha_converts_to_webp(): void
    {
        $svc = $this->svc();
        $res = $svc->store($this->pngBytes(), 'alpha.png');

        $this->assertSame('webp', $res['ext']);
        $this->assertSame('image/webp', $res['mime']);
        $this->assertStringEndsWith('.webp', $res['url']);

        $stored = (string) file_get_contents($this->site . '/images/uploads/' . $res['hash'] . '.webp');
        $this->assertTrue(self::isWebp($stored), 'stored bytes must be a valid RIFF/WEBP container');
        // hash addresses the FINAL (webp) bytes
        $this->assertSame(substr(hash('sha256', $stored), 0, 16), $res['hash']);
    }

    public function test_jpeg_converts_to_webp(): void
    {
        $svc = $this->svc();
        $res = $svc->store($this->jpegBytes(), 'photo.jpg');

        $this->assertSame('webp', $res['ext']);
        $this->assertSame('image/webp', $res['mime']);
        $stored = (string) file_get_contents($this->site . '/images/uploads/' . $res['hash'] . '.webp');
        $this->assertTrue(self::isWebp($stored));
    }

    public function test_gif_converts_to_webp(): void
    {
        $svc = $this->svc();
        $res = $svc->store($this->gifBytes(), 'anim.gif');

        $this->assertSame('webp', $res['ext']);
        $this->assertSame('image/webp', $res['mime']);
        $stored = (string) file_get_contents($this->site . '/images/uploads/' . $res['hash'] . '.webp');
        $this->assertTrue(self::isWebp($stored));
    }

    public function test_webp_passthrough_not_double_processed(): void
    {
        $svc = $this->svc();
        $res = $svc->store($this->webpBytes(), 'already.webp');

        $this->assertSame('webp', $res['ext']);
        $this->assertSame('image/webp', $res['mime']);
        $stored = (string) file_get_contents($this->site . '/images/uploads/' . $res['hash'] . '.webp');
        $this->assertTrue(self::isWebp($stored), 'webp upload must remain a valid WebP, untouched');
    }

    public function test_svg_is_not_converted_to_webp(): void
    {
        $svc = $this->svc();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="4" height="4"/></svg>';
        $res = $svc->store($svg, 'vector.svg');

        $this->assertSame('svg', $res['ext']);
        $this->assertSame('image/svg+xml', $res['mime']);
        $this->assertFileExists($this->site . '/images/uploads/' . $res['hash'] . '.svg');
    }
}
