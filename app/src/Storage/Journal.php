<?php

declare(strict_types=1);

namespace EditFront\Storage;

use EditFront\Support\Config;

/**
 * Append-only per-page operation journal (§4.4): one JSONL file per page,
 * rotated to .gz over 10 MB — never a file per command (§3.7 inode budget).
 * Best-effort: a journal failure must never break an already-completed save.
 */
final class Journal
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(private readonly Config $config)
    {
    }

    /** @param list<array<string, mixed>> $ops */
    public function append(string $relPath, array $ops, string $batchId): void
    {
        $dir = $this->config->storageDir() . '/journal';
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
            return;
        }
        $file = $dir . '/' . sha1($relPath) . '.jsonl';
        $this->rotateIfNeeded($file);

        $fh = @fopen($file, 'ab');
        if ($fh === false) {
            return;
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                return;
            }
            $now = time();
            $lines = json_encode(['_batch' => $batchId, 'ts' => $now, 'page' => $relPath]) . "\n";
            foreach ($ops as $op) {
                $lines .= json_encode($op + ['ts' => $now], JSON_UNESCAPED_UNICODE) . "\n";
            }
            fwrite($fh, $lines);
            fflush($fh);
        } finally {
            @flock($fh, LOCK_UN);
            @fclose($fh);
            @chmod($file, 0640);
        }
    }

    /** Remove the live journal + all rotated archives for a page (delete-page cleanup §4.8). */
    public function delete(string $relPath): void
    {
        $base = $this->config->storageDir() . '/journal/' . sha1($relPath);
        @unlink($base . '.jsonl');
        foreach (glob($base . '.*.jsonl.gz') ?: [] as $archive) {
            @unlink($archive);
        }
    }

    private function rotateIfNeeded(string $file): void
    {
        clearstatcache(true, $file);
        if (!is_file($file) || (int) @filesize($file) < self::MAX_BYTES) {
            return;
        }
        $content = (string) @file_get_contents($file);
        $gz = gzencode($content, 6);
        if ($gz === false) {
            return;
        }
        $archive = substr($file, 0, -6) . '.' . date('Ymd-His') . '.jsonl.gz';
        if (@file_put_contents($archive, $gz) === strlen($gz)) {
            @unlink($file);
        }
    }
}
