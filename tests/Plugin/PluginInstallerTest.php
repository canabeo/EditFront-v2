<?php

declare(strict_types=1);

namespace EditFront\Tests\Plugin;

use EditFront\Plugin\PluginInstaller;
use EditFront\Plugin\PluginInstallException;
use EditFront\Support\Config;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PluginInstallerTest extends TestCase
{
    private string $cms;
    private PluginInstaller $installer;

    protected function setUp(): void
    {
        $this->cms = ef2_temp_dir('inst-cms');
        @mkdir($this->cms . '/plugins', 0777, true);
        $config = ef2_test_config([
            'cms_dir' => $this->cms,
            'storage_dir' => ef2_temp_dir('inst-storage'),
        ]);
        $this->installer = new PluginInstaller($config, new NullLogger());
    }

    /** @param array<string, string> $entries name → content */
    private function makeZip(array $entries): string
    {
        $path = ef2_temp_dir('inst-zip') . '/p.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        return $path;
    }

    /** A minimal but structurally-valid plugin under top folder $slug. */
    private function validPlugin(string $slug = 'demo-plugin'): array
    {
        $manifest = json_encode([
            'slug' => $slug,
            'name' => ['en' => 'Demo', 'ru' => 'Демо'],
            'version' => '1.0.0',
            'api' => '1',
            'author' => 'local',
            'trust' => 'local',
            'server' => ['php' => 'src/Demo.php', 'class' => 'Demo\\Block'],
            'fixtures' => 'fixtures/ops.json',
            'kinds' => [['kind' => 'demo', 'label' => 'Demo', 'props_schema' => []]],
        ]);
        return [
            "$slug/plugin.json" => (string) $manifest,
            "$slug/src/Demo.php" => "<?php // demo\n",
            "$slug/fixtures/ops.json" => '[]',
        ];
    }

    public function test_install_valid_plugin(): void
    {
        $res = $this->installer->install($this->makeZip($this->validPlugin()));
        $this->assertSame('demo-plugin', $res['slug']);
        $this->assertFileExists($this->cms . '/plugins/demo-plugin/plugin.json');
        $this->assertFileExists($this->cms . '/plugins/demo-plugin/src/Demo.php');
    }

    public function test_install_rejects_zip_slip(): void
    {
        $entries = $this->validPlugin();
        $entries['demo-plugin/../evil.php'] = '<?php echo "pwned";';
        try {
            $this->installer->install($this->makeZip($entries));
            $this->fail('expected zip-slip rejection');
        } catch (PluginInstallException $e) {
            $this->assertSame(422, $e->status);
        }
        $this->assertFileDoesNotExist($this->cms . '/evil.php');
        $this->assertFileDoesNotExist(\dirname($this->cms) . '/evil.php');
    }

    public function test_install_rejects_missing_manifest(): void
    {
        try {
            $this->installer->install($this->makeZip(['demo-plugin/readme.txt' => 'hi']));
            $this->fail('expected rejection');
        } catch (PluginInstallException $e) {
            $this->assertSame(422, $e->status);
        }
    }

    public function test_install_rejects_two_top_folders(): void
    {
        $entries = $this->validPlugin();
        $entries['other/file.txt'] = 'x';
        try {
            $this->installer->install($this->makeZip($entries));
            $this->fail('expected rejection');
        } catch (PluginInstallException $e) {
            $this->assertSame(422, $e->status);
        }
    }

    public function test_install_rejects_invalid_manifest(): void
    {
        // server.php points at a file that is not in the archive
        $entries = [
            'demo-plugin/plugin.json' => (string) json_encode([
                'slug' => 'demo-plugin', 'name' => ['en' => 'D'], 'version' => '1.0.0', 'api' => '1',
                'author' => 'local', 'trust' => 'local',
                'server' => ['php' => 'src/Missing.php', 'class' => 'Demo\\Block'],
                'fixtures' => 'fixtures/ops.json',
                'kinds' => [['kind' => 'demo', 'props_schema' => []]],
            ]),
            'demo-plugin/fixtures/ops.json' => '[]',
        ];
        try {
            $this->installer->install($this->makeZip($entries));
            $this->fail('expected rejection');
        } catch (PluginInstallException $e) {
            $this->assertSame(422, $e->status);
        }
        $this->assertFileDoesNotExist($this->cms . '/plugins/demo-plugin');
    }

    public function test_install_conflict_if_already_installed(): void
    {
        @mkdir($this->cms . '/plugins/demo-plugin', 0777, true);
        try {
            $this->installer->install($this->makeZip($this->validPlugin()));
            $this->fail('expected conflict');
        } catch (PluginInstallException $e) {
            $this->assertSame(409, $e->status);
        }
    }

    public function test_uninstall_removes_folder(): void
    {
        $this->installer->install($this->makeZip($this->validPlugin()));
        $this->assertDirectoryExists($this->cms . '/plugins/demo-plugin');
        $this->installer->uninstall('demo-plugin');
        $this->assertDirectoryDoesNotExist($this->cms . '/plugins/demo-plugin');
    }

    public function test_uninstall_bad_slug(): void
    {
        try {
            $this->installer->uninstall('../etc');
            $this->fail('expected rejection');
        } catch (PluginInstallException $e) {
            $this->assertSame(422, $e->status);
        }
    }

    public function test_uninstall_missing(): void
    {
        try {
            $this->installer->uninstall('nope');
            $this->fail('expected 404');
        } catch (PluginInstallException $e) {
            $this->assertSame(404, $e->status);
        }
    }
}
