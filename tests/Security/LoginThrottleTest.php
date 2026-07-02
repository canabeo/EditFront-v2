<?php

declare(strict_types=1);

namespace EditFront\Tests\Security;

use EditFront\Security\LoginThrottle;
use PHPUnit\Framework\TestCase;

/**
 * Tiered throttle (§13): 2 failures → CAPTCHA, 5 failures → 10-minute IP block.
 * Failures count per IP (hard block) and per username (CAPTCHA only, anti-DoS).
 */
final class LoginThrottleTest extends TestCase
{
    private LoginThrottle $throttle;

    protected function setUp(): void
    {
        $this->throttle = new LoginThrottle(
            ef2_test_config(['storage_dir' => ef2_temp_dir('throttle')])
        );
    }

    public function test_not_blocked_below_limit(): void
    {
        $now = 1_000_000;
        for ($i = 0; $i < 4; $i++) {
            $this->throttle->registerFailure('1.2.3.4', '', $now + $i);
        }
        $this->assertSame(0, $this->throttle->blockedFor('1.2.3.4', $now + 10));
    }

    public function test_captcha_required_after_two_failures(): void
    {
        $now = 1_000_000;
        $this->assertFalse($this->throttle->captchaRequired('1.2.3.4', '', $now));
        $this->throttle->registerFailure('1.2.3.4', '', $now);
        $this->assertFalse($this->throttle->captchaRequired('1.2.3.4', '', $now + 1)); // 1 fail
        $this->throttle->registerFailure('1.2.3.4', '', $now + 1);
        $this->assertTrue($this->throttle->captchaRequired('1.2.3.4', '', $now + 2));  // 2 fails
    }

    public function test_blocked_after_five_failures_for_ten_minutes(): void
    {
        $now = 1_000_000;
        for ($i = 0; $i < 5; $i++) {
            $this->throttle->registerFailure('1.2.3.4', '', $now + $i);
        }
        $remaining = $this->throttle->blockedFor('1.2.3.4', $now + 10);
        $this->assertGreaterThan(500, $remaining);
        $this->assertLessThanOrEqual(600, $remaining);
    }

    public function test_block_expires(): void
    {
        $now = 1_000_000;
        for ($i = 0; $i < 5; $i++) {
            $this->throttle->registerFailure('1.2.3.4', '', $now);
        }
        $this->assertSame(0, $this->throttle->blockedFor('1.2.3.4', $now + 601));
    }

    public function test_old_failures_pruned_outside_window(): void
    {
        $now = 1_000_000;
        for ($i = 0; $i < 4; $i++) {
            $this->throttle->registerFailure('1.2.3.4', '', $now);
        }
        // a failure long after the 15-minute window → previous ones pruned
        $this->throttle->registerFailure('1.2.3.4', '', $now + 901);
        $this->assertSame(0, $this->throttle->blockedFor('1.2.3.4', $now + 902));
        $this->assertFalse($this->throttle->captchaRequired('1.2.3.4', '', $now + 902));
    }

    public function test_clear_resets_ip(): void
    {
        $now = 1_000_000;
        for ($i = 0; $i < 5; $i++) {
            $this->throttle->registerFailure('1.2.3.4', '', $now);
        }
        $this->throttle->clear('1.2.3.4', '');
        $this->assertSame(0, $this->throttle->blockedFor('1.2.3.4', $now));
    }

    public function test_ips_are_independent(): void
    {
        $now = 1_000_000;
        for ($i = 0; $i < 5; $i++) {
            $this->throttle->registerFailure('1.2.3.4', '', $now);
        }
        $this->assertSame(0, $this->throttle->blockedFor('5.6.7.8', $now));
    }

    public function test_distributed_failures_on_one_username_trigger_captcha(): void
    {
        $now = 1_000_000;
        // two different IPs, one failure each, same username
        $this->throttle->registerFailure('1.1.1.1', 'admin', $now);
        $this->throttle->registerFailure('2.2.2.2', 'admin', $now + 1);
        // a fresh IP on its own is not throttled
        $this->assertFalse($this->throttle->captchaRequired('3.3.3.3', '', $now + 2));
        // but the username has 2 recent failures → CAPTCHA required for it
        $this->assertTrue($this->throttle->captchaRequired('3.3.3.3', 'admin', $now + 2));
    }

    public function test_username_failures_do_not_hard_block(): void
    {
        $now = 1_000_000;
        // 6 failures against one username, each from a distinct IP
        for ($i = 0; $i < 6; $i++) {
            $this->throttle->registerFailure('9.9.9.' . $i, 'admin', $now + $i);
        }
        // no single IP is blocked → the real admin is never locked out
        $this->assertSame(0, $this->throttle->blockedFor('10.0.0.1', $now + 10));
        $this->assertTrue($this->throttle->captchaRequired('10.0.0.1', 'admin', $now + 10));
    }
}
