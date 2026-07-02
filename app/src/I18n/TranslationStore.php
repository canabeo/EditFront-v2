<?php

declare(strict_types=1);

namespace EditFront\I18n;

use EditFront\Support\Config;

/**
 * Read/write layer for the admin-UI translation editor (§8). The editor never
 * touches the shipped builtin files — it writes a thin OVERRIDE layer to
 * lang/user/<locale>.json (which the Translator merges last, and which is
 * gitignored). Only values that actually differ from the baseline are kept, so
 * unchanged keys keep flowing from builtin and survive product updates.
 */
final class TranslationStore
{
    private const KEY_RE = '/^[a-z0-9][a-z0-9._-]*$/';
    private const CODE_RE = '/^[a-z]{2,8}$/';
    private const MAX_FILE_BYTES = 512 * 1024;
    private const MAX_VALUE_LEN = 2000;

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Baseline value map for a locale: builtin/en ← plugins/en ← builtin/<loc>
     * ← plugins/<loc>. This is exactly what the Translator would resolve WITHOUT
     * the user override layer — i.e. the reference the editor lets you override.
     *
     * @return array<string, string>
     */
    public function baseline(string $locale): array
    {
        if (preg_match(self::CODE_RE, $locale) !== 1) {
            return [];
        }
        $merged = $this->load($this->dir() . '/builtin/en.json');
        foreach ($this->pluginFiles('en') as $f) {
            $merged = array_merge($merged, $this->load($f));
        }
        if ($locale !== 'en') {
            $merged = array_merge($merged, $this->load($this->dir() . '/builtin/' . $locale . '.json'));
            foreach ($this->pluginFiles($locale) as $f) {
                $merged = array_merge($merged, $this->load($f));
            }
        }
        return $merged;
    }

    /** @return array<string, string> current user overrides for a locale */
    public function overrides(string $locale): array
    {
        if (preg_match(self::CODE_RE, $locale) !== 1) {
            return [];
        }
        return $this->load($this->userFile($locale));
    }

    /**
     * Persist overrides. Keeps only known keys whose trimmed value is non-empty
     * and differs from the baseline; drops the rest (so clearing a field removes
     * the override). Deletes the file when nothing remains. Returns the number
     * of overrides kept.
     *
     * @param array<string, mixed> $pairs key => value (raw form input)
     */
    public function saveOverrides(string $locale, array $pairs): int
    {
        if (preg_match(self::CODE_RE, $locale) !== 1) {
            throw new \RuntimeException('bad locale');
        }
        $base = $this->baseline($locale);
        $clean = [];
        foreach ($pairs as $key => $value) {
            if (!is_string($key) || preg_match(self::KEY_RE, $key) !== 1) {
                continue;
            }
            if (!array_key_exists($key, $base) || !is_string($value)) {
                continue;
            }
            $value = trim($value);
            if ($value === '' || mb_strlen($value) > self::MAX_VALUE_LEN || $value === $base[$key]) {
                continue;
            }
            $clean[$key] = $value;
        }

        $file = $this->userFile($locale);
        if ($clean === []) {
            // A user-added language (no builtin file) must survive even with zero
            // overrides — otherwise its tab would vanish on the next save. A
            // builtin language simply reverts fully to the shipped file.
            if (!$this->hasBuiltin($locale)) {
                $this->atomicWrite($file, '{}');
            } elseif (is_file($file)) {
                @unlink($file);
            }
            return 0;
        }
        ksort($clean);
        $this->atomicWrite($file, (string) json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return count($clean);
    }

    /**
     * Register a new language by creating an empty user override file for it.
     * The Translator then lists it as supported (baseline falls back to English
     * until the admin translates keys). Rejects bad codes and existing languages.
     */
    public function addLanguage(string $code): void
    {
        $code = strtolower(trim($code));
        if (preg_match(self::CODE_RE, $code) !== 1) {
            throw new \RuntimeException('bad language code');
        }
        if ($this->hasBuiltin($code) || is_file($this->userFile($code))) {
            throw new \RuntimeException('language already exists');
        }
        $this->atomicWrite($this->userFile($code), '{}');
    }

    private function hasBuiltin(string $locale): bool
    {
        return is_file($this->dir() . '/builtin/' . $locale . '.json');
    }

    private function dir(): string
    {
        return $this->config->cmsDir() . '/lang';
    }

    private function userFile(string $locale): string
    {
        return $this->dir() . '/user/' . $locale . '.json';
    }

    /** @return list<string> */
    private function pluginFiles(string $code): array
    {
        $out = [];
        foreach (glob($this->config->cmsDir() . '/plugins/*/lang/' . $code . '.json') ?: [] as $f) {
            $out[] = $f;
        }
        return $out;
    }

    /** @return array<string, string> sane keys only */
    private function load(string $path): array
    {
        if (!is_file($path) || (int) filesize($path) > self::MAX_FILE_BYTES) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $k => $v) {
            if (is_string($k) && is_string($v) && preg_match(self::KEY_RE, $k) === 1) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private function atomicWrite(string $file, string $contents): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $contents) !== strlen($contents)) {
            @unlink($tmp);
            throw new \RuntimeException('cannot write translations file');
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('cannot move translations file into place');
        }
    }
}
