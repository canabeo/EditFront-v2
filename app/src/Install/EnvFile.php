<?php

declare(strict_types=1);

namespace EditFront\Install;

use EditFront\Support\Config;

/**
 * Minimal .env reader/writer for the install wizard (§9 C5). On a fresh
 * drop-in the install dir is owned by the web user → writable, so the wizard
 * can create/extend .env (APP_SECRET, ANNOTATE_ONLY). Writes are best-effort:
 * if .env is not writable the caller falls back to a storage/ secret file —
 * the install must never hard-fail over a config convenience.
 */
final class EnvFile
{
    public function __construct(private readonly Config $config)
    {
    }

    public function path(): string
    {
        return $this->config->cmsDir() . '/.env';
    }

    public function get(string $key): ?string
    {
        $file = $this->path();
        if (!is_file($file)) {
            return null;
        }
        foreach (preg_split('/\R/', (string) @file_get_contents($file)) ?: [] as $line) {
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=(.*)$/', $line, $m) === 1) {
                return trim($m[1], " \t\"'");
            }
        }
        return null;
    }

    public function isWritable(): bool
    {
        $file = $this->path();
        return is_file($file) ? is_writable($file) : is_writable(dirname($file));
    }

    /** Set/replace a key. Returns false if the file could not be written. */
    public function set(string $key, string $value): bool
    {
        if (!$this->isWritable()) {
            return false;
        }
        $file = $this->path();
        $lines = is_file($file) ? (preg_split('/\R/', (string) @file_get_contents($file)) ?: []) : [];
        $line = $key . '=' . $value;
        $replaced = false;
        foreach ($lines as $i => $l) {
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/', $l) === 1) {
                $lines[$i] = $line;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            $lines[] = $line;
        }
        // drop a trailing empty element to avoid blank-line growth
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }
        $content = implode("\n", $lines) . "\n";

        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $content) !== strlen($content)) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }
}
