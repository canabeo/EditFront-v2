<?php

declare(strict_types=1);

namespace EditFront\Tests\Security;

use EditFront\Security\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function test_token_is_stable_within_session(): void
    {
        $csrf = new Csrf();
        $token = $csrf->token();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
        $this->assertSame($token, $csrf->token());
    }

    public function test_verify_accepts_valid_token(): void
    {
        $csrf = new Csrf();
        $this->assertTrue($csrf->verify($csrf->token()));
    }

    public function test_verify_rejects_wrong_token(): void
    {
        $csrf = new Csrf();
        $csrf->token();
        $this->assertFalse($csrf->verify('deadbeefdeadbeefdeadbeefdeadbeef'));
    }

    public function test_verify_rejects_null_and_empty(): void
    {
        $csrf = new Csrf();
        $csrf->token();
        $this->assertFalse($csrf->verify(null));
        $this->assertFalse($csrf->verify(''));
    }

    public function test_verify_rejects_when_no_token_issued(): void
    {
        $csrf = new Csrf();
        $this->assertFalse($csrf->verify('anything'));
    }
}
