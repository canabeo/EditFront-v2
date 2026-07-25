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

    /**
     * Common fix for bugs 4 and 6. The site root CONTAINS the CMS, so a path
     * like "cms/storage/admin.json" needs no traversal — PathGuard is not the
     * problem, the allowed area is. FileStorage must refuse anything inside the
     * CMS folder on EVERY operation, or the page APIs can read the password
     * hash, publish .env as a page, or delete the credential file.
     */
    private function storageWithCmsInsideSite(): array
    {
        $site = ef2_temp_dir('fs-site');
        $cms = $site . '/cms';
        @mkdir($cms . '/storage', 0777, true);
        file_put_contents($cms . '/storage/admin.json', '{"username":"admin"}');
        file_put_contents($cms . '/.env', "ADMIN_PASSWORD_HASH=secret\n");

        $storage = new FileStorage(
            ef2_test_config(['site_root' => $site, 'cms_dir' => $cms]),
            new PathGuard(),
        );
        return [$storage, $site];
    }

    public function test_read_refuses_paths_inside_the_cms(): void
    {
        [$storage] = $this->storageWithCmsInsideSite();

        foreach (['cms/storage/admin.json', 'cms/.env'] as $rel) {
            try {
                $storage->read($rel);
                $this->fail('expected refusal for: ' . $rel);
            } catch (InvalidPathException $e) {
                $this->assertStringContainsString('inside the CMS folder', $e->getMessage());
            }
        }
    }

    public function test_exists_reports_false_for_cms_files(): void
    {
        [$storage] = $this->storageWithCmsInsideSite();
        // the file is really there, but it is not addressable as site content
        $this->assertFalse($storage->exists('cms/storage/admin.json'));
    }

    public function test_delete_refuses_paths_inside_the_cms(): void
    {
        [$storage, $site] = $this->storageWithCmsInsideSite();

        try {
            $storage->delete('cms/storage/admin.json');
            $this->fail('expected refusal');
        } catch (InvalidPathException $e) {
            $this->assertStringContainsString('inside the CMS folder', $e->getMessage());
        }
        $this->assertFileExists($site . '/cms/storage/admin.json');
    }

    public function test_writes_refuse_paths_inside_the_cms(): void
    {
        [$storage, $site] = $this->storageWithCmsInsideSite();

        try {
            $storage->atomicWrite('cms/app/pwned.php', '<?php');
            $this->fail('expected refusal from atomicWrite');
        } catch (InvalidPathException) {
        }
        try {
            $storage->createNew('cms/pwned.html', 'x');
            $this->fail('expected refusal from createNew');
        } catch (InvalidPathException) {
        }
        $this->assertFileDoesNotExist($site . '/cms/pwned.html');
    }

    public function test_ordinary_site_files_are_unaffected(): void
    {
        [$storage, $site] = $this->storageWithCmsInsideSite();

        $storage->atomicWrite('about.html', '<h1>hi</h1>');
        $this->assertSame('<h1>hi</h1>', $storage->read('about.html'));
        $this->assertTrue($storage->exists('about.html'));
        $storage->delete('about.html');
        $this->assertFileDoesNotExist($site . '/about.html');
    }
}
