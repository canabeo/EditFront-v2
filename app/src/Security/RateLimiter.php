<?php

declare(strict_types=1);

namespace EditFront\Security;

use EditFront\Support\Config;

/**
 * Generic per-bucket/per-key sliding-window rate limiter on one flock'ed
 * JSON file (§13: save 60/min, upload 20/min, ...). Best-effort: FS trouble
 * must never block legitimate requests.
 */
final class RateLimiter
{
    public function __construct(private readonly Config $config)
    {
    }

    public function allow(string $bucket, string $key, int $max, int $windowSec, ?int $now = null): bool
    {
        $now ??= time();
        $file = $this->config->storageDir() . '/locks/api-throttle.json';
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        $fh = @fopen($file, 'c+');
        if ($fh === false) {
            return true;
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                return true;
            }
            $raw = stream_get_contents($fh);
            $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            if (!is_array($data)) {
                $data = [];
            }

            $hits = array_values(array_filter(
                (array) ($data[$bucket][$key] ?? []),
                static fn ($ts): bool => is_int($ts) && $now - $ts < $windowSec
            ));

            $allowed = count($hits) < $max;
            if ($allowed) {
                $hits[] = $now;
            }
            $data[$bucket][$key] = $hits;

            $payload = (string) json_encode($data);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $payload);
            fflush($fh);
            @chmod($file, 0640);

            return $allowed;
        } finally {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }
}
