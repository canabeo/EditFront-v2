<?php

declare(strict_types=1);

/**
 * Plain config array built from environment. All boolean env values go through
 * filter_var(FILTER_VALIDATE_BOOLEAN) — (bool)"false" is true in PHP.
 */

$env = static function (string $key, ?string $default = null): ?string {
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($v === false || $v === null || $v === '') {
        return $default;
    }
    return (string) $v;
};

$cmsDir = dirname(__DIR__, 2);
$storageDir = rtrim($env('STORAGE_DIR') ?? $cmsDir . '/storage', '/');

return [
    // URL prefix the CMS lives under ('' = site root, default drop-in: /cms)
    'base_path' => rtrim($env('BASE_PATH', '/cms') ?? '/cms', '/'),
    // Directory with the user's static site (default: parent of the cms folder)
    'site_root' => rtrim($env('SITE_ROOT') ?? dirname($cmsDir), '/'),
    'cms_dir' => $cmsDir,
    'storage_dir' => $storageDir,
    'debug' => filter_var($env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
    'session_name' => 'cms_sid',
    'admin_file' => $storageDir . '/admin.json',
    // Pages listing cache TTL, seconds (§3.7 — do not rescan the tree on every GET)
    'pages_index_ttl' => 60,
    // §4.1: stamp ids without full recanonicalization at open (user opt-in, wizard step 2)
    'annotate_only' => filter_var($env('ANNOTATE_ONLY', 'false'), FILTER_VALIDATE_BOOLEAN),
    // pre-save snapshots kept per page (§3.7 inode budget)
    'backup_retention' => max(1, (int) ($env('BACKUP_RETENTION', '20') ?? 20)),
    // §4.9 image upload cap (bytes); default 10 MB
    'upload_max_bytes' => max(1, (int) ($env('UPLOAD_MAX_BYTES', '10485760') ?? 10485760)),
    // sitemap.xml origin; empty → derived from the request (§9 C5)
    'site_base_url' => rtrim($env('SITE_BASE_URL', '') ?? '', '/'),
];
