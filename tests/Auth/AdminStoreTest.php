<?php

declare(strict_types=1);

namespace EditFront\Tests\Auth;

use EditFront\Auth\AdminStore;
use PHPUnit\Framework\TestCase;

final class AdminStoreTest extends TestCase
{
    private AdminStore $store;
    private string $adminFile;

    protected function setUp(): void
    {
        $dir = ef2_temp_dir('admin');
        $this->adminFile = $dir . '/admin.json';
        $this->store = new AdminStore(ef2_test_config([
            'storage_dir' => $dir,
            'admin_file' => $this->adminFile,
        ]));
        unset($_ENV['ADMIN_USERNAME'], $_ENV['ADMIN_PASSWORD_HASH']);
    }

    protected function tearDown(): void
    {
        unset($_ENV['ADMIN_USERNAME'], $_ENV['ADMIN_PASSWORD_HASH']);
    }

    // low bcrypt cost: tests need speed, production hashes come from outside
    private function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
    }

    public function test_create_and_verify(): void
    {
        $this->store->create('admin', $this->hash('s3cret'));
        $this->assertTrue($this->store->exists());
        $this->assertTrue($this->store->verify('admin', 's3cret'));
    }

    public function test_verify_rejects_wrong_password(): void
    {
        $this->store->create('admin', $this->hash('s3cret'));
        $this->assertFalse($this->store->verify('admin', 'wrong'));
    }

    public function test_verify_rejects_unknown_user(): void
    {
        $this->store->create('admin', $this->hash('s3cret'));
        $this->assertFalse($this->store->verify('intruder', 's3cret'));
    }

    public function test_verify_without_admin_file_is_false(): void
    {
        $this->assertFalse($this->store->verify('admin', 'anything'));
    }

    public function test_admin_file_is_private(): void
    {
        $this->store->create('admin', $this->hash('s3cret'));
        $this->assertSame(0o600, fileperms($this->adminFile) & 0o777);
    }

    public function test_bootstrap_from_env(): void
    {
        $_ENV['ADMIN_USERNAME'] = 'admin';
        $_ENV['ADMIN_PASSWORD_HASH'] = $this->hash('fromenv');
        $this->store->ensureBootstrap();
        $this->assertTrue($this->store->verify('admin', 'fromenv'));
    }

    public function test_bootstrap_does_not_overwrite_existing(): void
    {
        $this->store->create('admin', $this->hash('original'));
        $_ENV['ADMIN_USERNAME'] = 'admin';
        $_ENV['ADMIN_PASSWORD_HASH'] = $this->hash('changed');
        $this->store->ensureBootstrap();
        $this->assertTrue($this->store->verify('admin', 'original'));
        $this->assertFalse($this->store->verify('admin', 'changed'));
    }

    public function test_bootstrap_without_env_is_noop(): void
    {
        $this->store->ensureBootstrap();
        $this->assertFalse($this->store->exists());
    }

    public function test_set_password_changes_hash_preserving_username(): void
    {
        $this->store->create('admin', $this->hash('original'));
        $this->store->setPassword($this->hash('brandnew'));
        $this->assertTrue($this->store->verify('admin', 'brandnew'));
        $this->assertFalse($this->store->verify('admin', 'original'));
        $record = json_decode((string) file_get_contents($this->adminFile), true);
        $this->assertSame('admin', $record['username']);
        $this->assertArrayHasKey('updated_at', $record);
    }

    public function test_set_password_without_admin_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->store->setPassword($this->hash('whatever'));
    }
}
