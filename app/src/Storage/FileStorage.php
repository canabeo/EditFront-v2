<?php

declare(strict_types=1);

namespace EditFront\Storage;

use EditFront\Support\Config;

/**
 * File access inside the site root. Writes are atomic only:
 * flock + tmp + fsync + rename + post-rename short-write check (invariant 0.2.3).
 * Any failure cleans up the tmp file and throws.
 *
 * The site root CONTAINS the CMS folder, so "cms/storage/admin.json" is a
 * perfectly legal relative path needing no traversal — PathGuard cannot help,
 * because the problem is not an escape but an allowed area that is too wide.
 * Every access therefore goes through resolve(), which additionally refuses any
 * path landing inside cmsDir(). That is the chokepoint: page CRUD, uploads and
 * every future caller inherit it instead of each re-deriving the rule.
 */
final class FileStorage
{
    /** realpath of the CMS folder, resolved once (false = unresolvable) */
    private string|false|null $cmsReal = null;

    public function __construct(
        private readonly Config $config,
        private readonly PathGuard $guard,
    ) {
    }

    /**
     * Resolve a site-relative path, refusing anything inside the CMS folder.
     *
     * The CMS is not editable content: its sources, storage/ (password hash),
     * .env and vendor/ must be unreachable through the page/file APIs even
     * though they physically live under the site root.
     */
    private function resolve(string $rel, bool $mustExist): string
    {
        $abs = $this->guard->resolveWithin($this->config->siteRoot(), $rel, $mustExist);

        if ($this->cmsReal === null) {
            $this->cmsReal = realpath($this->config->cmsDir());
        }
        $cms = $this->cmsReal;
        if ($cms === false) {
            return $abs;
        }
        // A degenerate install with the CMS AT the site root would otherwise
        // block everything; there is no separate site to protect in that case.
        $siteReal = realpath($this->config->siteRoot());
        if ($siteReal !== false && rtrim($cms, '/') === rtrim($siteReal, '/')) {
            return $abs;
        }
        if ($abs === $cms || str_starts_with($abs, rtrim($cms, '/') . '/')) {
            throw new InvalidPathException('path is inside the CMS folder: ' . $rel);
        }
        return $abs;
    }

    public function exists(string $rel): bool
    {
        try {
            $abs = $this->resolve($rel, true);
        } catch (StorageException) {
            return false;
        }
        return is_file($abs);
    }

    public function read(string $rel): string
    {
        $abs = $this->resolve($rel, true);
        if (!is_file($abs)) {
            throw new StorageException('not a file: ' . $rel);
        }
        $content = file_get_contents($abs);
        if ($content === false) {
            throw new StorageException('read failed: ' . $rel);
        }
        return $content;
    }

    public function atomicWrite(string $rel, string $content): void
    {
        $abs = $this->resolve($rel, false);
        $this->writeAtomicTo($abs, $rel, $content);
    }

    /**
     * Create a NEW file atomically — fails if the target already exists
     * (Pages CRUD §4.8: existing page → 409, never a silent overwrite).
     * Uses link() for an atomic exclusive create on the final name.
     */
    public function createNew(string $rel, string $content): void
    {
        $abs = $this->resolve($rel, false);
        clearstatcache(true, $abs);
        if (file_exists($abs)) {
            throw new PageExistsException('page already exists: ' . $rel);
        }

        $dir = dirname($abs);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new StorageException('directory not writable: ' . $rel);
        }

        $tmp = $dir . '/.' . basename($abs) . '.tmp.' . bin2hex(random_bytes(6));
        $this->writeBytes($tmp, $rel, $content);
        // link() is atomic and fails if $abs already exists — closes the TOCTOU window
        if (@link($tmp, $abs)) {
            @unlink($tmp);
            @chmod($abs, 0664);
            return;
        }
        // link() unavailable (some FUSE/overlay/network FS) OR the target exists.
        // NEVER fall back to rename: rename overwrites and would defeat the
        // create-only contract on exactly the shared-host filesystems we target
        // (review H2). Use an O_EXCL create on the FINAL name instead.
        @unlink($tmp);
        clearstatcache(true, $abs);
        $fh = @fopen($abs, 'xb');
        if ($fh === false) {
            // EEXIST → conflict (do NOT unlink: the file is not ours)
            if (file_exists($abs)) {
                throw new PageExistsException('page already exists: ' . $rel);
            }
            throw new StorageException('cannot create file for: ' . $rel);
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                throw new StorageException('cannot lock file for: ' . $rel);
            }
            $len = strlen($content);
            if (fwrite($fh, $content) !== $len) {
                throw new StorageException('short write: ' . $rel);
            }
            fflush($fh);
            fsync($fh);
            flock($fh, LOCK_UN);
            fclose($fh);
            $fh = null;
            @chmod($abs, 0664);
        } catch (\Throwable $e) {
            if (is_resource($fh)) {
                @flock($fh, LOCK_UN);
                @fclose($fh);
            }
            @unlink($abs); // safe: we created it via 'xb'
            throw $e;
        }
    }

    /** Delete a file inside the site root (Pages CRUD delete; caller backs up first). */
    public function delete(string $rel): void
    {
        $abs = $this->resolve($rel, true);
        if (!is_file($abs)) {
            throw new StorageException('not a file: ' . $rel);
        }
        if (!@unlink($abs)) {
            throw new StorageException('delete failed: ' . $rel);
        }
    }

    private function writeAtomicTo(string $abs, string $rel, string $content): void
    {
        $dir = dirname($abs);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new StorageException('directory not writable: ' . $rel);
        }
        $tmp = $dir . '/.' . basename($abs) . '.tmp.' . bin2hex(random_bytes(6));
        $len = $this->writeBytes($tmp, $rel, $content);
        try {
            if (!@rename($tmp, $abs)) {
                throw new StorageException('rename failed: ' . $rel);
            }
            @chmod($abs, 0664);
            clearstatcache(true, $abs);
            if (filesize($abs) !== $len) {
                throw new StorageException('post-rename size mismatch: ' . $rel);
            }
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        }
    }

    /** Write $content into $tmp with flock+fsync; cleans up + throws on any failure. Returns byte length. */
    private function writeBytes(string $tmp, string $rel, string $content): int
    {
        $fh = null;
        try {
            $fh = @fopen($tmp, 'xb');
            if ($fh === false) {
                throw new StorageException('cannot create tmp file for: ' . $rel);
            }
            if (!flock($fh, LOCK_EX)) {
                throw new StorageException('cannot lock tmp file for: ' . $rel);
            }
            $len = strlen($content);
            if (fwrite($fh, $content) !== $len) {
                throw new StorageException('short write: ' . $rel);
            }
            fflush($fh);
            fsync($fh);
            flock($fh, LOCK_UN);
            fclose($fh);
            return $len;
        } catch (\Throwable $e) {
            if (is_resource($fh)) {
                @flock($fh, LOCK_UN);
                @fclose($fh);
            }
            @unlink($tmp);
            throw $e;
        }
    }
}
