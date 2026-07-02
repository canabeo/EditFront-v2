<?php

declare(strict_types=1);

namespace EditFront\Tests\Storage;

use EditFront\Storage\FileStorage;
use EditFront\Storage\InvalidPathException;
use EditFront\Storage\PathGuard;
use EditFront\Storage\StorageException;
use PHPUnit\Framework\TestCase;

final class FileStorageTest extends TestCase
{
    private FileStorage $storage;
    private string $root;

    protected function setUp(): void
    {
        $this->root = ef2_temp_dir('storage');
        mkdir($this->root . '/sub');
        $this->storage = new FileStorage(
            ef2_test_config(['site_root' => $this->root]),
            new PathGuard()
        );
    }

    public function test_write_read_roundtrip(): void
    {
        $content = "<html>\n<body>тест</body>\n</html>\n";
        $this->storage->atomicWrite('page.html', $content);
        $this->assertSame($content, $this->storage->read('page.html'));
    }

    public function test_write_into_subdir(): void
    {
        $this->storage->atomicWrite('sub/page.html', 'x');
        $this->assertSame('x', $this->storage->read('sub/page.html'));
    }

    public function test_overwrite_replaces_content(): void
    {
        $this->storage->atomicWrite('page.html', 'old');
        $this->storage->atomicWrite('page.html', 'new');
        $this->assertSame('new', $this->storage->read('page.html'));
    }

    public function test_no_tmp_files_left_after_write(): void
    {
        $this->storage->atomicWrite('page.html', 'x');
        $leftovers = glob($this->root . '/.*tmp*') ?: [];
        $this->assertSame([], $leftovers);
    }

    public function test_rejects_traversal_on_write(): void
    {
        $this->expectException(InvalidPathException::class);
        $this->storage->atomicWrite('../escape.html', 'x');
    }

    public function test_rejects_traversal_on_read(): void
    {
        $this->expectException(InvalidPathException::class);
        $this->storage->read('../../etc/passwd');
    }

    public function test_write_to_missing_dir_throws_and_leaves_no_tmp(): void
    {
        try {
            $this->storage->atomicWrite('missing-dir/page.html', 'x');
            $this->fail('expected exception');
        } catch (StorageException) {
            // expected
        }
        $this->assertSame([], glob($this->root . '/missing-dir/*') ?: []);
    }

    public function test_exists(): void
    {
        $this->assertFalse($this->storage->exists('page.html'));
        $this->storage->atomicWrite('page.html', 'x');
        $this->assertTrue($this->storage->exists('page.html'));
    }
}
