<?php

declare(strict_types=1);

namespace EditFront\Tests\Storage;

use EditFront\Storage\InvalidPathException;
use EditFront\Storage\PathGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PathGuardTest extends TestCase
{
    private PathGuard $guard;
    private string $root;

    protected function setUp(): void
    {
        $this->guard = new PathGuard();
        $this->root = ef2_temp_dir('pathguard');
        mkdir($this->root . '/sub');
        file_put_contents($this->root . '/page.html', '<html></html>');
        file_put_contents($this->root . '/sub/nested.html', '<html></html>');
    }

    /** @return iterable<string, array{string}> */
    public static function badPaths(): iterable
    {
        yield 'empty' => [''];
        yield 'traversal' => ['../etc/passwd'];
        yield 'nested traversal' => ['sub/../../etc/passwd'];
        yield 'dot segment' => ['./page.html'];
        yield 'absolute' => ['/etc/passwd'];
        yield 'windows drive' => ['C:/windows'];
        yield 'null byte' => ["page\0.html"];
        yield 'leading dash' => ['-rf.html'];
        yield 'backslash' => ['sub\\nested.html'];
        yield 'double slash' => ['sub//nested.html'];
    }

    #[DataProvider('badPaths')]
    public function test_rejects_unsafe_relative_paths(string $path): void
    {
        $this->expectException(InvalidPathException::class);
        $this->guard->assertSafeRelative($path);
    }

    public function test_resolves_existing_file_inside_root(): void
    {
        $abs = $this->guard->resolveWithin($this->root, 'sub/nested.html');
        $this->assertSame(realpath($this->root . '/sub/nested.html'), $abs);
    }

    public function test_rejects_missing_file_when_must_exist(): void
    {
        $this->expectException(InvalidPathException::class);
        $this->guard->resolveWithin($this->root, 'absent.html');
    }

    public function test_resolves_new_file_in_existing_dir(): void
    {
        $abs = $this->guard->resolveWithin($this->root, 'sub/new.html', false);
        $this->assertSame(realpath($this->root . '/sub') . '/new.html', $abs);
    }

    public function test_rejects_new_file_in_missing_dir(): void
    {
        $this->expectException(InvalidPathException::class);
        $this->guard->resolveWithin($this->root, 'nope/new.html', false);
    }

    public function test_rejects_symlink_escape(): void
    {
        $outside = ef2_temp_dir('outside');
        file_put_contents($outside . '/secret.html', 'secret');
        symlink($outside, $this->root . '/link');

        $this->expectException(InvalidPathException::class);
        $this->guard->resolveWithin($this->root, 'link/secret.html');
    }
}
