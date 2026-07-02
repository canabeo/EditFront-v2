<?php

declare(strict_types=1);

namespace EditFront\Reviews;

use EditFront\Support\Config;

/**
 * Atomic JSON store over storage/reviews/items.json + storage/reviews/config.json.
 *
 * Mirrors EditFront\News\NewsStore (same atomic write idiom: flock + xb tmp +
 * fwrite + fflush + fsync + rename + chmod 0640). items.json is PRIMARY data:
 * an empty list is still written as {"items":[]} and the file is never unlinked.
 *
 * A review is text-only with a moderation status:
 *   id, name, country, text, year, status, source, created_at, updated_at
 * status ∈ {pending, approved, rejected}. Only `approved` reviews are rendered
 * onto the site (the publisher filters); pending/rejected stay in the admin
 * queue only.
 */
final class ReviewStore
{
    private const MAX_BYTES = 4_000_000; // 4 MB guard for items.json

    public const STATUSES = ['pending', 'approved', 'rejected'];

    private const MAX_NAME    = 100;
    private const MAX_COUNTRY = 80;
    private const MAX_SOURCE  = 120;
    private const MAX_TEXT    = 6000;

    /** @var array<string,mixed> */
    private const CONFIG_DEFAULTS = [
        // e-mail notification on a new public submission. Empty = do not send.
        'notify_email' => '',
        'notify_from'  => '',
        // human label used in the notification subject line.
        'site_label'   => '',
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

    /** @return list<array<string,mixed>> only items whose status === $status */
    public function byStatus(string $status): array
    {
        return array_values(array_filter(
            $this->items(),
            static fn (array $it): bool => ($it['status'] ?? '') === $status,
        ));
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
        /** @var array<string,mixed> */
        return $this->withItemsLock(function () use ($item): array {
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
        });
    }

    public function remove(string $id): bool
    {
        /** @var bool */
        return $this->withItemsLock(function () use ($id): bool {
            $items   = $this->items();
            $kept    = array_values(array_filter($items, static fn ($it): bool => ($it['id'] ?? null) !== $id));
            $removed = count($kept) !== count($items);
            if ($removed) {
                $this->writeItems($kept); // primary data: rewrite (possibly []), never unlink
            }
            return $removed;
        });
    }

    /** New item id: 'r-'.bin2hex(random_bytes(4)) => r-<hex8> */
    public function nextId(): string
    {
        return 'r-' . bin2hex(random_bytes(4));
    }

    /**
     * OUTPUT ordering: created_at desc (newest first), tie-break id desc. Pure +
     * static so the Publisher reuses it without depending on a store instance.
     *
     * @param  list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public static function sortForOutput(array $items): array
    {
        usort($items, static function (array $a, array $b): int {
            $ca = (string) ($a['created_at'] ?? '');
            $cb = (string) ($b['created_at'] ?? '');
            if ($ca !== $cb) {
                return strcmp($cb, $ca); // created_at desc
            }
            return strcmp((string) ($b['id'] ?? ''), (string) ($a['id'] ?? ''));
        });
        return array_values($items);
    }

    // ---- config ------------------------------------------------------------

    /** @return array<string,mixed> ReviewConfig with defaults merged for missing keys */
    public function config(): array
    {
        $defaults = self::CONFIG_DEFAULTS;

        $file = $this->configFile();
        if (!is_file($file)) {
            return $defaults;
        }
        $decoded = json_decode((string) @file_get_contents($file), true);
        if (!is_array($decoded) || !is_array($decoded['config'] ?? null)) {
            return $defaults;
        }
        // READ is corruption-tolerant (degrade to defaults), WRITE stays strict.
        try {
            return array_merge($defaults, $this->validateConfig($decoded['config']));
        } catch (ReviewException) {
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

    /** Storage root accessor (Config::storageDir()). */
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
        $name = $this->cleanText($item['name'] ?? '', self::MAX_NAME);
        if ($name === '') {
            throw new ReviewException('name is required');
        }

        $text = $this->cleanMultiline($item['text'] ?? '', self::MAX_TEXT);
        if ($text === '') {
            throw new ReviewException('text is required');
        }

        $status = is_string($item['status'] ?? null) ? trim($item['status']) : '';
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'pending';
        }

        $country = $this->cleanText($item['country'] ?? '', self::MAX_COUNTRY);
        $source  = $this->cleanText($item['source'] ?? '', self::MAX_SOURCE);

        $year = is_string($item['year'] ?? null) ? trim($item['year']) : '';
        if (preg_match('/^\d{4}$/', $year) !== 1) {
            $year = '';
        }

        $id  = is_string($item['id'] ?? null) && preg_match('/^r-[0-9a-f]{8}$/', $item['id']) === 1
            ? $item['id']
            : null;
        $now = gmdate('Y-m-d\TH:i:s\Z');

        // preserve created_at when updating a known item; for a NEW item honor a
        // caller-provided ISO created_at (used by the import/seed path to order
        // reviews by their real date), else stamp now.
        $createdAt = $now;
        if ($id !== null) {
            $prev = $this->find($id);
            if ($prev !== null && is_string($prev['created_at'] ?? null)) {
                $createdAt = $prev['created_at'];
            }
        } elseif (is_string($item['created_at'] ?? null)
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $item['created_at']) === 1) {
            $createdAt = $item['created_at'];
        }

        // default the year to the creation year when unset (so the card meta has
        // a sensible value without forcing the visitor to type it).
        if ($year === '') {
            $year = substr($createdAt, 0, 4);
        }

        return [
            'id'         => $id ?? $this->nextId(),
            'name'       => $name,
            'country'    => $country,
            'text'       => $text,
            'year'       => $year,
            'status'     => $status,
            'source'     => $source,
            'created_at' => $createdAt,
            'updated_at' => $now,
        ];
    }

    /**
     * Single-line plain text: strip tags, collapse whitespace, drop control
     * chars, trim, hard-cap. Used for name/country/source.
     */
    private function cleanText(mixed $raw, int $max): string
    {
        if (!is_string($raw)) {
            return '';
        }
        $s = strip_tags($raw);
        $s = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s);
        $s = (string) preg_replace('/\s+/u', ' ', $s);
        $s = trim($s);
        if ($s !== '' && mb_strlen($s) > $max) {
            $s = trim(mb_substr($s, 0, $max));
        }
        return $s;
    }

    /**
     * Multi-line plain text: strip tags + control chars but PRESERVE newlines
     * (collapsed to at most a double newline), trim, hard-cap. Used for the
     * review body. The renderer writes this via textContent, so it stays inert.
     */
    private function cleanMultiline(mixed $raw, int $max): string
    {
        if (!is_string($raw)) {
            return '';
        }
        $s = strip_tags($raw);
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        // drop control chars except newline
        $s = (string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', $s);
        // collapse runs of spaces/tabs (not newlines)
        $s = (string) preg_replace('/[ \t]+/u', ' ', $s);
        // collapse 3+ newlines to a paragraph break
        $s = (string) preg_replace('/\n{3,}/u', "\n\n", $s);
        $s = trim($s);
        if ($s !== '' && mb_strlen($s) > $max) {
            $s = trim(mb_substr($s, 0, $max));
        }
        return $s;
    }

    /**
     * @param  array<string,mixed> $config
     * @return array<string,string>
     */
    private function validateConfig(array $config): array
    {
        $out = [];
        foreach (['notify_email', 'notify_from', 'site_label'] as $key) {
            if (!array_key_exists($key, $config)) {
                continue;
            }
            $value = $config[$key];
            if (!is_string($value)) {
                throw new ReviewException("config.$key must be a string");
            }
            $out[$key] = trim($value);
        }
        foreach (['notify_email', 'notify_from'] as $key) {
            if (isset($out[$key]) && $out[$key] !== '' && !filter_var($out[$key], FILTER_VALIDATE_EMAIL)) {
                throw new ReviewException("config.$key must be a valid e-mail");
            }
        }
        return $out;
    }

    /**
     * Serialize a whole read-modify-write on items.json under ONE exclusive lock,
     * so concurrent writers (e.g. two simultaneous anonymous review submissions
     * on the public submit endpoint) cannot lose an update. The per-tmp-file
     * flock in writeJson() locks a fresh inode each call and therefore does NOT
     * serialize against the shared items.json — this outer lock does. Mirrors the
     * fopen('c')+flock(LOCK_EX) idiom already used by Security\LoginThrottle and
     * Security\RateLimiter.
     *
     * @template T
     * @param  callable():T $fn
     * @return T
     */
    private function withItemsLock(callable $fn): mixed
    {
        $dir = dirname($this->itemsFile());
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new ReviewException('cannot create reviews storage dir');
        }
        $lockFile = $this->itemsFile() . '.lock';
        $fh = @fopen($lockFile, 'c');
        if ($fh === false) {
            // fail-closed: erroring beats risking a silent lost update.
            throw new ReviewException('cannot open reviews lock');
        }
        try {
            flock($fh, LOCK_EX);
            @chmod($lockFile, 0640);
            return $fn();
        } finally {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }

    // ---- internals: paths + atomic write ----------------------------------

    private function itemsFile(): string
    {
        return $this->config->storageDir() . '/reviews/items.json';
    }

    private function configFile(): string
    {
        return $this->config->storageDir() . '/reviews/config.json';
    }

    /** @param list<array<string,mixed>> $items */
    private function writeItems(array $items): void
    {
        $this->writeJson($this->itemsFile(), ['items' => array_values($items)]);
    }

    /**
     * Atomic JSON write. Idiom copied verbatim from NewsStore / PropsStore:
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
            throw new ReviewException('reviews data too large to encode');
        }

        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new ReviewException('cannot create reviews storage dir');
        }

        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        $fh  = @fopen($tmp, 'xb');
        if ($fh === false) {
            throw new ReviewException('cannot create tmp file');
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
            throw new ReviewException('rename failed');
        }
        @chmod($file, 0640);
    }
}
