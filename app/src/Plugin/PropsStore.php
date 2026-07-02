<?php

declare(strict_types=1);

namespace EditFront\Plugin;

use EditFront\Storage\StorageException;
use EditFront\Support\Config;

/**
 * Per-instance plugin props sidecar (§6.5): storage/meta/<sha1(page)>.json →
 * elements[<data-cms-id>] = {kind, version, props}. The DOM is the renderable
 * truth; this sidecar is the structured props for re-edit (serialize() is the
 * fallback if it is lost). Reconciled from the DOM on every save, so it never
 * drifts. Atomic flock+tmp+fsync+rename, like every other writer.
 */
final class PropsStore
{
    private const MAX_BYTES = 4 * 1024 * 1024;

    public function __construct(private readonly Config $config)
    {
    }

    /** @return array<string, array<string, mixed>> elements map (id → {kind,version,props}) */
    public function load(string $page): array
    {
        $file = $this->file($page);
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($file), true);
        if (!is_array($decoded) || !is_array($decoded['elements'] ?? null)) {
            return [];
        }
        return $decoded['elements'];
    }

    /** @return array<string, mixed>|null */
    public function get(string $page, string $id): ?array
    {
        $els = $this->load($page);
        $el = $els[$id] ?? null;
        return is_array($el) ? $el : null;
    }

    /**
     * Replace the whole elements map with $map (drops ids no longer present →
     * deleted plugin nodes lose their sidecar entry). Empty map removes the
     * file so non-plugin pages keep zero sidecars.
     * @param array<string, array<string, mixed>> $map
     */
    public function sync(string $page, array $map): void
    {
        $file = $this->file($page);
        if ($map === []) {
            @unlink($file);
            return;
        }
        $payload = json_encode([
            'page' => $page,
            'elements' => $map,
            'updated_at' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false || strlen($payload) > self::MAX_BYTES) {
            throw new StorageException('props sidecar too large');
        }

        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
            throw new StorageException('cannot create meta dir');
        }
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        $fh = @fopen($tmp, 'xb');
        if ($fh === false) {
            throw new StorageException('cannot create props tmp');
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
            throw new StorageException('props rename failed');
        }
        @chmod($file, 0640);
    }

    public function delete(string $page): void
    {
        @unlink($this->file($page));
    }

    private function file(string $page): string
    {
        return $this->config->storageDir() . '/meta/' . sha1($page) . '.json';
    }
}
