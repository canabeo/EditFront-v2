<?php

declare(strict_types=1);

namespace EditFront\Tests\Plugin;

use EditFront\Plugin\PluginException;
use EditFront\Support\Config;
use PHPUnit\Framework\TestCase;

final class PluginManagerAdminTest extends TestCase
{
    private function config(): Config
    {
        $cms = ef2_plugin_cms([ef2_pricing_plugin_dir()]);
        return ef2_test_config([
            'cms_dir' => $cms,
            'storage_dir' => ef2_temp_dir('pm-admin-storage'),
        ]);
    }

    public function test_admin_list_reports_installed_plugin(): void
    {
        $config = $this->config();
        $list = ef2_plugin_manager($config)->adminList();
        $this->assertCount(1, $list);
        $this->assertSame('pricing-table', $list[0]['slug']);
        $this->assertSame('enabled', $list[0]['status']);
        $this->assertTrue($list[0]['enabled']);
        $this->assertContains('pricing-table', $list[0]['kinds']);
        $this->assertArrayHasKey('en', $list[0]['name']);
        $this->assertNotSame('', $list[0]['version']);
    }

    public function test_disable_then_relist_shows_disabled(): void
    {
        $config = $this->config();
        ef2_plugin_manager($config)->setEnabled('pricing-table', false);

        // a FRESH manager boots from the persisted registry
        $list = ef2_plugin_manager($config)->adminList();
        $this->assertSame('disabled', $list[0]['status']);
        $this->assertFalse($list[0]['enabled']);
    }

    public function test_reenable_reverifies_and_enables(): void
    {
        $config = $this->config();
        $mgr = ef2_plugin_manager($config);
        $mgr->setEnabled('pricing-table', false);
        ef2_plugin_manager($config)->adminList(); // boot once disabled
        ef2_plugin_manager($config)->setEnabled('pricing-table', true);

        $list = ef2_plugin_manager($config)->adminList();
        // re-enable forces a fresh gate; the valid reference plugin passes
        $this->assertSame('enabled', $list[0]['status']);
        $this->assertTrue($list[0]['enabled']);
    }

    public function test_set_enabled_bad_slug_throws(): void
    {
        $this->expectException(PluginException::class);
        ef2_plugin_manager($this->config())->setEnabled('../evil', false);
    }

    public function test_set_enabled_unknown_plugin_throws(): void
    {
        $this->expectException(PluginException::class);
        ef2_plugin_manager($this->config())->setEnabled('not-installed', false);
    }
}
