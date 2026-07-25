<?php

declare(strict_types=1);

namespace EditFront\Install;

use EditFront\Support\Config;

/**
 * Install-wizard step 1 (§9 C5): environment probes. Each probe returns a
 * status (ok|warn|fail) and whether it is blocking. The wizard refuses to
 * create the admin while any blocking probe fails (missing PHP ext, version,
 * unwritable storage). Non-blocking probes (mod_rewrite on nginx, .env
 * writability) are advisory only.
 */
final class SelfCheck
{
    /**
     * Canary written into storage/ so the wizard can prove, from the browser,
     * that the directory is not served over HTTP. Deliberately NOT a dotfile:
     * it has to be as reachable as admin.json is, or the probe proves nothing.
     */
    private const CANARY = 'webcheck.txt';

    public function __construct(
        private readonly Config $config,
        private readonly EnvFile $env,
    ) {
    }

    /** @return list<array{key: string, status: string, blocking: bool, detail: string}> */
    public function run(): array
    {
        $checks = [];

        $checks[] = $this->probe(
            'php_version',
            PHP_VERSION_ID >= 80200,
            true,
            PHP_VERSION
        );

        foreach (['dom', 'mbstring', 'json', 'fileinfo'] as $ext) {
            $checks[] = $this->probe('ext_' . $ext, extension_loaded($ext), true, $ext);
        }

        $storage = $this->config->storageDir();
        $checks[] = $this->probe(
            'storage_writable',
            is_dir($storage) && is_writable($storage),
            true,
            $storage
        );

        $site = $this->config->siteRoot();
        $checks[] = $this->probe(
            'site_root',
            is_dir($site) && is_readable($site),
            true,
            $site
        );

        // uploads dir: created on first upload — only warn if the site root is read-only
        $uploads = $site . '/images';
        $checks[] = $this->probe(
            'uploads_dir',
            is_writable($site) || is_dir($uploads),
            false,
            $uploads
        );

        $checks[] = $this->probe(
            'env_writable',
            $this->env->isWritable(),
            false,
            $this->env->path()
        );

        // mod_rewrite is Apache-specific; on nginx the front controller is wired
        // in the server config → advisory, never blocking
        [$rwOk, $rwDetail] = $this->rewriteStatus();
        $checks[] = $this->probe('mod_rewrite', $rwOk, false, $rwDetail);

        $bp = $this->config->basePath();
        $checks[] = $this->probe('base_path', true, false, $bp === '' ? '/' : $bp);

        return $checks;
    }

    /**
     * Prepare the storage-exposure probe: drop a canary with a random token into
     * storage/ and return the URL the browser should fetch. On Apache the
     * bundled .htaccess denies storage/; nginx ignores .htaccess entirely, so on
     * a misconfigured nginx deployment storage/admin.json (the password hash) is
     * public. The wizard fetches this URL client-side — a server-side loopback
     * request would risk deadlocking the only PHP-FPM worker on cheap hosting.
     *
     * @return array{url: string, token: string}|null null when storage lives
     *   outside the CMS folder (no URL can reach it) or the canary is unwritable
     */
    public function prepareExposureProbe(): ?array
    {
        $storageReal = realpath($this->config->storageDir());
        $cmsReal = realpath($this->config->cmsDir());
        if ($storageReal === false || $cmsReal === false) {
            return null;
        }
        $prefix = rtrim($cmsReal, '/\\') . DIRECTORY_SEPARATOR;
        if (!str_starts_with($storageReal, $prefix)) {
            return null; // storage moved out of the document root — nothing to probe
        }

        $token = bin2hex(random_bytes(16));
        if (@file_put_contents($storageReal . DIRECTORY_SEPARATOR . self::CANARY, $token) === false) {
            return null;
        }
        @chmod($storageReal . DIRECTORY_SEPARATOR . self::CANARY, 0640);

        $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($storageReal, strlen($prefix)));
        return [
            'url' => $this->config->basePath() . '/' . $rel . '/' . self::CANARY,
            'token' => $token,
        ];
    }

    /** Remove the canary once the wizard is done with it. */
    public function clearExposureProbe(): void
    {
        $storageReal = realpath($this->config->storageDir());
        if ($storageReal !== false) {
            @unlink($storageReal . DIRECTORY_SEPARATOR . self::CANARY);
        }
    }

    public function canProceed(): bool
    {
        foreach ($this->run() as $c) {
            if ($c['blocking'] && $c['status'] === 'fail') {
                return false;
            }
        }
        return true;
    }

    /** @return array{0: bool, 1: string} */
    private function rewriteStatus(): array
    {
        if (function_exists('apache_get_modules')) {
            $mods = apache_get_modules();
            return [in_array('mod_rewrite', $mods, true), 'Apache'];
        }
        $sw = (string) ($_SERVER['SERVER_SOFTWARE'] ?? '');
        if (stripos($sw, 'nginx') !== false) {
            return [true, 'nginx (front controller via server config)'];
        }
        return [true, $sw !== '' ? $sw : 'unknown server'];
    }

    /** @return array{key: string, status: string, blocking: bool, detail: string} */
    private function probe(string $key, bool $ok, bool $blocking, string $detail): array
    {
        return [
            'key' => $key,
            'status' => $ok ? 'ok' : ($blocking ? 'fail' : 'warn'),
            'blocking' => $blocking,
            'detail' => $detail,
        ];
    }
}
