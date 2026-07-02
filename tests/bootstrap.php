<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Create an isolated temp dir tree for a test and return its path.
 * Каждый тест работает в своём корне — никаких следов в реальном siteRoot.
 */
function ef2_temp_dir(string $prefix = 'ef2test'): string
{
    $dir = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);
    register_shutdown_function(static function () use ($dir): void {
        ef2_rmdir_recursive($dir);
    });
    return $dir;
}

function ef2_rmdir_recursive(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        // do not descend through symlinked plugin dirs when cleaning up
        if ($item->isLink()) {
            @unlink($item->getPathname());
            continue;
        }
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

/** Build a Config with sane test defaults, overridable per-test. */
function ef2_test_config(array $overrides = []): \EditFront\Support\Config
{
    $defaults = [
        'base_path' => '/cms',
        'site_root' => sys_get_temp_dir(),
        'cms_dir' => dirname(__DIR__),
        'storage_dir' => sys_get_temp_dir(),
        'debug' => true,
        'session_name' => 'cms_sid',
        'admin_file' => sys_get_temp_dir() . '/admin.json',
        'pages_index_ttl' => 60,
    ];
    return new \EditFront\Support\Config(array_merge($defaults, $overrides));
}

function ef2_sanitizer_html(): \EditFront\Security\SanitizerHtml
{
    return new \EditFront\Security\SanitizerHtml(
        new \EditFront\Security\SanitizerUrl(),
        new \EditFront\Security\SanitizerCss()
    );
}

/** Full OperationRegistry with all core ops, dependencies built by hand. */
function ef2_registry(): \EditFront\Operation\OperationRegistry
{
    $html5 = new \EditFront\Document\Html5();
    $annotator = new \EditFront\Document\Annotator();
    $urls = new \EditFront\Security\SanitizerUrl();
    $sanitizer = ef2_sanitizer_html();

    $registry = new \EditFront\Operation\OperationRegistry();
    $registry->register(new \EditFront\Operation\Ops\TextSetOp($sanitizer, $html5));
    $registry->register(new \EditFront\Operation\Ops\AttrSetOp($urls));
    $registry->register(new \EditFront\Operation\Ops\AttrRemoveOp());
    $registry->register(new \EditFront\Operation\Ops\StyleSetOp(new \EditFront\Security\SanitizerCss()));
    $registry->register(new \EditFront\Operation\Ops\StyleStateOp(new \EditFront\Security\SanitizerCss()));
    $registry->register(new \EditFront\Operation\Ops\NodeInsertOp(
        new \EditFront\Operation\NodeTemplates(),
        $annotator,
        $html5
    ));
    $registry->register(new \EditFront\Operation\Ops\NodeDeleteOp());
    $registry->register(new \EditFront\Operation\Ops\NodeMoveOp($annotator));
    $registry->register(new \EditFront\Operation\Ops\NodeDuplicateOp($annotator));
    $registry->register(new \EditFront\Operation\Ops\NodeRestoreOp($annotator, $html5, $sanitizer));
    $registry->register(new \EditFront\Operation\Ops\NodeReplaceOp($annotator, $html5, $sanitizer));
    return $registry;
}

/** Parse a body-html snippet into a full document. */
function ef2_doc(string $bodyHtml): \DOMDocument
{
    $html5 = new \EditFront\Document\Html5();
    return $html5->parse("<!DOCTYPE html><html><head><title>t</title></head><body>{$bodyHtml}</body></html>");
}

/* --- i18n helpers (C4) --------------------------------------------------- */

function ef2_translator(array $configOverrides = []): \EditFront\I18n\Translator
{
    return new \EditFront\I18n\Translator(ef2_test_config($configOverrides));
}

/** Build a Slim PSR-7 server request for resolver tests. */
function ef2_request(string $target = '/cms/login', array $query = [], array $cookies = [], string $acceptLanguage = ''): \Psr\Http\Message\ServerRequestInterface
{
    $req = (new \Slim\Psr7\Factory\ServerRequestFactory())
        ->createServerRequest('GET', 'https://example.test' . $target)
        ->withQueryParams($query)
        ->withCookieParams($cookies);
    if ($acceptLanguage !== '') {
        $req = $req->withHeader('Accept-Language', $acceptLanguage);
    }
    return $req;
}

/* --- plugin helpers (C3) ------------------------------------------------- */

function ef2_kind_renderer(\EditFront\Support\Config $config): \EditFront\Plugin\KindRenderer
{
    return new \EditFront\Plugin\KindRenderer(
        ef2_sanitizer_html(),
        new \EditFront\Document\Html5(),
        new \EditFront\Plugin\KindCtxFactory($config)
    );
}

function ef2_props_store(\EditFront\Support\Config $config): \EditFront\Plugin\PropsStore
{
    return new \EditFront\Plugin\PropsStore($config);
}

function ef2_seo_service(\EditFront\Support\Config $config): \EditFront\Seo\SeoService
{
    $guard = new \EditFront\Storage\PathGuard();
    return new \EditFront\Seo\SeoService(
        new \EditFront\Storage\FileStorage($config, $guard),
        new \EditFront\Document\Html5(),
        new \EditFront\Storage\BackupService($config),
        new \EditFront\Security\SanitizerUrl(),
        $guard
    );
}

function ef2_plugin_manager(\EditFront\Support\Config $config): \EditFront\Plugin\PluginManager
{
    return new \EditFront\Plugin\PluginManager(
        $config,
        new \EditFront\Plugin\ElementTypeRegistry(),
        new \EditFront\Plugin\PropsMutator(),
        ef2_kind_renderer($config),
        new \EditFront\Plugin\KindCtxFactory($config),
        new \EditFront\Document\Annotator(),
        new \EditFront\Document\Html5(),
        new \Psr\Log\NullLogger()
    );
}

/**
 * Build a temp cmsDir whose plugins/ holds SYMLINKS to real plugin dirs, so
 * require_once dedups by canonical path across tests (copying the PHP would
 * trigger "cannot redeclare class"). Returns the temp cmsDir.
 *
 * @param list<string> $sourceDirs absolute plugin dirs to expose
 */
function ef2_plugin_cms(array $sourceDirs): string
{
    $cms = ef2_temp_dir('plugin-cms');
    @mkdir($cms . '/plugins', 0777, true);
    foreach ($sourceDirs as $src) {
        $real = realpath($src);
        if ($real !== false) {
            @symlink($real, $cms . '/plugins/' . basename($real));
        }
    }
    return $cms;
}

/** Absolute path to the bundled reference plugin. */
function ef2_pricing_plugin_dir(): string
{
    return dirname(__DIR__) . '/plugins/pricing-table';
}

/** Absolute path to the deliberately-broken test plugin. */
function ef2_broken_plugin_dir(): string
{
    return __DIR__ . '/fixtures/plugins/broken-invert';
}
