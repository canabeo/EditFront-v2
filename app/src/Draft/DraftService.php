<?php

declare(strict_types=1);

namespace EditFront\Draft;

use EditFront\Storage\StorageException;
use EditFront\Support\Config;

/**
 * Draft = the serialized CommandLog itself (§7.3): entries + cursor + baseSha,
 * NOT a patch-state snapshot — reload restores history, undo, redo as-is.
 * Atomic write, TTL 30 days, integrity via base_sha256 (checked by the caller
 * against the current page — a stale draft is never applied silently).
 */
final class DraftService
{
    private const TTL_SEC = 30 * 86400;
    private const MAX_BYTES = 2 * 1024 * 1024;
    private const MAX_ENTRIES = 500;

    public function __construct(
        private readonly Config $config,
        private readonly UndoPayloadStore $payloads,
    ) {
    }

    /** @param array<string, mixed> $draft {base_sha256, serial, cursor, entries} */
    public function save(string $page, array $draft): void
    {
        $entries = $draft['entries'] ?? null;
        $cursor = $draft['cursor'] ?? null;
        $baseSha = $draft['base_sha256'] ?? null;
        if (
            !is_array($entries) || $entries !== array_values($entries)
            || count($entries) > self::MAX_ENTRIES
            || !is_int($cursor) || $cursor < 0 || $cursor > count($entries)
            || !is_string($baseSha) || preg_match('/^[0-9a-f]{64}$/', $baseSha) !== 1
        ) {
            throw new StorageException('malformed draft');
        }

        $payload = json_encode([
            'page' => $page,
            'base_sha256' => $baseSha,
            'serial' => (int) ($draft['serial'] ?? 0),
            'cursor' => $cursor,
            'entries' => $entries,
            'updated_at' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false || strlen($payload) > self::MAX_BYTES) {
            throw new StorageException('draft too large — offload heavy payloads first');
        }

        $file = $this->file($page);
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
            throw new StorageException('cannot create drafts dir');
        }
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        $fh = @fopen($tmp, 'xb');
        if ($fh === false) {
            throw new StorageException('cannot create draft tmp');
        }
        try {
            flock($fh, LOCK_EX);
            fwrite($fh, $payload);
            fflush($fh);
            fsync($fh);
        } finally {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new StorageException('draft rename failed');
        }
        @chmod($file, 0640);
    }

    /** @return array<string, mixed>|null null = none (or expired → removed) */
    public function load(string $page): ?array
    {
        $file = $this->file($page);
        if (!is_file($file)) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($file), true);
        if (!is_array($decoded) || !is_int($decoded['updated_at'] ?? null)) {
            $this->delete($page);
            return null;
        }
        if (time() - $decoded['updated_at'] > self::TTL_SEC) {
            $this->delete($page); // expired — clean up incl. undo payloads
            return null;
        }
        return $decoded;
    }

    public function delete(string $page): void
    {
        @unlink($this->file($page));
        $this->payloads->clear($page);
    }

    private function file(string $page): string
    {
        return $this->config->storageDir() . '/drafts/' . sha1($page) . '.json';
    }
}
