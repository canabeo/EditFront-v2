<?php

declare(strict_types=1);

namespace EditFront\Tests\Install;

use EditFront\Install\EnvFile;
use EditFront\Install\SelfCheck;
use EditFront\Support\Config;
use PHPUnit\Framework\TestCase;

final class SelfCheckTest extends TestCase
{
    private function check(array $overrides): SelfCheck
    {
        $config = ef2_test_config($overrides);
        return new SelfCheck($config, new EnvFile($config));
    }

    public function test_healthy_environment_can_proceed(): void
    {
        $sc = $this->check([
            'site_root' => ef2_temp_dir('sc-site'),
            'storage_dir' => ef2_temp_dir('sc-storage'),
            'cms_dir' => ef2_temp_dir('sc-cms'),
        ]);
        $this->assertTrue($sc->canProceed());

        $checks = $sc->run();
        $keys = array_column($checks, 'key');
        foreach (['php_version', 'ext_dom', 'ext_mbstring', 'ext_json', 'ext_fileinfo', 'storage_writable', 'site_root', 'mod_rewrite', 'base_path'] as $k) {
            $this->assertContains($k, $keys);
        }
    }

    public function test_unwritable_storage_blocks(): void
    {
        $sc = $this->check([
            'site_root' => ef2_temp_dir('sc-site'),
            'storage_dir' => '/nonexistent/' . bin2hex(random_bytes(4)),
            'cms_dir' => ef2_temp_dir('sc-cms'),
        ]);
        $this->assertFalse($sc->canProceed());

        $byKey = [];
        foreach ($sc->run() as $c) {
            $byKey[$c['key']] = $c;
        }
        $this->assertSame('fail', $byKey['storage_writable']['status']);
        $this->assertTrue($byKey['storage_writable']['blocking']);
    }

    public function test_php_version_probe_passes_on_runtime(): void
    {
        $sc = $this->check([
            'site_root' => ef2_temp_dir('sc-site'),
            'storage_dir' => ef2_temp_dir('sc-storage'),
            'cms_dir' => ef2_temp_dir('sc-cms'),
        ]);
        $byKey = [];
        foreach ($sc->run() as $c) {
            $byKey[$c['key']] = $c;
        }
        $this->assertSame('ok', $byKey['php_version']['status']);
    }
}
