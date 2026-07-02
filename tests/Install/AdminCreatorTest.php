<?php

declare(strict_types=1);

namespace EditFront\Tests\Install;

use EditFront\Auth\AdminStore;
use EditFront\Install\AdminCreator;
use EditFront\Install\EnvFile;
use EditFront\Install\InstallException;
use EditFront\Support\Config;
use PHPUnit\Framework\TestCase;

final class AdminCreatorTest extends TestCase
{
    private string $storage;
    private string $cms;
    private Config $config;
    private AdminCreator $creator;
    private AdminStore $admins;

    protected function setUp(): void
    {
        $this->storage = ef2_temp_dir('ac-storage');
        $this->cms = ef2_temp_dir('ac-cms');
        $this->config = ef2_test_config([
            'storage_dir' => $this->storage,
            'cms_dir' => $this->cms,
            'admin_file' => $this->storage . '/admin.json',
        ]);
        $this->admins = new AdminStore($this->config);
        $this->creator = new AdminCreator($this->config, $this->admins, new EnvFile($this->config));
    }

    public function test_install_creates_bcrypt_cost12_admin(): void
    {
        $this->assertFalse($this->creator->isInstalled());
        $res = $this->creator->install('admin', 'secret123');
        $this->assertTrue($this->creator->isInstalled());

        $record = json_decode((string) file_get_contents($this->config->get('admin_file')), true);
        $this->assertSame('admin', $record['username']);
        $info = password_get_info($record['password_hash']);
        $this->assertSame(PASSWORD_BCRYPT, $info['algo']);
        $this->assertSame(12, $info['options']['cost']);
        $this->assertTrue(password_verify('secret123', $record['password_hash']));
        // APP_SECRET persisted (env is writable in temp cms dir)
        $this->assertSame('env', $res['secret_persisted']);
        $this->assertSame(1, preg_match('/^[0-9a-f]{64}$/', (string) (new EnvFile($this->config))->get('APP_SECRET')));
    }

    public function test_reinstall_is_refused(): void
    {
        $this->creator->install('admin', 'secret123');
        try {
            $this->creator->install('admin', 'secret123');
            $this->fail('expected refusal');
        } catch (InstallException $e) {
            $this->assertSame(410, $e->status);
        }
    }

    public function test_invalid_username_rejected(): void
    {
        try {
            $this->creator->install('bad user!', 'secret123');
            $this->fail('expected 422');
        } catch (InstallException $e) {
            $this->assertSame(422, $e->status);
        }
        $this->assertFalse($this->creator->isInstalled());
    }

    public function test_short_password_rejected(): void
    {
        try {
            $this->creator->install('admin', '123');
            $this->fail('expected 422');
        } catch (InstallException $e) {
            $this->assertSame(422, $e->status);
        }
    }

    public function test_annotate_only_written_to_env(): void
    {
        $this->creator->install('admin', 'secret123', ['annotate_only' => true]);
        $this->assertSame('true', (new EnvFile($this->config))->get('ANNOTATE_ONLY'));
    }
}
