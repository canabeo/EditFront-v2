<?php

declare(strict_types=1);

namespace EditFront\Draft;

use EditFront\Storage\StorageException;
use EditFront\Support\Config;

/**
 * Sidecar for HEAVY command payloads (§7.2): a command whose payload exceeds
 * INLINE_INVERSE_CAP is NOT stored inline in the draft — the client offloads
 * {forward, undo} here and the draft keeps только ссылку. This is what keeps
 * the "every action is undoable" promise at any payload size: no command is
 * ever volatile. Cleared together with the draft on save/discard.
 */
final class UndoPayloadStore
{
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const ID_PATTERN = '/^cmd-[0-9a-f]{8,24}$/';

    public function __construct(private readonly Config $config)
    {
    }

    public function save(string $page, string $cmdId, string $payloadJson): void
    {
        $this->assertCmdId($cmdId);
        if (strlen($payloadJson) > self::MAX_BYTES) {
            throw new StorageException('undo payload too large');
        }
        $dir = $this->pageDir($page);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
            throw new StorageException('cannot create undo payload dir');
        }
        $gz = gzencode($payloadJson, 6);
        if ($gz === false) {
            throw new StorageException('gzip failed for undo payload');
        }
        $file = $dir . '/' . $cmdId . '.json.gz';
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $gz) !== strlen($gz) || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new StorageException('undo payload write failed');
        }
        @chmod($file, 0640);
    }

    public function load(string $page, string $cmdId): ?string
    {
        $this->assertCmdId($cmdId);
        $file = $this->pageDir($page) . '/' . $cmdId . '.json.gz';
        if (!is_file($file)) {
            return null;
        }
        $decoded = @gzdecode((string) @file_get_contents($file));
        return $decoded === false ? null : $decoded;
    }

    /** Remove all payloads of a page (draft saved-or-discarded). */
    public function clear(string $page): void
    {
        $dir = $this->pageDir($page);
        foreach (glob($dir . '/*.json.gz') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    private function pageDir(string $page): string
    {
        return $this->config->storageDir() . '/undo/' . sha1($page);
    }

    private function assertCmdId(string $cmdId): void
    {
        if (preg_match(self::ID_PATTERN, $cmdId) !== 1) {
            throw new StorageException('invalid command id');
        }
    }
}
