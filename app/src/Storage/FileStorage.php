<?php

declare(strict_types=1);

namespace EditFront\Storage;

use EditFront\Support\Config;

/**
 * File access inside the site root. Writes are atomic only:
 * flock + tmp + fsync + rename + post-rename short-write check (invariant 0.2.3).
 * Any failure cleans up the tmp file and throws.
 */
final class FileStorage
{
    public function __construct(
        private readonly Config $config,
        private readonly PathGuard $guard,
    ) {
    }

    public function exists(string $rel): bool
    {
        try {
            $abs = $this->guard->resolveWithin($this->config->siteRoot(), $rel, true);
        } catch (StorageException) {
            return false;
        }
        return is_file($abs);
    }

    public function read(string $rel): string
    {
        $abs = $this->guard->resolveWithin($this->config->siteRoot(), $rel, true);
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
        $abs = $this->guard->resolveWithin($this->config->siteRoot(), $rel, false);
        $this->writeAtomicTo($abs, $rel, $content);
    }

    /**
     * Create a NEW file atomically — fails if the target already exists
     * (Pages CRUD §4.8: existing page → 409, never a silent overwrite).
     * Uses link() for an atomic exclusive create on the final name.
     */
    public function createNew(string $rel, string $content): void
    {
        $abs = $this->guard->resolveWithin($this->config->siteRoot(), $rel, false);
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
        $abs = $this->guard->resolveWithin($this->config->siteRoot(), $rel, true);
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
