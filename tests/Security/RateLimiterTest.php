<?php

declare(strict_types=1);

namespace EditFront\Tests\Security;

use EditFront\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    private RateLimiter $limiter;

    protected function setUp(): void
    {
        $this->limiter = new RateLimiter(ef2_test_config(['storage_dir' => ef2_temp_dir('rate')]));
    }

    public function test_allows_up_to_max_then_blocks(): void
    {
        $now = 1_000_000;
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($this->limiter->allow('save', '1.2.3.4', 5, 60, $now + $i));
        }
        $this->assertFalse($this->limiter->allow('save', '1.2.3.4', 5, 60, $now + 6));
    }

    public function test_window_slides(): void
    {
        $now = 1_000_000;
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->allow('save', '1.2.3.4', 5, 60, $now);
        }
        $this->assertFalse($this->limiter->allow('save', '1.2.3.4', 5, 60, $now + 30));
        $this->assertTrue($this->limiter->allow('save', '1.2.3.4', 5, 60, $now + 61));
    }

    public function test_buckets_and_keys_independent(): void
    {
        $now = 1_000_000;
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->allow('save', '1.2.3.4', 5, 60, $now);
        }
        $this->assertTrue($this->limiter->allow('upload', '1.2.3.4', 5, 60, $now));
        $this->assertTrue($this->limiter->allow('save', '5.6.7.8', 5, 60, $now));
    }
}
