<?php

declare(strict_types=1);

namespace EditFront\Storage;

use EditFront\Support\Config;

/**
 * Mandatory pre-save snapshots: storage/backups/pre-save/<sha1(page)>/<ts>.html.gz.
 * The snapshot is verified by decode round-trip — no verified backup, no save
 * (invariant 0.2.3). GC keeps the newest N and is guarded against a missing
 * retention key (v1 bug: null retention wiped ALL pre-save snapshots).
 */
final class BackupService
{
    private const DEFAULT_RETENTION = 20;
    private const ID_PATTERN = '/^\d{8}-\d{6}-\d{6}-[0-9a-f]{6}$/';

    public function __construct(private readonly Config $config)
    {
    }

    /** @return string backup id; throws StorageException on any failure */
    public function preSave(string $relPath, string $content): string
    {
        $dir = $this->pageDir($relPath);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
            throw new StorageException('cannot create backup dir for: ' . $relPath);
        }

        // microsecond component keeps ids sortable within one second (GC order)
        $mt = microtime(true);
        $id = date('Ymd-His', (int) $mt)
            . sprintf('-%06d', (int) round(($mt - floor($mt)) * 1_000_000))
            . '-' . bin2hex(random_bytes(3));
        $file = $dir . '/' . $id . '.html.gz';

        $gz = gzencode($content, 6);
        if ($gz === false) {
            throw new StorageException('gzip failed for: ' . $relPath);
        }
        if (@file_put_contents($file, $gz) !== strlen($gz)) {
            @unlink($file);
            throw new StorageException('backup write failed for: ' . $relPath);
        }
        // verify round-trip: a corrupt snapshot must abort the save
        $decoded = @gzdecode((string) @file_get_contents($file));
        if ($decoded !== $content) {
            @unlink($file);
            throw new StorageException('backup verification failed for: ' . $relPath);
        }
        @chmod($file, 0640);

        $this->gc($dir);
        return $id;
    }

    /** @return list<array{id: string, file: string, mtime: int, size: int}> newest first */
    public function listForPage(string $relPath): array
    {
        $out = [];
        foreach (glob($this->pageDir($relPath) . '/*.html.gz') ?: [] as $file) {
            $out[] = [
                'id' => basename($file, '.html.gz'),
                'file' => $file,
                'mtime' => (int) @filemtime($file),
                'size' => (int) @filesize($file),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($b['id'], $a['id']));
        return $out;
    }

    /**
     * Decode one snapshot's HTML. The id is format-validated so it can never
     * escape the page's backup dir.
     */
    public function readContent(string $relPath, string $id): string
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new StorageException('invalid backup id');
        }
        $file = $this->pageDir($relPath) . '/' . $id . '.html.gz';
        if (!is_file($file)) {
            throw new StorageException('backup not found: ' . $id);
        }
        $decoded = @gzdecode((string) @file_get_contents($file));
        if ($decoded === false) {
            throw new StorageException('backup decode failed: ' . $id);
        }
        return $decoded;
    }

    private function pageDir(string $relPath): string
    {
        return $this->config->storageDir() . '/backups/pre-save/' . sha1($relPath);
    }

    private function gc(string $dir): void
    {
        $retention = $this->config->get('backup_retention');
        if (!is_int($retention) || $retention < 1) {
            $retention = self::DEFAULT_RETENTION; // null/garbage guard — never "delete everything"
        }
        $files = glob($dir . '/*.html.gz') ?: [];
        if (count($files) <= $retention) {
            return;
        }
        sort($files); // timestamped names → chronological
        foreach (array_slice($files, 0, count($files) - $retention) as $old) {
            @unlink($old);
        }
    }
}
