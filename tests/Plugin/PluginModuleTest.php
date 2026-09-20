<?php

declare(strict_types=1);

namespace EditFront\Tests\Plugin;

use EditFront\Plugin\ModuleCtx;
use EditFront\Plugin\PluginException;
use EditFront\Plugin\PluginManifest;
use EditFront\Plugin\PluginModule;
use PHPUnit\Framework\TestCase;

/**
 * §6.9 — the second thing a plugin can be. A BlockKind adds content to a page;
 * a module adds a piece of the site: its own endpoints, its own admin screen,
 * its own storage. That is where per-client work belongs, so that the core
 * stays the same everywhere it is installed.
 *
 * What these tests pin down is the security shape, because a module runs
 * trusted PHP behind public URLs: an action nobody declared has no route, an
 * action nobody marked public needs a login, and nothing a manifest says can
 * reach outside the plugin's own namespace.
 */
final class PluginModuleTest extends TestCase
{
    /** @param array<string,mixed> $overrides */
    private function manifest(array $overrides = [], string $dir = 'demo-module'): PluginManifest
    {
        $raw = array_replace([
            'slug' => $dir,
            'name' => ['en' => 'Demo module'],
            'version' => '1.0.0',
            'api' => '1',
            'author' => 'local',
            'trust' => 'local',
            'module' => [
                'php' => 'src/DemoModule.php',
                'class' => 'EditFront\\Tests\\Fixtures\\DemoModule',
                'actions' => ['submit', 'list'],
                'public' => ['submit'],
                'admin' => ['label_key' => 'demo.title', 'order' => 40],
            ],
        ], $overrides);

        return PluginManifest::fromArray($raw, '/tmp/' . $dir, '1');
    }

    /* --- manifest ------------------------------------------------------- */

    public function test_a_module_only_plugin_needs_no_kinds_fixtures_or_server(): void
    {
        $m = $this->manifest();

        $this->assertSame([], $m->kinds);
        $this->assertSame('', $m->fixtures);
        $this->assertSame('', $m->serverClass);
        $this->assertSame(['submit', 'list'], $m->module['actions']);
        $this->assertSame(['submit'], $m->module['public']);
        $this->assertSame(40, $m->module['admin']['order']);
    }

    public function test_a_plugin_that_contributes_nothing_is_rejected(): void
    {
        $this->expectException(PluginException::class);
        $this->manifest(['module' => null]);
    }

    public function test_public_must_be_a_subset_of_declared_actions(): void
    {
        $this->expectException(PluginException::class);
        $this->expectExceptionMessageMatches('/not declared/');
        $this->manifest(['module' => [
            'php' => 'src/M.php',
            'class' => 'A\\B',
            'actions' => ['submit'],
            'public' => ['submit', 'drop-everything'],
        ]]);
    }

    public function test_a_module_without_actions_is_rejected(): void
    {
        $this->expectException(PluginException::class);
        $this->manifest(['module' => ['php' => 'src/M.php', 'class' => 'A\\B', 'actions' => []]]);
    }

    public function test_an_action_name_that_could_escape_the_url_is_rejected(): void
    {
        foreach (['../../save', 'Submit', 'sub mit', ''] as $bad) {
            try {
                $this->manifest(['module' => [
                    'php' => 'src/M.php', 'class' => 'A\\B', 'actions' => [$bad],
                ]]);
                $this->fail('accepted a bad action name: ' . var_export($bad, true));
            } catch (PluginException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_module_php_path_cannot_leave_the_plugin_folder(): void
    {
        $this->expectException(PluginException::class);
        $this->manifest(['module' => [
            'php' => '../../../app/bootstrap.php',
            'class' => 'A\\B',
            'actions' => ['x'],
        ]]);
    }

    public function test_a_plugin_can_still_be_kinds_only(): void
    {
        $raw = json_decode((string) file_get_contents(ef2_pricing_plugin_dir() . '/plugin.json'), true);
        $m = PluginManifest::fromArray($raw, ef2_pricing_plugin_dir(), '1');

        $this->assertSame([], $m->module);
        $this->assertNotSame([], $m->kinds);
    }

    /* --- manager -------------------------------------------------------- */

    private function manager(array $dirs = null): \EditFront\Plugin\PluginManager
    {
        $cms = ef2_plugin_cms($dirs ?? [ef2_module_plugin_dir()]);
        $config = ef2_test_config(['cms_dir' => $cms, 'storage_dir' => ef2_temp_dir('pmod-storage')]);
        return ef2_plugin_manager($config);
    }

    public function test_a_module_plugin_boots_and_registers(): void
    {
        $pm = $this->manager();
        $pm->boot();

        $this->assertSame('enabled', $pm->statuses()['demo-module']['status']);
        $this->assertInstanceOf(PluginModule::class, $pm->module('demo-module'));
    }

    public function test_only_declared_actions_exist_and_only_listed_ones_are_public(): void
    {
        $pm = $this->manager();

        $this->assertTrue($pm->moduleHasAction('demo-module', 'submit'));
        $this->assertTrue($pm->moduleHasAction('demo-module', 'list'));
        $this->assertFalse($pm->moduleHasAction('demo-module', 'wipe'));

        $this->assertTrue($pm->moduleActionIsPublic('demo-module', 'submit'));
        $this->assertFalse($pm->moduleActionIsPublic('demo-module', 'list'));
        $this->assertFalse($pm->moduleActionIsPublic('demo-module', 'wipe'));
    }

    public function test_an_unknown_plugin_has_no_module_and_no_public_actions(): void
    {
        $pm = $this->manager();

        $this->assertNull($pm->module('nosuch'));
        $this->assertFalse($pm->moduleHasAction('nosuch', 'submit'));
        $this->assertFalse($pm->moduleActionIsPublic('nosuch', 'submit'));
    }

    public function test_a_kinds_only_plugin_contributes_no_module(): void
    {
        $pm = $this->manager([ef2_pricing_plugin_dir()]);
        $pm->boot();

        $this->assertSame('enabled', $pm->statuses()['pricing-table']['status']);
        $this->assertNull($pm->module('pricing-table'));
        $this->assertSame([], $pm->moduleAdminPages());
    }

    public function test_a_disabled_plugin_exposes_no_routes(): void
    {
        $cms = ef2_plugin_cms([ef2_module_plugin_dir()]);
        $storage = ef2_temp_dir('pmod-disabled');
        $config = ef2_test_config(['cms_dir' => $cms, 'storage_dir' => $storage]);

        $pm = ef2_plugin_manager($config);
        $pm->boot();
        $pm->setEnabled('demo-module', false);

        $fresh = ef2_plugin_manager($config);
        $fresh->boot();

        $this->assertNull($fresh->module('demo-module'));
        $this->assertFalse($fresh->moduleActionIsPublic('demo-module', 'submit'), 'a disabled plugin must not keep a public URL');
        $this->assertSame([], $fresh->moduleAdminPages());
    }

    public function test_admin_pages_are_listed_in_declared_order(): void
    {
        $pm = $this->manager();
        $pages = $pm->moduleAdminPages();

        $this->assertCount(1, $pages);
        $this->assertSame('demo-module', $pages[0]['slug']);
        $this->assertSame('demo.title', $pages[0]['label_key']);
    }

    /* --- context -------------------------------------------------------- */

    public function test_storage_is_private_to_the_plugin_and_outside_the_site(): void
    {
        $storage = ef2_temp_dir('ctx-storage');
        $site = ef2_temp_dir('ctx-site');
        $config = ef2_test_config(['storage_dir' => $storage, 'site_root' => $site]);

        $ctx = new ModuleCtx(
            'demo-module',
            '/tmp/demo-module',
            $config,
            new \Twig\Environment(new \Twig\Loader\FilesystemLoader(dirname(__DIR__, 2) . '/app/templates')),
            ef2_translator(['storage_dir' => $storage, 'site_root' => $site]),
            new \EditFront\Http\UrlHelper($config),
            new \EditFront\Security\RateLimiter($config),
            new \Psr\Log\NullLogger(),
        );

        $dir = $ctx->storageDir();

        $this->assertSame($storage . '/plugins/demo-module', $dir);
        $this->assertDirectoryExists($dir);
        $this->assertStringStartsNotWith($site, $dir, 'plugin data must never be web-served');
    }
}
