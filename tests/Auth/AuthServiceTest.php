<?php

declare(strict_types=1);

namespace EditFront\Tests\Auth;

use EditFront\Auth\AdminStore;
use EditFront\Auth\AuthService;
use PHPUnit\Framework\TestCase;

final class AuthServiceTest extends TestCase
{
    private AdminStore $store;
    private AuthService $auth;

    protected function setUp(): void
    {
        $dir = ef2_temp_dir('auth');
        $this->store = new AdminStore(ef2_test_config([
            'storage_dir' => $dir,
            'admin_file' => $dir . '/admin.json',
        ]));
        // low bcrypt cost for the seed hash — speed; changePassword writes its own
        $this->store->create('admin', password_hash('s3cret', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->auth = new AuthService($this->store);
        $_SESSION = ['cms_user' => 'admin', 'cms_expires_at' => time() + 9999];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function test_change_password_with_correct_current(): void
    {
        $this->assertTrue($this->auth->changePassword('s3cret', 'newsecret12'));
        $this->assertTrue($this->store->verify('admin', 'newsecret12'));
        $this->assertFalse($this->store->verify('admin', 's3cret'));
    }

    public function test_change_password_wrong_current_is_rejected(): void
    {
        $this->assertFalse($this->auth->changePassword('wrongpass', 'newsecret12'));
        $this->assertTrue($this->store->verify('admin', 's3cret')); // unchanged
    }

    public function test_change_password_without_session_is_rejected(): void
    {
        $_SESSION = [];
        $this->assertFalse($this->auth->changePassword('s3cret', 'newsecret12'));
        $this->assertTrue($this->store->verify('admin', 's3cret'));
    }
}
