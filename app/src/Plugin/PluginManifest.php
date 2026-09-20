<?php

declare(strict_types=1);

namespace EditFront\Plugin;

/**
 * Parsed + validated plugins/<slug>/plugin.json (§6.2) — the single declarative
 * source of registration. No manual DI: the manifest is data, PluginManager
 * turns it into RegisteredKinds and ops. A malformed manifest throws and the
 * plugin is skipped (boot never dies).
 */
final class PluginManifest
{
    public const AUTHORS = ['core', 'verified', 'local', 'marketplace'];
    public const TRUST_RANK = ['core' => 3, 'verified' => 2, 'local' => 1, 'marketplace' => 0];
    private const SLUG_RE = '/^[a-z][a-z0-9-]{1,39}$/';
    private const KIND_RE = '/^[a-z][a-z0-9-]{1,39}$/';
    private const ACTION_RE = '/^[a-z][a-z0-9-]{0,39}$/';

    /**
     * @param array<string, string> $names locale → label
     * @param array<string, string> $assets editor_js/editor_css/runtime_js/runtime_css
     * @param list<array<string, mixed>> $kinds
     * @param array{class: string, php: string, actions: list<string>, public: list<string>, admin: array<string, mixed>} $module
     * @param array<string, mixed> $contributions
     * @param array<string, string> $i18n locale → relative path
     */
    private function __construct(
        public readonly string $slug,
        public readonly array $names,
        public readonly string $version,
        public readonly string $api,
        public readonly string $author,
        public readonly string $trust,
        public readonly array $assets,
        public readonly string $serverPhp,
        public readonly string $serverClass,
        public readonly string $fixtures,
        public readonly array $kinds,
        public readonly array $module,
        public readonly array $contributions,
        public readonly array $i18n,
        public readonly string $dir,
    ) {
    }

    /**
     * @param array<string, mixed> $raw decoded plugin.json
     * @throws PluginException
     */
    public static function fromArray(array $raw, string $dir, string $coreApi): self
    {
        $slug = (string) ($raw['slug'] ?? '');
        if (preg_match(self::SLUG_RE, $slug) !== 1) {
            throw new PluginException('bad slug');
        }
        if (basename($dir) !== $slug) {
            throw new PluginException("slug '$slug' must equal folder name '" . basename($dir) . "'");
        }

        $version = (string) ($raw['version'] ?? '');
        if (preg_match('/^\d+(\.\d+){0,2}([-+].*)?$/', $version) !== 1) {
            throw new PluginException('bad version');
        }
        $api = (string) ($raw['api'] ?? '');
        if ($api === '') {
            throw new PluginException('missing api');
        }
        // incompatible api → caller degrades (§6.6); we still reject hard here so
        // the plugin is not registered as editable
        if ((int) $api !== (int) $coreApi) {
            throw new PluginException("plugin api '$api' incompatible with core api '$coreApi'");
        }

        $author = (string) ($raw['author'] ?? 'marketplace');
        if (!in_array($author, self::AUTHORS, true)) {
            throw new PluginException('bad author');
        }
        $trust = (string) ($raw['trust'] ?? $author);
        if (!isset(self::TRUST_RANK[$trust])) {
            throw new PluginException('bad trust');
        }
        // the core may only LOWER trust, never raise above the author class
        if (self::TRUST_RANK[$trust] > self::TRUST_RANK[$author]) {
            $trust = $author;
        }

        $names = [];
        foreach ((array) ($raw['name'] ?? []) as $loc => $label) {
            if (is_string($loc) && is_string($label)) {
                $names[$loc] = $label;
            }
        }
        if ($names === []) {
            $names['en'] = $slug;
        }

        $assets = [];
        foreach (['editor_js', 'editor_css', 'runtime_js', 'runtime_css'] as $k) {
            $v = ($raw['assets'][$k] ?? null);
            if (is_string($v) && $v !== '') {
                self::assertSafeRel($v);
                $assets[$k] = $v;
            }
        }

        // `server` provides the BlockKind class. A module-only plugin contributes
        // no kind, so it declares `module` instead and leaves this out entirely.
        $server = (array) ($raw['server'] ?? []);
        $serverPhp = (string) ($server['php'] ?? '');
        $serverClass = (string) ($server['class'] ?? '');
        if ($server !== [] && ($serverPhp === '' || $serverClass === '')) {
            throw new PluginException('missing server.php / server.class');
        }
        if ($serverPhp !== '') {
            self::assertSafeRel($serverPhp);
        }
        if ($serverClass !== '' && preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]{0,200}$/', $serverClass) !== 1) {
            throw new PluginException('bad server.class');
        }

        $module = self::parseModule($raw['module'] ?? null);
        $hasKinds = ($raw['kinds'] ?? []) !== [];

        // Fixtures prove a plugin-op's inverse round-trips (§6.4.2). A module-only
        // plugin emits no ops and edits no document, so there is nothing to
        // round-trip and demanding a fixtures file would be cargo cult.
        $fixtures = (string) ($raw['fixtures'] ?? '');
        if ($fixtures === '' && $hasKinds) {
            throw new PluginException('missing fixtures (required for inverse round-trip, §6.4.2)');
        }
        if ($fixtures !== '') {
            self::assertSafeRel($fixtures);
        }

        $kinds = [];
        $kindNames = [];
        foreach ((array) ($raw['kinds'] ?? []) as $k) {
            if (!is_array($k)) {
                throw new PluginException('bad kind entry');
            }
            $kindName = (string) ($k['kind'] ?? '');
            if (preg_match(self::KIND_RE, $kindName) !== 1) {
                throw new PluginException('bad kind name');
            }
            if (isset($kindNames[$kindName])) {
                throw new PluginException('duplicate kind: ' . $kindName);
            }
            $kindNames[$kindName] = true;
            $schema = $k['props_schema'] ?? [];
            if (!is_array($schema)) {
                throw new PluginException('props_schema must be an object');
            }
            // icon is a plugin-relative path → same guard as every other file field
            $icon = '';
            if (is_string($k['icon'] ?? null) && $k['icon'] !== '') {
                self::assertSafeRel($k['icon']);
                $icon = $k['icon'];
            }
            $kinds[] = [
                'kind' => $kindName,
                'label' => (string) ($k['label'] ?? $kindName),
                'icon' => $icon,
                'needs_runtime' => (bool) ($k['needs_runtime'] ?? false),
                'version' => max(1, (int) ($k['version'] ?? 1)),
                'props_schema' => $schema,
                // optional layout hint: "contents" → the core wraps the block in a
                // transparent (display:contents) <div> so adopting a themed
                // structural element doesn't disturb the page layout (§6 adopt).
                'layout' => in_array(($k['layout'] ?? ''), ['contents'], true) ? 'contents' : '',
            ];
        }
        if ($kinds === [] && $module === []) {
            throw new PluginException('plugin declares neither kinds nor a module');
        }

        $i18n = [];
        foreach ((array) ($raw['i18n'] ?? []) as $loc => $rel) {
            if (is_string($loc) && is_string($rel) && $rel !== '') {
                self::assertSafeRel($rel);
                $i18n[$loc] = $rel;
            }
        }

        return new self(
            $slug,
            $names,
            $version,
            $api,
            $author,
            $trust,
            $assets,
            $serverPhp,
            $serverClass,
            $fixtures,
            $kinds,
            $module,
            is_array($raw['contributions'] ?? null) ? $raw['contributions'] : [],
            $i18n,
            $dir,
        );
    }

    /**
     * Parse `module` (§6.9). Shape:
     *
     *   "module": {
     *     "php":     "src/Leads.php",
     *     "class":   "EditFront\\Plugins\\Leads\\LeadsModule",
     *     "actions": ["submit", "code", "list", "config"],
     *     "public":  ["submit", "code"],
     *     "admin":   { "label_key": "leads.title", "order": 40 }
     *   }
     *
     * `actions` is a whitelist: an action not listed here has no route at all,
     * so a typo in a URL cannot reach a method by accident. `public` must be a
     * subset — an action reachable with no session and no CSRF token. Listing
     * an action as public is the plugin author's explicit decision and it is
     * confined to this plugin's own /api/p/<slug>/ namespace; nothing here can
     * open a core path.
     *
     * @return array{class: string, php: string, actions: list<string>, public: list<string>, admin: array<string, mixed>}
     */
    private static function parseModule(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            throw new PluginException('module must be an object');
        }

        $php = (string) ($raw['php'] ?? '');
        $class = (string) ($raw['class'] ?? '');
        if ($php === '' || $class === '') {
            throw new PluginException('missing module.php / module.class');
        }
        self::assertSafeRel($php);
        if (preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]{0,200}$/', $class) !== 1) {
            throw new PluginException('bad module.class');
        }

        $actions = [];
        foreach ((array) ($raw['actions'] ?? []) as $a) {
            if (!is_string($a) || preg_match(self::ACTION_RE, $a) !== 1) {
                throw new PluginException('bad module action: ' . (is_string($a) ? $a : gettype($a)));
            }
            $actions[$a] = true;
        }
        if ($actions === []) {
            throw new PluginException('module declares no actions');
        }

        $public = [];
        foreach ((array) ($raw['public'] ?? []) as $a) {
            if (!is_string($a) || !isset($actions[$a])) {
                throw new PluginException('module.public lists an action that is not declared: '
                    . (is_string($a) ? $a : gettype($a)));
            }
            $public[$a] = true;
        }

        $admin = [];
        if (is_array($raw['admin'] ?? null)) {
            $labelKey = (string) ($raw['admin']['label_key'] ?? '');
            if ($labelKey !== '' && preg_match('/^[a-z0-9][a-z0-9._-]*$/', $labelKey) !== 1) {
                throw new PluginException('bad module.admin.label_key');
            }
            $admin = [
                'label_key' => $labelKey,
                'order' => (int) ($raw['admin']['order'] ?? 100),
            ];
        }

        return [
            'php' => $php,
            'class' => $class,
            'actions' => array_keys($actions),
            'public' => array_keys($public),
            'admin' => $admin,
        ];
    }

    /** @throws PluginException reject traversal / absolute / null-byte paths */
    private static function assertSafeRel(string $rel): void
    {
        if (
            $rel === '' || str_contains($rel, "\0") || str_contains($rel, '..')
            || str_starts_with($rel, '/') || preg_match('#^[A-Za-z]:#', $rel) === 1
        ) {
            throw new PluginException('unsafe plugin path: ' . $rel);
        }
    }
}
