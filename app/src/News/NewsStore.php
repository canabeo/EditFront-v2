<?php

declare(strict_types=1);

namespace EditFront\News;

use EditFront\Support\Config;

/**
 * Atomic JSON store over storage/news/items.json + storage/news/config.json.
 *
 * items.json is PRIMARY data (not a per-page sha1 sidecar): an empty list is
 * still written as {"items":[]} and the file is never unlinked. config.json is
 * seeded in-memory with defaults on first read; only saveConfig() writes it.
 *
 * Write idiom (flock + xb tmp + fwrite + fflush + fsync + rename + chmod 0640)
 * is copied from EditFront\Plugin\PropsStore.
 */
final class NewsStore
{
    private const MAX_BYTES = 4_000_000; // 4 MB guard for items.json

    /** @var array<string,mixed> */
    private const CONFIG_DEFAULTS = [
        'template_page' => '_news-template.html',
        'title_suffix'  => '',
        'base_url'      => '',
        'date_locale'   => 'ru',
    ];

    public function __construct(private readonly Config $config)
    {
    }

    // ---- items -------------------------------------------------------------

    /** @return list<array<string,mixed>> file-order (unsorted); [] when no file */
    public function items(): array
    {
        $file = $this->itemsFile();
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($file), true);
        if (!is_array($decoded) || !is_array($decoded['items'] ?? null)) {
            return [];
        }
        return array_values(array_filter(
            $decoded['items'],
            static fn ($it): bool => is_array($it) && isset($it['id']) && is_string($it['id']),
        ));
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        foreach ($this->items() as $item) {
            if (($item['id'] ?? null) === $id) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Validate shape + persist. Creates a new item (mints id + timestamps) when
     * 'id' is absent/unknown; updates in place (keeps created_at) when present.
     *
     * @param  array<string,mixed> $item
     * @return array<string,mixed> the stored, normalized item
     */
    public function upsert(array $item): array
    {
        $normalized = $this->normalize($item);
        $items      = $this->items();

        $replaced = false;
        foreach ($items as $i => $existing) {
            if (($existing['id'] ?? null) === $normalized['id']) {
                $items[$i] = $normalized;
                $replaced  = true;
                break;
            }
        }
        if (!$replaced) {
            $items[] = $normalized;
        }

        $this->writeItems($items);
        return $normalized;
    }

    public function remove(string $id): bool
    {
        $items   = $this->items();
        $kept    = array_values(array_filter($items, static fn ($it): bool => ($it['id'] ?? null) !== $id));
        $removed = count($kept) !== count($items);
        if ($removed) {
            $this->writeItems($kept); // primary data: rewrite (possibly []), never unlink
        }
        return $removed;
    }

    /** New item id: 'n-'.bin2hex(random_bytes(4)) => n-<hex8> */
    public function nextId(): string
    {
        return 'n-' . bin2hex(random_bytes(4));
    }

    /**
     * OUTPUT ordering: date desc, tie-break created_at desc. Pure + static so
     * the plan-N3 Publisher reuses it without depending on a store instance.
     *
     * @param  list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public static function sortForOutput(array $items): array
    {
        usort($items, static function (array $a, array $b): int {
            $da = (string) ($a['date'] ?? '');
            $db = (string) ($b['date'] ?? '');
            if ($da !== $db) {
                return strcmp($db, $da); // date desc
            }
            $ca = (string) ($a['created_at'] ?? '');
            $cb = (string) ($b['created_at'] ?? '');
            return strcmp($cb, $ca); // created_at desc
        });
        return array_values($items);
    }

    // ---- config ------------------------------------------------------------

    /** @return array<string,mixed> NewsConfig with defaults merged for missing keys */
    public function config(): array
    {
        $defaults = self::CONFIG_DEFAULTS;
        // base_url fallback: config.json empty => Config::get('site_base_url')
        $defaults['base_url'] = (string) $this->config->get('site_base_url', '');

        $file = $this->configFile();
        if (!is_file($file)) {
            return $defaults; // in-memory seed, no write
        }
        $decoded = json_decode((string) @file_get_contents($file), true);
        if (!is_array($decoded) || !is_array($decoded['config'] ?? null)) {
            return $defaults;
        }
        // READ is corruption-tolerant, WRITE stays strict (mirrors items() vs
        // upsert()): a schema-drifted config.json must degrade to defaults
        // rather than make every read throw. saveConfig() still validates so a
        // human editing config gets immediate feedback.
        try {
            return array_merge($defaults, $this->validateConfig($decoded['config']));
        } catch (NewsException) {
            return $defaults;
        }
    }

    /**
     * Validate + persist config.
     *
     * @param  array<string,mixed> $config
     * @return array<string,mixed> the stored, merged config
     */
    public function saveConfig(array $config): array
    {
        $merged = array_merge(self::CONFIG_DEFAULTS, $this->validateConfig($config));
        $this->writeJson($this->configFile(), ['config' => $merged]);
        return $merged;
    }

    /**
     * Storage root accessor — used by NewsPublisher to resolve per-page sidecar
     * directories when cleaning up a deleted article (FileStorage exposes no
     * storage-dir accessor; the canonical path is Config::storageDir()).
     */
    public function storageDir(): string
    {
        return $this->config->storageDir();
    }

    // ---- internals: validation --------------------------------------------

    /**
     * @param  array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function normalize(array $item): array
    {
        $title = is_string($item['title'] ?? null) ? trim($item['title']) : '';
        if ($title === '') {
            throw new NewsException('title is required');
        }

        $date = is_string($item['date'] ?? null) ? trim($item['date']) : '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new NewsException("date must be ISO 'YYYY-MM-DD', got: " . $date);
        }

        $slug = is_string($item['slug'] ?? null) ? trim($item['slug']) : '';
        if ($slug === '') {
            throw new NewsException('slug is required');
        }

        $id  = is_string($item['id'] ?? null) && preg_match('/^n-[0-9a-f]{8}$/', $item['id']) === 1
            ? $item['id']
            : null;
        $now = gmdate('Y-m-d\TH:i:s\Z');

        // preserve created_at when updating a known item
        $createdAt = $now;
        if ($id !== null) {
            $prev = $this->find($id);
            if ($prev !== null && is_string($prev['created_at'] ?? null)) {
                $createdAt = $prev['created_at'];
            }
        }

        $str = static fn (mixed $v): string => is_string($v) ? trim($v) : '';

        $titleShort = $str($item['title_short'] ?? '');
        $cover      = $str($item['cover'] ?? '');
        $coverOg    = $str($item['cover_og'] ?? '');

        return [
            'id'               => $id ?? $this->nextId(),
            'slug'             => $slug,
            'title'            => $title,
            'title_short'      => $titleShort !== '' ? $titleShort : $title,
            'category'         => $str($item['category'] ?? ''),
            'date'             => $date,
            'cover'            => $cover,
            'cover_og'         => $coverOg !== '' ? $coverOg : $cover,
            'excerpt'          => $str($item['excerpt'] ?? ''),
            'body_html'        => is_string($item['body_html'] ?? null) ? $item['body_html'] : '',
            'gallery'          => self::normalizeGallery($item['gallery'] ?? null),
            'gallery_position' => self::normalizeGalleryPosition($item['gallery_position'] ?? null),
            'published'        => (bool) ($item['published'] ?? false),
            'created_at'       => $createdAt,
            'updated_at'       => $now,
        ];
    }

    /** Max number of gallery images kept per item (excess is dropped). */
    public const MAX_GALLERY = 50;

    /**
     * Normalize a raw gallery value into a clean ordered list of safe image
     * URLs. Static + public so NewsPublisher::normalize reuses EXACTLY this
     * validation (the renderer trusts the list it gets, so both write paths
     * must agree). Rules:
     *   - input that is not an array ⇒ [];
     *   - non-string / blank entries dropped;
     *   - each URL trimmed, then gated: keep only http(s) absolute or a
     *     site-relative / relative path; REJECT data:, javascript:, vbscript:
     *     and protocol-relative `//host` (which would load attacker-controlled
     *     third-party images — mirrors the NewsBodySanitizer img gate);
     *   - the kept list is capped at MAX_GALLERY (first N, order preserved).
     *
     * @param  mixed $raw
     * @return list<string>
     */
    public static function normalizeGallery(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            $url = self::safeGalleryUrl($entry);
            if ($url !== null) {
                $out[] = $url;
            }
            if (count($out) >= self::MAX_GALLERY) {
                break;
            }
        }
        return $out;
    }

    /**
     * Gate one gallery URL. Returns the trimmed URL when safe, null otherwise.
     * Safe = http(s) absolute, OR a relative/root-relative/anchor/query path.
     * Unsafe = empty, data:, javascript:, vbscript: (or any non-http(s)
     * scheme), protocol-relative `//host`, or anything carrying a NUL/control
     * char before the scheme (obfuscation guard, mirrors SanitizerUrl).
     */
    private static function safeGalleryUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2000 || str_contains($url, "\0")) {
            return null;
        }

        // probe on a control-char-free copy so "java\tscript:" can't slip past
        $probe = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $url));

        // protocol-relative `//host/...` would fetch a third-party image — reject
        if (str_starts_with($probe, '//')) {
            return null;
        }

        if (preg_match('~^([a-z][a-z0-9+.-]*):~', $probe, $m) === 1) {
            // a scheme is present — only http/https are allowed for images
            return in_array($m[1], ['http', 'https'], true) ? $url : null;
        }

        // schemeless: relative path / root-relative / anchor / query — fine
        return $url;
    }

    /**
     * Normalize gallery_position: only 'before' | 'after' are valid; anything
     * else (junk, non-string, missing) becomes the default 'after'.
     */
    public static function normalizeGalleryPosition(mixed $raw): string
    {
        $val = is_string($raw) ? trim($raw) : '';
        return $val === 'before' ? 'before' : 'after';
    }

    /**
     * @param  array<string,mixed> $config
     * @return array<string,string>
     */
    private function validateConfig(array $config): array
    {
        $out = [];
        foreach (['template_page', 'title_suffix', 'base_url', 'date_locale'] as $key) {
            if (!array_key_exists($key, $config)) {
                continue;
            }
            $value = $config[$key];
            if (!is_string($value)) {
                throw new NewsException("config.$key must be a string");
            }
            // title_suffix is concatenated raw onto <title> (spec §1.1, contract §3:
            // example ' — Новости компании'), so its leading/trailing space is
            // load-bearing and must be preserved. Structural fields are trimmed.
            $out[$key] = $key === 'title_suffix' ? $value : trim($value);
        }
        if (isset($out['template_page']) && $out['template_page'] === '') {
            throw new NewsException('config.template_page cannot be empty');
        }
        return $out;
    }

    // ---- internals: paths + atomic write ----------------------------------

    private function itemsFile(): string
    {
        return $this->config->storageDir() . '/news/items.json';
    }

    private function configFile(): string
    {
        return $this->config->storageDir() . '/news/config.json';
    }

    /** @param list<array<string,mixed>> $items */
    private function writeItems(array $items): void
    {
        $this->writeJson($this->itemsFile(), ['items' => array_values($items)]);
    }

    /**
     * Atomic JSON write. Idiom copied verbatim from PropsStore:
     * flock + fopen('xb') tmp + fwrite + fflush + fsync + rename + chmod 0640.
     *
     * @param array<string,mixed> $body
     */
    private function writeJson(string $file, array $body): void
    {
        $payload = json_encode(
            $body + ['updated_at' => time()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        if ($payload === false || strlen($payload) > self::MAX_BYTES) {
            throw new NewsException('news data too large to encode');
        }

        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new NewsException('cannot create news storage dir');
        }

        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        $fh  = @fopen($tmp, 'xb'); // exclusive create; fails rather than clobber a stale tmp
        if ($fh === false) {
            throw new NewsException('cannot create tmp file');
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

        if (!@rename($tmp, $file)) { // rename is the atomic publish step
            @unlink($tmp);
            throw new NewsException('rename failed');
        }
        @chmod($file, 0640);
    }
}
