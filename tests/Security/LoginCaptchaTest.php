<?php

declare(strict_types=1);

namespace EditFront\Tests\Security;

use EditFront\Security\LoginCaptcha;
use PHPUnit\Framework\TestCase;

/**
 * Session-bound math CAPTCHA: the answer lives in $_SESSION and is consumed on
 * every verify (one-shot, no replay).
 */
final class LoginCaptchaTest extends TestCase
{
    private LoginCaptcha $captcha;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->captcha = new LoginCaptcha();
    }

    public function test_issue_stores_answer_and_correct_answer_verifies(): void
    {
        $q = $this->captcha->issue();
        $this->assertMatchesRegularExpression('/^\d+ \+ \d+$/', $q);
        [$a, $b] = array_map('intval', explode(' + ', $q));
        $this->assertTrue($this->captcha->verify((string) ($a + $b)));
    }

    public function test_wrong_answer_fails(): void
    {
        $this->captcha->issue();
        $answer = $_SESSION['cms_captcha_sum'];
        $this->assertFalse($this->captcha->verify((string) ($answer + 1)));
    }

    public function test_verify_is_one_shot(): void
    {
        $this->captcha->issue();
        $answer = $_SESSION['cms_captcha_sum'];
        $this->assertTrue($this->captcha->verify((string) $answer));
        $this->assertFalse($this->captcha->verify((string) $answer)); // already consumed
    }

    public function test_null_and_nondigit_answers_fail(): void
    {
        $this->captcha->issue();
        $this->assertFalse($this->captcha->verify(null));
        $this->captcha->issue();
        $this->assertFalse($this->captcha->verify('abc'));
        $this->captcha->issue();
        $this->assertFalse($this->captcha->verify('  '));
    }
}
