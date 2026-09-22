<?php

declare(strict_types=1);

namespace EditFront\Upload;

use EditFront\Http\UrlHelper;
use EditFront\Security\SvgSanitizer;
use EditFront\Storage\FileStorage;
use EditFront\Support\Config;

/**
 * Image upload (§4.9). Files are stored content-addressable (sha256[:16].<ext>,
 * free dedup) in {siteRoot}/images/uploads/ — OUTSIDE cms/, so deleting the CMS
 * never breaks images already referenced by pages (drop-in invariant §3.3).
 * The returned URL is site-relative and built through UrlHelper from the site
 * root (v1 F4-bugfix8: a subfolder install must keep its prefix). MIME is
 * decided from the real bytes via finfo, never the extension; SVG is run
 * through SvgSanitizer and rejected if it does not survive.
 *
 * WebP auto-conversion: newly uploaded jpeg/png/gif are transcoded to WebP
 * (quality 82, alpha preserved) via GD before hashing — the stored file becomes
 * <hash>.webp and ext/mime/url reflect that. webp (already webp), svg (vector,
 * already sanitized) and avif (already efficient; GD avif is inconsistent) pass
 * through unchanged. Conversion is best-effort: if GD cannot decode/encode the
 * bytes, the original is stored as-is (graceful fallback). The size cap is
 * enforced on the original upload; content-addressing hashes the final bytes.
 */
final class UploadService
{
    private const UPLOAD_REL = 'images/uploads';

    /** finfo mime → stored extension (the only accepted raster/vector types) */
    private const MIME_EXT = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/gif' => 'gif',
        'image/svg+xml' => 'svg',
    ];

    /** finfo may report a bare-text/xml mime for SVG → treat as an SVG candidate */
    private const SVG_FALLBACK_MIMES = ['text/plain', 'text/html', 'text/xml', 'application/xml', 'image/svg'];

    private const LIST_EXT = ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif', 'svg'];
    private const MAX_LIST = 500;
    /** the site's own pictures (gallery_dirs) — cap so a huge media folder stays usable */
    private const MAX_SITE_LIST = 2000;
    /** smallest responsive copy width that still looks sharp as a gallery tile */
    private const THUMB_MIN = 300;

    public function __construct(
        private readonly Config $config,
        private readonly FileStorage $storage,
        private readonly UrlHelper $url,
        private readonly SvgSanitizer $svg,
    ) {
    }

    /**
     * Validate + store raw bytes. Returns the picker payload.
     * @return array{url: string, hash: string, name: string, ext: string, size: int, mime: string}
     */
    public function store(string $content, string $clientName): array
    {
        $max = $this->maxBytes();
        if ($content === '') {
            throw new UploadException('empty file');
        }
        if (strlen($content) > $max) {
            throw new UploadException('file too large (max ' . (int) round($max / 1048576) . ' MB)', 413);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->buffer($content);

        $ext = self::MIME_EXT[$mime] ?? null;

        if ($ext === 'svg' || ($ext === null && $this->looksLikeSvg($mime, $content))) {
            $clean = $this->svg->sanitize($content);
            if ($clean === null) {
                throw new UploadException('SVG rejected by sanitizer', 422);
            }
            $content = $clean;
            $ext = 'svg';
            $mime = 'image/svg+xml';
        } elseif ($ext === null) {
            throw new UploadException('unsupported file type: ' . $mime, 415);
        } else {
            // raster integrity: real image bytes must parse (avif: trust finfo —
            // getimagesizefromstring lacks avif support on some builds)
            if ($ext !== 'avif' && @getimagesizefromstring($content) === false) {
                throw new UploadException('not a valid image', 422);
            }
            // jpeg/png/gif → WebP (best-effort; webp/avif pass through)
            if (in_array($ext, ['jpg', 'png', 'gif'], true)) {
                $webp = $this->toWebp($content);
                if ($webp !== null) {
                    $content = $webp;
                    $ext = 'webp';
                    $mime = 'image/webp';
                }
            }
        }

        $hash = substr(hash('sha256', $content), 0, 16);
        $file = $hash . '.' . $ext;
        $rel = self::UPLOAD_REL . '/' . $file;

        $this->ensureDir();
        if (!$this->storage->exists($rel)) {
            $this->storage->atomicWrite($rel, $content);
        }

        [$w, $h] = $this->dimensions($content);

        return [
            'url' => $this->url->siteUrl($rel),
            'hash' => $hash,
            'name' => $this->displayName($clientName),
            'ext' => $ext,
            'size' => strlen($content),
            'mime' => $mime,
            'w' => $w,
            'h' => $h,
        ];
    }

    /**
     * The picker's gallery: CMS uploads (newest first) followed by the site's own
     * pictures from `gallery_dirs` (alphabetical). Every entry carries `source`
     * ('upload' | 'site') so the editor can group them.
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return array_merge($this->listUploads(), $this->listSiteImages());
    }

    /** @return list<array{url: string, name: string, size: int, mtime: int, source: string}> newest first */
    private function listUploads(): array
    {
        $dir = $this->absDir();
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (glob($dir . '/*') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $name = basename($path);
            $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, self::LIST_EXT, true)) {
                continue;
            }
            $out[] = [
                'url' => $this->url->siteUrl(self::UPLOAD_REL . '/' . $name),
                'name' => $name,
                'size' => (int) @filesize($path),
                'mtime' => (int) @filemtime($path),
                'source' => 'upload',
            ];
        }
        usort($out, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
        return array_slice($out, 0, self::MAX_LIST);
    }

    /**
     * Pictures the site already has, from the folders named in `gallery_dirs`.
     *
     * Static sites keep responsive copies next to the original (`hero.webp`,
     * `hero-800.webp`, `hero-1280.webp`). Listing every copy would bury the
     * gallery, so a file named `<base>-<width>.<ext>` is folded into `<base>.<ext>`
     * when that original exists; the smallest copy of at least THUMB_MIN px becomes
     * the thumbnail, so the grid does not download full-size photos. A `-<number>`
     * file without an original next to it is an ordinary picture and is listed.
     *
     * Folders must resolve inside the site root and never into the CMS itself or
     * the uploads folder (already listed above); anything else is skipped silently.
     *
     * @return list<array<string, mixed>> alphabetical
     */
    private function listSiteImages(): array
    {
        $dirs = $this->config->get('gallery_dirs', []);
        if (!is_array($dirs) || $dirs === []) {
            return [];
        }
        $root = realpath($this->config->siteRoot());
        if ($root === false) {
            return [];
        }
        $cms = realpath($this->config->cmsDir());
        $uploads = realpath($this->absDir());

        $out = [];
        $seen = [];
        foreach ($dirs as $rel) {
            $rel = trim((string) $rel, '/');
            $abs = realpath($root . '/' . $rel);
            if (
                $abs === false || !is_dir($abs)
                || !str_starts_with($abs . '/', $root . '/')
                || ($cms !== false && str_starts_with($abs . '/', $cms . '/'))
                || ($uploads !== false && $abs === $uploads)
                || isset($seen[$abs])
            ) {
                continue;
            }
            $seen[$abs] = true;
            $relDir = trim(substr($abs, strlen($root)), '/');

            $names = [];
            foreach (glob($abs . '/*') ?: [] as $path) {
                $name = basename($path);
                $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
                if (is_file($path) && in_array($ext, self::LIST_EXT, true)) {
                    $names[$name] = $path;
                }
            }

            // fold responsive copies into their original
            $copies = [];
            foreach (array_keys($names) as $name) {
                if (preg_match('/^(.+)-(\d{2,4})w?\.([a-z0-9]+)$/i', $name, $m) === 1) {
                    $original = $m[1] . '.' . $m[3];
                    if (isset($names[$original])) {
                        $copies[$original][(int) $m[2]] = $name;
                        unset($names[$name]);
                    }
                }
            }

            foreach ($names as $name => $path) {
                $thumb = $name;
                if (isset($copies[$name])) {
                    ksort($copies[$name]);
                    foreach ($copies[$name] as $width => $copy) {
                        $thumb = $copy;
                        if ($width >= self::THUMB_MIN) {
                            break;
                        }
                    }
                }
                [$w, $h] = $this->fileDimensions($path);
                $out[] = [
                    'url' => $this->url->siteUrl($relDir . '/' . $name),
                    'thumb' => $this->url->siteUrl($relDir . '/' . $thumb),
                    'name' => $name,
                    'size' => (int) @filesize($path),
                    'mtime' => (int) @filemtime($path),
                    'w' => $w,
                    'h' => $h,
                    'source' => 'site',
                ];
                if (count($out) >= self::MAX_SITE_LIST) {
                    break 2;
                }
            }
        }

        usort($out, static fn (array $a, array $b): int => strnatcasecmp((string) $a['name'], (string) $b['name']));
        return $out;
    }

    /** @return array{0: int, 1: int} width/height of raster bytes; 0/0 for SVG or unreadable */
    private function dimensions(string $content): array
    {
        $info = @getimagesizefromstring($content);
        return is_array($info) ? [(int) $info[0], (int) $info[1]] : [0, 0];
    }

    /** @return array{0: int, 1: int} */
    private function fileDimensions(string $path): array
    {
        $info = @getimagesize($path);
        return is_array($info) ? [(int) $info[0], (int) $info[1]] : [0, 0];
    }

    public function maxBytes(): int
    {
        $v = (int) $this->config->get('upload_max_bytes', 10 * 1024 * 1024);
        return $v > 0 ? $v : 10 * 1024 * 1024;
    }

    private function looksLikeSvg(string $mime, string $content): bool
    {
        return in_array($mime, self::SVG_FALLBACK_MIMES, true)
            && stripos($content, '<svg') !== false;
    }

    /**
     * Transcode raster bytes to WebP (quality 82), preserving transparency for
     * png/gif. Returns null on any GD failure so the caller keeps the original.
     */
    private function toWebp(string $content): ?string
    {
        if (!function_exists('imagewebp')) {
            return null;
        }
        $im = @imagecreatefromstring($content);
        if ($im === false) {
            return null; // graceful fallback — store the original bytes
        }
        @imagepalettetotruecolor($im);
        @imagealphablending($im, false);
        @imagesavealpha($im, true);
        ob_start();
        $ok = @imagewebp($im, null, 82);
        $webp = (string) ob_get_clean();
        imagedestroy($im);
        return ($ok && $webp !== '') ? $webp : null;
    }

    private function displayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1f]+/', '', $name);
        return $name !== '' ? mb_substr($name, 0, 120) : 'image';
    }

    private function absDir(): string
    {
        return $this->config->siteRoot() . '/' . self::UPLOAD_REL;
    }

    private function ensureDir(): void
    {
        $dir = $this->absDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new UploadException('cannot create uploads directory');
        }
    }
}
