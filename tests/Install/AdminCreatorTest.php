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
        // isInstalled() consults the environment (an .env-provisioned instance is
        // already installed), and booting the real container elsewhere in the
        // suite loads this deployment's own .env into $_ENV. Start from a known
        // state so these tests describe the wizard, not the host machine.
        self::clearAdminEnv();
    }

    protected function tearDown(): void
    {
        self::clearAdminEnv();
    }

    private static function clearAdminEnv(): void
    {
        unset(
            $_ENV['ADMIN_USERNAME'], $_ENV['ADMIN_PASSWORD_HASH'],
            $_SERVER['ADMIN_USERNAME'], $_SERVER['ADMIN_PASSWORD_HASH'],
        );
        putenv('ADMIN_USERNAME');
        putenv('ADMIN_PASSWORD_HASH');
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

    /**
     * Bug 1 (HIGH, CWE-306): isInstalled() asked only "does storage/admin.json
     * exist?". Provisioning through .env (ADMIN_USERNAME + ADMIN_PASSWORD_HASH)
     * does NOT create that file — only AdminStore::ensureBootstrap() does, and it
     * runs solely on the first successful login. So an instance deployed via .env
     * and not yet logged into kept the wizard open to anyone: an anonymous
     * visitor could POST /install, write their own hash, permanently
     * short-circuit ensureBootstrap() and kill the operator's credentials.
     */
    public function test_env_provisioned_instance_is_already_installed(): void
    {
        $_ENV['ADMIN_USERNAME'] = 'operator';
        $_ENV['ADMIN_PASSWORD_HASH'] = password_hash('operator-pw', PASSWORD_BCRYPT, ['cost' => 4]);
        try {
            // the credential file is deliberately absent — this is the vulnerable state
            $this->assertFileDoesNotExist((string) $this->config->get('admin_file'));
            $this->assertTrue(
                $this->creator->isInstalled(),
                'an .env-provisioned instance must not present the wizard',
            );
        } finally {
            unset($_ENV['ADMIN_USERNAME'], $_ENV['ADMIN_PASSWORD_HASH']);
        }
    }

    public function test_env_provisioned_instance_refuses_a_competing_admin(): void
    {
        $_ENV['ADMIN_USERNAME'] = 'operator';
        $_ENV['ADMIN_PASSWORD_HASH'] = password_hash('operator-pw', PASSWORD_BCRYPT, ['cost' => 4]);
        try {
            try {
                $this->creator->install('attacker', 'attacker-pw');
                $this->fail('expected the wizard to refuse');
            } catch (InstallException $e) {
                $this->assertSame(410, $e->status);
            }

            // the operator's credentials must still be the ones that work
            $this->admins->ensureBootstrap();
            $this->assertTrue($this->admins->verify('operator', 'operator-pw'));
            $this->assertFalse($this->admins->verify('attacker', 'attacker-pw'));
        } finally {
            unset($_ENV['ADMIN_USERNAME'], $_ENV['ADMIN_PASSWORD_HASH']);
        }
    }

    /**
     * Guard against over-correcting: a half-filled .env must NOT be mistaken for
     * a provisioned admin, or the operator would face a permanently closed wizard
     * with no account to log into.
     */
    public function test_partial_env_provisioning_does_not_close_the_wizard(): void
    {
        $_ENV['ADMIN_USERNAME'] = 'operator'; // hash missing
        try {
            $this->assertFalse($this->creator->isInstalled());
        } finally {
            unset($_ENV['ADMIN_USERNAME']);
        }

        $_ENV['ADMIN_PASSWORD_HASH'] = password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]); // username missing
        try {
            $this->assertFalse($this->creator->isInstalled());
        } finally {
            unset($_ENV['ADMIN_PASSWORD_HASH']);
        }
    }
}
