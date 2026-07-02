<?php

declare(strict_types=1);

namespace EditFront\Plugin;

use EditFront\Document\Annotator;
use EditFront\Document\Html5;
use EditFront\Operation\OperationSpec;
use EditFront\Plugin\Ops\PluginInsertOp;
use EditFront\Plugin\Ops\PluginMutateOp;
use EditFront\Support\Config;
use Psr\Log\LoggerInterface;

/**
 * Discovers plugins/<slug>/plugin.json on boot, validates each manifest, runs
 * the inverse fixtures round-trip GATE (§6.4.2 — a present invert() is not
 * enough, it must round-trip), and only then registers the kinds + ops into the
 * shared registries. A broken/unverified plugin degrades to read-only instead
 * of being registered, and a thrown exception is isolated so boot never dies
 * (§6.6). plugins.json caches the verdict by manifest+fixtures mtime.
 */
final class PluginManager
{
    public const CORE_API = '1';

    private bool $booted = false;
    /** @var list<OperationSpec> */
    private array $opSpecs = [];
    /** @var array<string, PluginManifest> enabled plugins */
    private array $manifests = [];
    /** @var array<string, array<string, mixed>> slug → status row (for UI + registry) */
    private array $statuses = [];
    /**
     * server class FQCN → the file it was loaded from. STATIC because PHP's
     * class table is process-global: a class declared by an earlier boot in the
     * same process is legitimately reusable, only a DIFFERENT file claiming the
     * same FQCN is a collision.
     * @var array<string, string>
     */
    private static array $loadedClasses = [];

    public function __construct(
        private readonly Config $config,
        private readonly ElementTypeRegistry $types,
        private readonly PropsMutator $mutator,
        private readonly KindRenderer $renderer,
        private readonly KindCtxFactory $ctxFactory,
        private readonly Annotator $annotator,
        private readonly Html5 $html5,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true; // set first: a plugin op factory must not re-enter

        $dir = $this->config->cmsDir() . '/plugins';
        if (!is_dir($dir)) {
            return;
        }
        $registry = $this->loadRegistry();
        // a plugin whose PHP fataled mid-`require` last boot left its slug+sig in
        // this map; we skip re-requiring that exact version so a parse-error fatal
        // can't 500 every request forever (it self-heals once the file changes).
        $quarantine = $this->readQuarantine();
        $seen = [];

        foreach (glob($dir . '/*/plugin.json') ?: [] as $manifestPath) {
            $pluginDir = dirname($manifestPath);
            $slug = basename($pluginDir);
            $seen[$slug] = true;
            try {
                $this->loadOne($manifestPath, $pluginDir, $slug, $registry, $quarantine);
            } catch (\Throwable $e) {
                // isolation: a single bad plugin never takes down the boot
                $row = [
                    'enabled' => $registry[$slug]['enabled'] ?? true,
                    'status' => 'error',
                    'reason' => $e->getMessage(),
                    'trust' => $registry[$slug]['trust'] ?? 'marketplace',
                    'approved_runtime' => (bool) ($registry[$slug]['approved_runtime'] ?? false),
                    'sig' => '',
                ];
                $registry[$slug] = $row;
                $this->statuses[$slug] = $row;
                $this->logger->warning('plugin.load_failed', ['slug' => $slug, 'error' => $e->getMessage()]);
            }
        }

        // drop rows for plugins whose folder no longer exists (don't re-persist dead slugs)
        $registry = array_intersect_key($registry, $seen);
        $this->saveRegistry($registry);
    }

    /**
     * @param array<string, array<string, mixed>> $registry mutated in place
     * @param array<string, string> $quarantine slug → sig of a plugin that crashed mid-load
     */
    private function loadOne(string $manifestPath, string $pluginDir, string $slug, array &$registry, array $quarantine): void
    {
        $raw = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($raw)) {
            throw new PluginException('invalid JSON in plugin.json');
        }
        $manifest = PluginManifest::fromArray($raw, $pluginDir, self::CORE_API);

        $prev = $registry[$slug] ?? null;
        $approvedRuntime = (bool) ($prev['approved_runtime'] ?? false);
        $installed = (string) ($prev['installed'] ?? date('Y-m-d'));

        // honor a manual disable switch (§6.6): keep the node static, do not register
        if (($prev['enabled'] ?? true) === false) {
            $this->record($slug, $registry, [
                'enabled' => false, 'status' => 'disabled', 'reason' => '',
                'trust' => $manifest->trust, 'approved_runtime' => $approvedRuntime,
                'installed' => $installed, 'sig' => $this->sig($manifest, $pluginDir),
            ]);
            return;
        }

        $sig = $this->sig($manifest, $pluginDir);

        // a version whose PHP fataled mid-require last boot stays read-only until
        // the file changes (sig differs) — prevents a persistent boot-DoS (§6.6)
        if (($quarantine[$slug] ?? null) === $sig) {
            $this->record($slug, $registry, [
                'enabled' => true, 'status' => 'degraded',
                'reason' => 'server code crashed during a previous load — read-only until the file changes',
                'trust' => $manifest->trust, 'approved_runtime' => $approvedRuntime,
                'installed' => $installed, 'sig' => $sig,
            ]);
            $this->logger->warning('plugin.quarantined', ['slug' => $slug]);
            return;
        }

        // mark "attempting require"; if loadServerClass fatals on a parse error
        // (uncatchable), this marker survives and quarantines the version next boot
        $this->markAttempt($slug, $sig);
        $blocks = $this->loadServerClass($manifest);
        $this->clearAttempt($slug);

        // gate verdict is cached by a content-hash signature (§6.6)
        $verified = ($prev !== null && ($prev['sig'] ?? null) === $sig && ($prev['status'] ?? '') === 'enabled');
        $reason = '';
        if (!$verified) {
            $gate = $this->runFixturesGate($manifest, $blocks, $pluginDir);
            $verified = $gate['ok'];
            $reason = $gate['reason'];
        }

        if (!$verified) {
            $this->record($slug, $registry, [
                'enabled' => true, 'status' => 'degraded', 'reason' => $reason,
                'trust' => $manifest->trust, 'approved_runtime' => $approvedRuntime,
                'installed' => $installed, 'sig' => $sig,
            ]);
            $this->logger->warning('plugin.degraded', ['slug' => $slug, 'reason' => $reason]);
            return;
        }

        // register kinds + ops into the shared registries
        foreach ($manifest->kinds as $mk) {
            $kindName = (string) $mk['kind'];
            $block = $blocks[$kindName] ?? null;
            if ($block === null) {
                continue; // declared kind not provided by the class → skip (degrades via orphan path)
            }
            $this->types->register(new RegisteredKind($slug, $manifest->trust, $mk, $block));
        }
        $this->buildOps($slug);
        $this->manifests[$slug] = $manifest;

        $this->record($slug, $registry, [
            'enabled' => true, 'status' => 'enabled', 'reason' => '',
            'trust' => $manifest->trust, 'approved_runtime' => $approvedRuntime,
            'installed' => $installed, 'sig' => $sig,
        ]);
    }

    /** @param array<string, array<string, mixed>> $registry */
    private function record(string $slug, array &$registry, array $row): void
    {
        $registry[$slug] = $row;
        $this->statuses[$slug] = $row;
    }

    /** @return array<string, BlockKind> kind name → instance */
    private function loadServerClass(PluginManifest $manifest): array
    {
        $php = $manifest->dir . '/' . $manifest->serverPhp;
        $real = realpath($php);
        $dirReal = realpath($manifest->dir);
        // path must stay inside the plugin dir (no symlink escape)
        if ($real === false || $dirReal === false || !str_starts_with($real, $dirReal . '/')) {
            throw new PluginException('server php outside plugin dir');
        }
        $class = $manifest->serverClass;
        // collision guard: if this FQCN is already declared by a DIFFERENT file,
        // a require_once would fatal with "cannot redeclare class" (uncatchable).
        // Degrade this plugin instead of crashing the whole boot.
        if (class_exists($class, false)) {
            if ((self::$loadedClasses[$class] ?? null) !== $real) {
                throw new PluginException('server class already declared elsewhere (collision): ' . $class);
            }
        } else {
            require_once $real;
            if (!class_exists($class)) {
                throw new PluginException('server class not found: ' . $class);
            }
            self::$loadedClasses[$class] = $real;
        }
        $instance = new $class();
        if (!$instance instanceof BlockKind) {
            throw new PluginException('server class is not a BlockKind: ' . $class);
        }
        return [$instance->kind() => $instance];
    }

    private function buildOps(string $slug): void
    {
        $this->opSpecs[] = new PluginInsertOp($slug, $this->types, $this->renderer, $this->annotator);
        foreach (PropsMutator::MODES as $mode) {
            $this->opSpecs[] = new PluginMutateOp(
                $slug,
                $mode,
                $this->types,
                $this->mutator,
                $this->renderer,
                $this->html5,
            );
        }
    }

    /**
     * §6.4.2: prove every op round-trips before allowing edit mode. Mutate ops
     * must satisfy invert(forward(S)) ≡ S; the kind must satisfy the render↔
     * serialize round-trip. Missing/failing fixtures → not verified.
     * @param array<string, BlockKind> $blocks
     * @return array{ok: bool, reason: string}
     */
    private function runFixturesGate(PluginManifest $manifest, array $blocks, string $pluginDir): array
    {
        $fixturesPath = $pluginDir . '/' . $manifest->fixtures;
        if (!is_file($fixturesPath)) {
            return ['ok' => false, 'reason' => 'fixtures file missing'];
        }
        $decoded = json_decode((string) file_get_contents($fixturesPath), true);
        $fixtures = is_array($decoded['ops'] ?? null) ? $decoded['ops'] : (is_array($decoded) ? $decoded : null);
        if (!is_array($fixtures)) {
            return ['ok' => false, 'reason' => 'fixtures malformed'];
        }

        // schema per declared kind
        $schemas = [];
        $needsArrayOps = false;
        foreach ($manifest->kinds as $mk) {
            $schema = new PropsSchema((array) ($mk['props_schema'] ?? []));
            $schemas[(string) $mk['kind']] = $schema;
            if ($this->schemaHasArray((array) ($mk['props_schema'] ?? []))) {
                $needsArrayOps = true;
            }
        }

        $covered = [];
        foreach ($fixtures as $i => $fx) {
            if (!is_array($fx)) {
                return ['ok' => false, 'reason' => "fixture #$i not an object"];
            }
            $op = (string) ($fx['op'] ?? '');
            $kindName = (string) ($fx['kind'] ?? array_key_first($schemas));
            $schema = $schemas[$kindName] ?? null;
            $block = $blocks[$kindName] ?? null;
            if ($schema === null || $block === null) {
                return ['ok' => false, 'reason' => "fixture #$i references unknown kind '$kindName'"];
            }
            $covered[$op] = true;

            try {
                if ($op === 'insert') {
                    $this->gateInsert($block, $schema, (array) ($fx['forward'] ?? []), $manifest, $kindName);
                } elseif (in_array($op, PropsMutator::MODES, true)) {
                    $this->gateMutate($op, $schema, $fx);
                } else {
                    return ['ok' => false, 'reason' => "fixture #$i unknown op '$op'"];
                }
            } catch (\Throwable $e) {
                return ['ok' => false, 'reason' => "fixture #$i ($op): " . $e->getMessage()];
            }
        }

        // required coverage (§6.4.2 "по одному примеру на КАЖДУЮ свою op")
        $required = ['insert', 'setprop'];
        if ($needsArrayOps) {
            $required = array_merge($required, ['arrayinsert', 'arrayremove', 'arraymove']);
        }
        foreach ($required as $op) {
            if (!isset($covered[$op])) {
                return ['ok' => false, 'reason' => "missing fixture for op '$op'"];
            }
        }

        return ['ok' => true, 'reason' => ''];
    }

    /** @param array<string, mixed> $forward */
    private function gateInsert(BlockKind $block, PropsSchema $schema, array $forward, PluginManifest $manifest, string $kindName): void
    {
        $props = $schema->defaults();
        if (is_array($forward['props'] ?? null)) {
            $props = array_replace($props, $forward['props']);
        }
        if ($schema->validate($props) !== []) {
            throw new PluginException('insert props invalid');
        }
        // a temp RegisteredKind only to build a render ctx
        $rk = new RegisteredKind($manifest->slug, $manifest->trust, ['kind' => $kindName, 'props_schema' => $schema->raw()], $block);
        $ctx = $this->ctxFactory->make($rk);

        $html1 = $block->render($props, '', $ctx);
        $node = $this->parseFirstElement('<div data-cms-block="' . $kindName . '">' . $html1 . '</div>');
        if ($node === null) {
            throw new PluginException('render produced no node');
        }
        $props2 = $block->serialize($node);
        if ($schema->validate($props2) !== []) {
            throw new PluginException('serialize(render(p)) failed validation');
        }
        // render must be idempotent through the round-trip (DOM is a faithful encoding)
        $html2 = $block->render($props2, '', $ctx);
        if ($this->normalizeHtml($html1) !== $this->normalizeHtml($html2)) {
            throw new PluginException('render↔serialize not stable');
        }
    }

    /** @param array<string, mixed> $fx */
    private function gateMutate(string $mode, PropsSchema $schema, array $fx): void
    {
        $before = (array) (($fx['state_before']['props'] ?? null) ?? []);
        if ($schema->validate($before) !== []) {
            throw new PluginException('state_before.props invalid');
        }
        $forward = (array) ($fx['forward'] ?? []);
        $after = $this->mutator->apply($mode, $before, $forward, $schema);
        if ($schema->validate($after) !== []) {
            throw new PluginException('props invalid after forward');
        }
        $inv = $this->mutator->invert($mode, $forward, $before, $schema);
        $back = $this->mutator->apply($inv['mode'], $after, $inv['forward'], $schema);
        if ($this->canon($back) !== $this->canon($before)) {
            throw new PluginException('inverse(forward(S)) != S');
        }
    }

    private function parseFirstElement(string $html): ?\DOMElement
    {
        $doc = $this->html5->parse('<!DOCTYPE html><html><head><title>t</title></head><body>' . $html . '</body></html>');
        $body = $doc->getElementsByTagName('body')->item(0);
        $node = $body?->firstElementChild ?? null;
        return $node instanceof \DOMElement ? $node : null;
    }

    private function normalizeHtml(string $html): string
    {
        return preg_replace('/\s+/', ' ', trim($html)) ?? $html;
    }

    public function runtimeAllowed(string $slug): bool
    {
        $row = $this->statuses[$slug] ?? null;
        if ($row === null) {
            return false;
        }
        $trust = (string) ($row['trust'] ?? 'marketplace');
        $rank = PluginManifest::TRUST_RANK[$trust] ?? 0;
        return $rank >= PluginManifest::TRUST_RANK['verified'] || (bool) ($row['approved_runtime'] ?? false);
    }

    /** @return list<OperationSpec> */
    public function operationSpecs(): array
    {
        $this->boot();
        return $this->opSpecs;
    }

    public function elementTypes(): ElementTypeRegistry
    {
        $this->boot();
        return $this->types;
    }

    /** @return array<string, array<string, mixed>> */
    public function statuses(): array
    {
        $this->boot();
        return $this->statuses;
    }

    /**
     * Flip a plugin's enable switch in the registry (admin UI). The change takes
     * effect on the NEXT request's boot(): disabling keeps the node static,
     * re-enabling forces a fresh fixtures-gate (status is reset so a previously
     * broken plugin can never be re-registered without re-verification).
     */
    public function setEnabled(string $slug, bool $enabled): void
    {
        if (preg_match('/^[a-z][a-z0-9-]{1,39}$/', $slug) !== 1) {
            throw new PluginException('bad slug');
        }
        if (!is_dir($this->config->cmsDir() . '/plugins/' . $slug)) {
            throw new PluginException('plugin not installed: ' . $slug);
        }
        $registry = $this->loadRegistry();
        $row = is_array($registry[$slug] ?? null) ? $registry[$slug] : [];
        $row['enabled'] = $enabled;
        // 'pending' (not 'enabled') forces boot to re-run the gate; never trust an
        // old 'enabled' verdict when re-enabling.
        $row['status'] = $enabled ? 'pending' : 'disabled';
        $row['reason'] = '';
        $registry[$slug] = $row;
        $this->saveRegistry($registry);
    }

    /**
     * All installed plugins (regardless of enable state) merged with their boot
     * status, for the admin page. Boots first so statuses are populated.
     * @return list<array{slug: string, name: array<string,string>, version: string, kinds: list<string>, status: string, enabled: bool, reason: string, trust: string}>
     */
    public function adminList(): array
    {
        $this->boot();
        $out = [];
        foreach (glob($this->config->cmsDir() . '/plugins/*/plugin.json') ?: [] as $manifestPath) {
            $slug = basename(dirname($manifestPath));
            $name = ['en' => $slug];
            $version = '';
            $kinds = [];
            $raw = json_decode((string) @file_get_contents($manifestPath), true);
            if (is_array($raw)) {
                foreach ((array) ($raw['name'] ?? []) as $loc => $label) {
                    if (is_string($loc) && is_string($label)) {
                        $name[$loc] = $label;
                    }
                }
                $version = (string) ($raw['version'] ?? '');
                foreach ((array) ($raw['kinds'] ?? []) as $k) {
                    if (is_array($k) && is_string($k['kind'] ?? null)) {
                        $kinds[] = $k['kind'];
                    }
                }
            }
            $st = $this->statuses[$slug] ?? [];
            $out[] = [
                'slug' => $slug,
                'name' => $name,
                'version' => $version,
                'kinds' => $kinds,
                'status' => (string) ($st['status'] ?? 'unknown'),
                'enabled' => (bool) ($st['enabled'] ?? true),
                'reason' => (string) ($st['reason'] ?? ''),
                'trust' => (string) ($st['trust'] ?? 'marketplace'),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['slug'], $b['slug']));
        return $out;
    }

    /**
     * Serializable plugin info for the editor/preview client: kinds, props
     * schema, labels, icons, trust, needs_runtime, editor asset URLs.
     *
     * Trust model (§6.7 — two axes). editor_js is BUILD-TIME code: it runs only
     * inside the admin's own editor iframe and is trusted exactly like the
     * plugin's server PHP (installing a plugin = trusting it, single-admin Free).
     * It is therefore emitted for every enabled plugin and NOT gated. The gated
     * axis is `runtime_js` — visitor-facing code on the live public page — which
     * runs only when runtimeAllowed() (trust ≥ verified OR approved_runtime); no
     * runtime_js is injected anywhere in C3 (the reference plugin has none).
     *
     * @param callable(string, string): string $assetUrl (slug, relPath) → URL
     * @return array<string, mixed>
     */
    public function clientManifest(callable $assetUrl): array
    {
        $this->boot();
        $plugins = [];
        foreach ($this->manifests as $slug => $m) {
            $kinds = [];
            foreach ($m->kinds as $mk) {
                if (!$this->types->has((string) $mk['kind'])) {
                    continue;
                }
                $kinds[] = [
                    'kind' => $mk['kind'],
                    'label' => $mk['label'],
                    'icon' => $mk['icon'] !== '' ? $assetUrl($slug, (string) $mk['icon']) : '',
                    'needs_runtime' => $mk['needs_runtime'],
                    'version' => $mk['version'],
                    'props_schema' => $mk['props_schema'],
                    'layout' => $mk['layout'] ?? '',
                ];
            }
            $plugins[$slug] = [
                'slug' => $slug,
                'name' => $m->names,
                'trust' => $m->trust,
                'runtime_allowed' => $this->runtimeAllowed($slug),
                'editor_js' => isset($m->assets['editor_js']) ? $assetUrl($slug, $m->assets['editor_js']) : null,
                'editor_css' => isset($m->assets['editor_css']) ? $assetUrl($slug, $m->assets['editor_css']) : null,
                'kinds' => $kinds,
                'contributions' => $m->contributions,
            ];
        }
        return $plugins;
    }

    private function sig(PluginManifest $manifest, string $dir): string
    {
        // content hash, not mtime: a restore / cp -p / touch can change the bytes
        // while preserving mtime, which would let a now-broken plugin skip the gate.
        $parts = [
            (string) @hash_file('sha256', $dir . '/plugin.json'),
            (string) @hash_file('sha256', $dir . '/' . $manifest->fixtures),
            (string) @hash_file('sha256', $dir . '/' . $manifest->serverPhp),
        ];
        return substr(hash('sha256', implode('|', $parts) . '|' . self::CORE_API), 0, 24);
    }

    /* --- crash quarantine (self-healing isolation for require fatals) -------- */

    /** @return array<string, string> slug → sig of a plugin that crashed mid-require */
    private function readQuarantine(): array
    {
        $file = $this->quarantineFile();
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($file), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function markAttempt(string $slug, string $sig): void
    {
        $q = $this->readQuarantine();
        $q[$slug] = $sig;
        $this->writeQuarantine($q);
    }

    private function clearAttempt(string $slug): void
    {
        $q = $this->readQuarantine();
        if (array_key_exists($slug, $q)) {
            unset($q[$slug]);
            $this->writeQuarantine($q);
        }
    }

    /** @param array<string, string> $q */
    private function writeQuarantine(array $q): void
    {
        $file = $this->quarantineFile();
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
            return;
        }
        // best-effort but FLUSHED before the require that may fatal
        $payload = (string) json_encode($q, JSON_UNESCAPED_SLASHES);
        $fh = @fopen($file, 'cb');
        if ($fh === false) {
            return;
        }
        try {
            flock($fh, LOCK_EX);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $payload);
            fflush($fh);
            fsync($fh);
        } finally {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
        @chmod($file, 0640);
    }

    private function quarantineFile(): string
    {
        return $this->config->storageDir() . '/plugins-loading.json';
    }

    /** @return array<string, array<string, mixed>> */
    private function loadRegistry(): array
    {
        $file = $this->registryFile();
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($file), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, array<string, mixed>> $registry */
    private function saveRegistry(array $registry): void
    {
        $file = $this->registryFile();
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
            return; // best-effort: a missing registry just means a re-scan next boot
        }
        $payload = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return;
        }
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            return;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return;
        }
        @chmod($file, 0640);
    }

    private function registryFile(): string
    {
        return $this->config->storageDir() . '/plugins.json';
    }

    private function schemaHasArray(array $schema): bool
    {
        foreach ($schema as $field) {
            if (!is_array($field)) {
                continue;
            }
            if (($field['type'] ?? '') === 'array') {
                return true;
            }
            if (($field['type'] ?? '') === 'object' && $this->schemaHasArray((array) ($field['shape'] ?? []))) {
                return true;
            }
        }
        return false;
    }

    private function canon(mixed $v): string
    {
        return (string) json_encode($this->canonValue($v), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function canonValue(mixed $v): mixed
    {
        if (!is_array($v)) {
            return $v;
        }
        if ($v === array_values($v)) {
            return array_map(fn ($x) => $this->canonValue($x), $v); // list: keep order
        }
        ksort($v);
        $out = [];
        foreach ($v as $k => $val) {
            $out[$k] = $this->canonValue($val);
        }
        return $out;
    }
}
