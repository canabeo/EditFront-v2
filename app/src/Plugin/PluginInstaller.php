<?php

declare(strict_types=1);

namespace EditFront\Plugin;

use EditFront\Support\Config;
use Psr\Log\LoggerInterface;

/**
 * Install a plugin from an uploaded ZIP, or uninstall by removing its folder
 * (admin UI). Plugin PHP is TRUSTED code (single-admin Free, §6.7) — the admin
 * who uploads it is the one who runs it — but the upload path is still hardened
 * against the classic ZIP attacks:
 *
 *  - zip-slip: every entry name is validated (no `..`, absolute, backslash,
 *    null) AND each file is extracted through an explicit safe path-join with a
 *    realpath-containment check — extractTo() is never trusted blindly.
 *  - one top-level folder == the slug; the staged manifest must pass the same
 *    PluginManifest validation as a boot-time plugin before it is moved into
 *    place. A bad archive never lands in plugins/.
 *  - size / entry-count caps; an existing slug is a 409 (uninstall first).
 *
 * The fixtures-gate is NOT run here — boot() runs it and degrades a broken
 * plugin to read-only, so a freshly installed-but-broken plugin can never
 * register its ops; install only guarantees a structurally valid manifest.
 */
final class PluginInstaller
{
    private const SLUG_RE = '/^[a-z][a-z0-9-]{1,39}$/';
    private const MAX_UNCOMPRESSED = 16 * 1024 * 1024; // 16 MB
    private const MAX_ENTRIES = 800;

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{slug: string} */
    public function install(string $zipPath): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new PluginInstallException('the PHP zip extension is required to install plugins from the browser', 422);
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
            throw new PluginInstallException('cannot open the uploaded file as a ZIP', 422);
        }

        try {
            $slug = $this->validateArchive($zip);

            $target = $this->pluginsDir() . '/' . $slug;
            clearstatcache(true, $target);
            if (is_dir($target)) {
                throw new PluginInstallException('a plugin named "' . $slug . '" is already installed — uninstall it first', 409);
            }

            $staging = $this->makeStaging();
            try {
                $this->extractSafely($zip, $staging);
                $this->validateStaged($staging, $slug);
                $this->moveIntoPlace($staging . '/' . $slug, $target);
            } finally {
                $this->rrmdir($staging);
            }
        } finally {
            $zip->close();
        }

        $this->logger->info('plugin.install', ['slug' => $slug]);
        return ['slug' => $slug];
    }

    public function uninstall(string $slug): void
    {
        if (preg_match(self::SLUG_RE, $slug) !== 1) {
            throw new PluginInstallException('bad slug', 422);
        }
        $pluginsReal = realpath($this->pluginsDir());
        $dirReal = realpath($this->pluginsDir() . '/' . $slug);
        if ($pluginsReal === false || $dirReal === false) {
            throw new PluginInstallException('plugin not installed: ' . $slug, 404);
        }
        // containment: never delete anything outside plugins/ (no symlink escape)
        if ($dirReal === $pluginsReal || !str_starts_with($dirReal, $pluginsReal . '/')) {
            throw new PluginInstallException('refusing to remove a path outside plugins/', 422);
        }
        $this->rrmdir($dirReal);
        $this->logger->info('plugin.uninstall', ['slug' => $slug]);
    }

    /** Enumerate + validate every entry; returns the single top-level slug. */
    private function validateArchive(\ZipArchive $zip): string
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new PluginInstallException('archive has too many files', 422);
        }
        $tops = [];
        $totalSize = 0;
        $hasManifest = false;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $this->assertSafeEntry($name);

            $stat = $zip->statIndex($i);
            $totalSize += is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;
            if ($totalSize > self::MAX_UNCOMPRESSED) {
                throw new PluginInstallException('archive contents exceed the size limit', 422);
            }

            $top = explode('/', trim($name, '/'))[0] ?? '';
            if ($top !== '') {
                $tops[$top] = true;
            }
            if ($name === ($top . '/plugin.json')) {
                $hasManifest = true;
            }
        }

        if (count($tops) !== 1) {
            throw new PluginInstallException('the ZIP must contain exactly one top-level plugin folder', 422);
        }
        $slug = (string) array_key_first($tops);
        if (preg_match(self::SLUG_RE, $slug) !== 1) {
            throw new PluginInstallException('the plugin folder name is not a valid slug (a-z, 0-9, -)', 422);
        }
        if (!$hasManifest) {
            throw new PluginInstallException('the ZIP has no ' . $slug . '/plugin.json', 422);
        }
        return $slug;
    }

    private function assertSafeEntry(string $name): void
    {
        if (
            $name === ''
            || str_contains($name, "\0")
            || str_contains($name, '\\')
            || str_starts_with($name, '/')
            || preg_match('#(^|/)\.\.(/|$)#', $name) === 1
            || preg_match('#^[A-Za-z]:#', $name) === 1
        ) {
            throw new PluginInstallException('unsafe path in archive: ' . $name, 422);
        }
    }

    /** Extract entry-by-entry through a safe join + realpath containment. */
    private function extractSafely(\ZipArchive $zip, string $dest): void
    {
        $destReal = realpath($dest);
        if ($destReal === false) {
            throw new PluginInstallException('staging dir missing', 500);
        }
        $written = 0; // real inflated bytes — the declared statIndex size is attacker metadata
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $this->assertSafeEntry($name);
            $abs = $destReal . '/' . $name;

            if (str_ends_with($name, '/')) {
                if (!is_dir($abs) && !@mkdir($abs, 0770, true) && !is_dir($abs)) {
                    throw new PluginInstallException('cannot create dir: ' . $name, 500);
                }
                continue;
            }
            $dir = dirname($abs);
            if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
                throw new PluginInstallException('cannot create dir for: ' . $name, 500);
            }
            // containment: the resolved parent dir must stay inside the staging root
            $dirReal = realpath($dir);
            if ($dirReal === false || ($dirReal !== $destReal && !str_starts_with($dirReal, $destReal . '/'))) {
                throw new PluginInstallException('archive entry escapes staging: ' . $name, 422);
            }
            $bytes = $zip->getFromIndex($i);
            if ($bytes === false) {
                throw new PluginInstallException('cannot read archive entry: ' . $name, 422);
            }
            $written += strlen($bytes);
            if ($written > self::MAX_UNCOMPRESSED) {
                // a zip bomb over-declares small sizes in validateArchive but inflates
                // huge here — cap on the REAL bytes (review M1)
                throw new PluginInstallException('archive contents exceed the size limit', 422);
            }
            if (@file_put_contents($abs, $bytes) === false) {
                throw new PluginInstallException('cannot write: ' . $name, 500);
            }
        }
    }

    private function validateStaged(string $staging, string $slug): void
    {
        $pluginDir = $staging . '/' . $slug;
        $manifestPath = $pluginDir . '/plugin.json';
        if (!is_file($manifestPath)) {
            throw new PluginInstallException('plugin.json missing after extract', 422);
        }
        $raw = json_decode((string) @file_get_contents($manifestPath), true);
        if (!is_array($raw)) {
            throw new PluginInstallException('plugin.json is not valid JSON', 422);
        }
        try {
            // same structural validation as boot — slug==folder, safe paths, kinds, etc.
            $manifest = PluginManifest::fromArray($raw, $pluginDir, PluginManager::CORE_API);
        } catch (PluginException $e) {
            throw new PluginInstallException('invalid plugin: ' . $e->getMessage(), 422);
        }
        // the files the manifest points at must actually be present in the archive
        foreach ([$manifest->serverPhp, $manifest->fixtures] as $rel) {
            if (!is_file($pluginDir . '/' . $rel)) {
                throw new PluginInstallException('plugin is missing a required file: ' . $rel, 422);
            }
        }
    }

    private function moveIntoPlace(string $stagedDir, string $target): void
    {
        // re-check right before the move: a concurrent install of the same slug
        // could have created it after the early is_dir() check (review L2)
        clearstatcache(true, $target);
        if (is_dir($target)) {
            throw new PluginInstallException('a plugin with this name was just installed — try again', 409);
        }
        if (@rename($stagedDir, $target)) {
            return;
        }
        // cross-device rename fails → recursive copy (never MERGE into an existing dir)
        clearstatcache(true, $target);
        if (is_dir($target)) {
            throw new PluginInstallException('a plugin with this name was just installed — try again', 409);
        }
        $this->rcopy($stagedDir, $target);
        if (!is_file($target . '/plugin.json')) {
            $this->rrmdir($target);
            throw new PluginInstallException('failed to move plugin into place', 500);
        }
    }

    private function makeStaging(): string
    {
        $base = $this->config->storageDir() . '/tmp';
        if (!is_dir($base) && !@mkdir($base, 0770, true) && !is_dir($base)) {
            throw new PluginInstallException('cannot create staging dir', 500);
        }
        $dir = $base . '/plugin-install-' . bin2hex(random_bytes(6));
        if (!@mkdir($dir, 0770, true)) {
            throw new PluginInstallException('cannot create staging dir', 500);
        }
        return $dir;
    }

    private function pluginsDir(): string
    {
        return $this->config->cmsDir() . '/plugins';
    }

    private function rcopy(string $src, string $dst): void
    {
        @mkdir($dst, 0770, true);
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $rel = substr($item->getPathname(), strlen($src) + 1);
            $to = $dst . '/' . $rel;
            if ($item->isDir()) {
                @mkdir($to, 0770, true);
            } else {
                @copy($item->getPathname(), $to);
            }
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isLink() || !$item->isDir()) {
                @unlink($item->getPathname());
            } else {
                @rmdir($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
