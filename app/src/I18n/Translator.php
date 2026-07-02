<?php

declare(strict_types=1);

namespace EditFront\I18n;

use EditFront\Support\Config;

/**
 * UI i18n (§8). Flat keys, no DB. The active-language dictionary is the merge
 * (lowest→highest priority): builtin/en baseline ← plugin builtin/en ← builtin/
 * <locale> ← plugin <locale> ← user/<locale>. A key missing in the active
 * language therefore falls back to English, never to a raw key — and the WHOLE
 * merged dict is what ships to the client (no prefix filtering, v1 lesson).
 *
 * t(key, params, fallback) has the identical signature server-side and client-
 * side (§8.3); the 3rd `fallback` arg is honored on both.
 */
final class Translator
{
    public const DEFAULT = 'en';
    private const KEY_RE = '/^[a-z0-9][a-z0-9._-]*$/';
    private const MAX_FILE_BYTES = 512 * 1024;
    private const CODE_RE = '/^[a-z]{2,8}$/';

    private string $current = self::DEFAULT;
    /** @var array<string, array<string, string>> locale → merged dict (memoized) */
    private array $cache = [];
    /** @var list<string>|null */
    private ?array $supported = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function setCurrent(string $locale): void
    {
        $this->current = in_array($locale, $this->supportedLanguages(), true) ? $locale : self::DEFAULT;
    }

    public function current(): string
    {
        return $this->current;
    }

    /** @return list<string> languages with a builtin or user file; 'en' always present */
    public function supportedLanguages(): array
    {
        if ($this->supported !== null) {
            return $this->supported;
        }
        $codes = ['en' => true];
        foreach (['builtin', 'user'] as $sub) {
            foreach (glob($this->langDir() . '/' . $sub . '/*.json') ?: [] as $f) {
                $code = strtolower(basename($f, '.json'));
                if (preg_match(self::CODE_RE, $code) === 1) {
                    $codes[$code] = true;
                }
            }
        }
        $list = array_keys($codes);
        sort($list);
        return $this->supported = $list;
    }

    /** @param array<string, scalar>|null $params */
    public function t(string $key, ?array $params = null, ?string $fallback = null, ?string $locale = null): string
    {
        $dict = $this->dict($locale ?? $this->current);
        $s = array_key_exists($key, $dict) ? $dict[$key] : ($fallback ?? $key);
        if ($params !== null) {
            foreach ($params as $k => $v) {
                $s = str_replace(':' . $k, (string) $v, $s);
            }
        }
        return $s;
    }

    /** @return array<string, string> full merged dict for a locale */
    public function dict(string $locale): array
    {
        if (!in_array($locale, $this->supportedLanguages(), true)) {
            $locale = self::DEFAULT;
        }
        if (isset($this->cache[$locale])) {
            return $this->cache[$locale];
        }

        $merged = [];
        // baseline English first (so any missing active-language key shows English)
        $merged = array_merge($merged, $this->loadFile($this->langDir() . '/builtin/en.json'));
        foreach ($this->pluginLangFiles('en') as $f) {
            $merged = array_merge($merged, $this->loadFile($f));
        }
        if ($locale !== 'en') {
            $merged = array_merge($merged, $this->loadFile($this->langDir() . '/builtin/' . $locale . '.json'));
            foreach ($this->pluginLangFiles($locale) as $f) {
                $merged = array_merge($merged, $this->loadFile($f));
            }
        }
        // user overrides win
        $merged = array_merge($merged, $this->loadFile($this->langDir() . '/user/' . $locale . '.json'));

        return $this->cache[$locale] = $merged;
    }

    public function dictJson(string $locale): string
    {
        // HEX_TAG/APOS/QUOT so a value containing </script> or quotes can never
        // break out of the inline <script> the dict is injected into (§8.3)
        return (string) json_encode(
            $this->dict($locale),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }

    private function langDir(): string
    {
        return $this->config->cmsDir() . '/lang';
    }

    /** @return list<string> plugins/{slug}/lang/<code>.json paths */
    private function pluginLangFiles(string $code): array
    {
        $out = [];
        foreach (glob($this->config->cmsDir() . '/plugins/*/lang/' . $code . '.json') ?: [] as $f) {
            $out[] = $f;
        }
        return $out;
    }

    /** @return array<string, string> sane keys only */
    private function loadFile(string $path): array
    {
        if (!is_file($path) || filesize($path) > self::MAX_FILE_BYTES) {
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
}
