<?php

declare(strict_types=1);

namespace EditFront\Storage;

use EditFront\Support\Config;

/**
 * Listing of editable .html pages in the site root with an mtime/TTL cache
 * (§3.7 — never rescan the whole tree on every dashboard GET).
 * The cache is invalidated when the site root dir mtime changes (top-level
 * create/delete) or after TTL; page CRUD must call invalidate() explicitly.
 */
final class PagesIndex
{
    private const MAX_PAGES = 5000;

    public function __construct(private readonly Config $config)
    {
    }

    /** @return list<array{path: string, size: int, mtime: int}> */
    public function list(bool $fresh = false): array
    {
        $cacheFile = $this->cacheFile();
        $ttl = (int) $this->config->get('pages_index_ttl', 60);

        if (!$fresh && is_file($cacheFile)) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
            if (
                is_array($cached)
                && is_int($cached['scanned_at'] ?? null)
                && is_array($cached['pages'] ?? null)
                && time() - $cached['scanned_at'] < $ttl
                && (int) ($cached['root_mtime'] ?? -1) === (int) @filemtime($this->config->siteRoot())
            ) {
                /** @var list<array{path: string, size: int, mtime: int}> */
                return $cached['pages'];
            }
        }

        $pages = $this->scan();
        $this->writeCache($cacheFile, $pages);
        return $pages;
    }

    public function invalidate(): void
    {
        @unlink($this->cacheFile());
    }

    private function cacheFile(): string
    {
        return $this->config->storageDir() . '/cache/pages-index.json';
    }

    /** @return list<array{path: string, size: int, mtime: int}> */
    private function scan(): array
    {
        $root = realpath($this->config->siteRoot());
        if ($root === false) {
            return [];
        }
        $cmsDir = realpath($this->config->cmsDir());

        $inner = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator(
            $inner,
            static function (\SplFileInfo $f) use ($cmsDir): bool {
                if ($f->isDir()) {
                    if (str_starts_with($f->getFilename(), '.')) {
                        return false;
                    }
                    if ($cmsDir !== false && $f->getRealPath() === $cmsDir) {
                        return false;
                    }
                }
                return true;
            }
        );

        $pages = [];
        foreach (new \RecursiveIteratorIterator($filter) as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || preg_match('/\.html?$/i', $file->getFilename()) !== 1) {
                continue;
            }
            $pages[] = [
                'path' => substr($file->getPathname(), strlen($root) + 1),
                'size' => (int) $file->getSize(),
                'mtime' => (int) $file->getMTime(),
            ];
            if (count($pages) >= self::MAX_PAGES) {
                break;
            }
        }

        usort($pages, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        return $pages;
    }

    /** @param list<array{path: string, size: int, mtime: int}> $pages */
    private function writeCache(string $cacheFile, array $pages): void
    {
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $payload = json_encode([
            'scanned_at' => time(),
            'root_mtime' => (int) @filemtime($this->config->siteRoot()),
            'pages' => $pages,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // best-effort: a failed cache write must never break the listing
        $tmp = $cacheFile . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, (string) $payload) !== false) {
            @chmod($tmp, 0640);
            @rename($tmp, $cacheFile);
        }
        @unlink($tmp);
    }
}
