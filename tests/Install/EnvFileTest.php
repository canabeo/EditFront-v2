<?php

declare(strict_types=1);

namespace EditFront\Tests\Install;

use EditFront\Install\EnvFile;
use EditFront\Support\Config;
use PHPUnit\Framework\TestCase;

final class EnvFileTest extends TestCase
{
    private function envFile(string $cmsDir): EnvFile
    {
        return new EnvFile(new Config(['cms_dir' => $cmsDir]));
    }

    public function test_set_then_get_roundtrip(): void
    {
        $cms = ef2_temp_dir('env-cms');
        $env = $this->envFile($cms);
        $this->assertTrue($env->set('APP_SECRET', 'deadbeef'));
        $this->assertSame('deadbeef', $env->get('APP_SECRET'));
    }

    public function test_set_replaces_existing_key(): void
    {
        $cms = ef2_temp_dir('env-cms');
        file_put_contents($cms . '/.env', "FOO=1\nAPP_SECRET=old\nBAR=2\n");
        $env = $this->envFile($cms);
        $this->assertTrue($env->set('APP_SECRET', 'new'));
        $this->assertSame('new', $env->get('APP_SECRET'));
        $this->assertSame('1', $env->get('FOO'));
        $this->assertSame('2', $env->get('BAR'));
        // exactly one APP_SECRET line
        $this->assertSame(1, substr_count((string) file_get_contents($cms . '/.env'), 'APP_SECRET='));
    }

    public function test_get_missing_returns_null(): void
    {
        $env = $this->envFile(ef2_temp_dir('env-cms'));
        $this->assertNull($env->get('NOPE'));
    }

    public function test_is_writable_true_for_new_dir(): void
    {
        $env = $this->envFile(ef2_temp_dir('env-cms'));
        $this->assertTrue($env->isWritable());
    }
}
