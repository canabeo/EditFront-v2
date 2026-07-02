<?php

declare(strict_types=1);

namespace EditFront\Security;

use EditFront\Support\Config;

/**
 * File-based login throttle with tiered escalation (§13):
 *   - after CAPTCHA_AFTER (2) failures in the window → a math CAPTCHA is required;
 *   - after BLOCK_AFTER  (5) failures in the window → the IP is blocked BLOCK_SEC (10 min).
 *
 * Failures are counted per IP AND per username (sha1, lower-cased). Hard blocks
 * are IP-only — blocking by username would let an attacker lock the real admin
 * out (self-DoS). The username counter only escalates to CAPTCHA, which slows a
 * DISTRIBUTED brute-force (many IPs, one account) without locking anyone out.
 *
 * Read-modify-write goes through flock on the counters file; expired entries are
 * pruned on every write and the map is bounded to MAX_KEYS against username spraying.
 */
final class LoginThrottle
{
    private const WINDOW_SEC = 900;   // 15-minute sliding window for counting failures
    private const CAPTCHA_AFTER = 2;  // failures in window → require CAPTCHA
    private const BLOCK_AFTER = 5;    // failures in window → block the IP
    private const BLOCK_SEC = 600;    // 10-minute block
    private const MAX_KEYS = 500;     // bound the counters file against username spraying

    public function __construct(private readonly Config $config)
    {
    }

    /** Seconds the IP remains blocked, 0 when not blocked. */
    public function blockedFor(string $ip, ?int $now = null): int
    {
        $now ??= time();
        $remaining = 0;
        $this->withLock(function (array $data) use ($ip, $now, &$remaining): ?array {
            $until = (int) ($data[$ip]['blocked_until'] ?? 0);
            $remaining = max(0, $until - $now);
            return null; // read-only
        });
        return $remaining;
    }

    /** True once the IP or the username has reached CAPTCHA_AFTER failures in the window. */
    public function captchaRequired(string $ip, string $username = '', ?int $now = null): bool
    {
        $now ??= time();
        $required = false;
        $this->withLock(function (array $data) use ($ip, $username, $now, &$required): ?array {
            $ipFails = count($this->recent($data[$ip]['fails'] ?? [], $now));
            $userFails = 0;
            if ($username !== '') {
                $userFails = count($this->recent($data[$this->userKey($username)]['fails'] ?? [], $now));
            }
            $required = max($ipFails, $userFails) >= self::CAPTCHA_AFTER;
            return null; // read-only
        });
        return $required;
    }

    public function registerFailure(string $ip, string $username = '', ?int $now = null): void
    {
        $now ??= time();
        $this->withLock(function (array $data) use ($ip, $username, $now): array {
            // IP bucket — escalates to a hard block at BLOCK_AFTER.
            $ipFails = $this->recent($data[$ip]['fails'] ?? [], $now);
            $ipFails[] = $now;
            $blockedUntil = (int) ($data[$ip]['blocked_until'] ?? 0);
            if (count($ipFails) >= self::BLOCK_AFTER) {
                $blockedUntil = $now + self::BLOCK_SEC;
                $ipFails = [];
            }
            $data[$ip] = ['fails' => $ipFails, 'blocked_until' => $blockedUntil];

            // Username bucket — CAPTCHA only, never a hard block (anti-DoS).
            if ($username !== '') {
                $uk = $this->userKey($username);
                if (isset($data[$uk]) || count($data) < self::MAX_KEYS) {
                    $uFails = $this->recent($data[$uk]['fails'] ?? [], $now);
                    $uFails[] = $now;
                    $data[$uk] = ['fails' => $uFails];
                }
            }

            return $this->prune($data, $now);
        });
    }

    public function clear(string $ip, string $username = '', ?int $now = null): void
    {
        $this->withLock(function (array $data) use ($ip, $username): array {
            unset($data[$ip]);
            if ($username !== '') {
                unset($data[$this->userKey($username)]);
            }
            return $data;
        });
    }

    /**
     * @param mixed $fails
     * @return list<int> timestamps still inside the window
     */
    private function recent(mixed $fails, int $now): array
    {
        return array_values(array_filter(
            (array) $fails,
            static fn ($ts): bool => is_int($ts) && $now - $ts < self::WINDOW_SEC
        ));
    }

    /**
     * Drop entries that are fully expired (no recent failures and no live block).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function prune(array $data, int $now): array
    {
        foreach ($data as $key => $entry) {
            $hasFails = $this->recent(is_array($entry) ? ($entry['fails'] ?? []) : [], $now) !== [];
            $blocked = is_array($entry) && (int) ($entry['blocked_until'] ?? 0) > $now;
            if (!$hasFails && !$blocked) {
                unset($data[$key]);
            }
        }
        return $data;
    }

    private function userKey(string $username): string
    {
        return 'u:' . sha1(mb_strtolower(trim($username)));
    }

    /**
     * @param callable(array<string, mixed>): ?array<string, mixed> $fn
     *        Receives decoded counters; returns updated array or null to skip writing.
     */
    private function withLock(callable $fn): void
    {
        $file = $this->config->storageDir() . '/locks/login-throttle.json';
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        $fh = fopen($file, 'c+');
        if ($fh === false) {
            return; // throttle is best-effort: never break login on FS trouble
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                return;
            }
            $raw = stream_get_contents($fh);
            $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            if (!is_array($data)) {
                $data = [];
            }

            $updated = $fn($data);
            if ($updated !== null) {
                $payload = (string) json_encode($updated, JSON_UNESCAPED_SLASHES);
                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, $payload);
                fflush($fh);
                fsync($fh);
                @chmod($file, 0640);
            }
        } finally {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }
}
