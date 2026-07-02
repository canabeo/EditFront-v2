<?php

declare(strict_types=1);

namespace EditFront\Tests\Document;

use EditFront\Document\Annotator;
use EditFront\Document\DocumentService;
use EditFront\Document\Html5;
use EditFront\Document\TagAnnotatorLite;
use EditFront\Storage\BackupService;
use EditFront\Storage\FileStorage;
use EditFront\Storage\PathGuard;
use EditFront\Storage\StorageException;
use EditFront\Support\Config;
use PHPUnit\Framework\TestCase;

final class DocumentServiceTest extends TestCase
{
    private string $site;
    private string $storage;

    protected function setUp(): void
    {
        $this->site = ef2_temp_dir('docsvc-site');
        $this->storage = ef2_temp_dir('docsvc-storage');
        copy(dirname(__DIR__) . '/fixtures/page.html', $this->site . '/page.html');
    }

    private function service(array $overrides = []): DocumentService
    {
        $config = ef2_test_config(array_merge([
            'site_root' => $this->site,
            'storage_dir' => $this->storage,
        ], $overrides));
        $annotator = new Annotator();
        return new DocumentService(
            $config,
            new FileStorage($config, new PathGuard()),
            new Html5(),
            $annotator,
            new TagAnnotatorLite($annotator),
            new BackupService($config)
        );
    }

    public function test_open_stamps_ids_to_disk_with_backup(): void
    {
        $original = (string) file_get_contents($this->site . '/page.html');
        $html = $this->service()->openForEdit('page.html');

        $onDisk = (string) file_get_contents($this->site . '/page.html');
        $this->assertSame($html, $onDisk, 'returned html must equal disk content');
        $this->assertMatchesRegularExpression('/data-cms-id="cms-[0-9a-f]{12}"/', $onDisk);

        // mandatory pre-stamp backup holds the ORIGINAL bytes
        $backups = glob($this->storage . '/backups/pre-save/' . sha1('page.html') . '/*.html.gz');
        $this->assertCount(1, $backups);
        $this->assertSame($original, gzdecode((string) file_get_contents($backups[0])));
    }

    public function test_second_open_is_noop(): void
    {
        $svc = $this->service();
        $first = $svc->openForEdit('page.html');
        $second = $svc->openForEdit('page.html');

        $this->assertSame($first, $second);
        $backups = glob($this->storage . '/backups/pre-save/' . sha1('page.html') . '/*.html.gz');
        $this->assertCount(1, $backups, 'no second backup for an unchanged open');
    }

    public function test_annotate_only_preserves_formatting(): void
    {
        $raw = "<!DOCTYPE html>\n<html>\n<head><title>X</title></head>\n<body>\n"
            . "\t<p   class=\"q\" >Weird   spacing</p>\n\n</body>\n</html>\n";
        file_put_contents($this->site . '/weird.html', $raw);

        $this->service(['annotate_only' => true])->openForEdit('weird.html');

        $onDisk = (string) file_get_contents($this->site . '/weird.html');
        $stripped = (string) preg_replace('/ data-cms-id="cms-[0-9a-f]{12}"/', '', $onDisk);
        $this->assertSame($raw, $stripped, 'ANNOTATE_ONLY must not recanonicalize');
    }

    public function test_no_backup_no_stamp_write(): void
    {
        // storage dir path under a regular FILE → backup mkdir fails → open must throw and NOT touch the page
        $blocked = $this->storage . '/blocked';
        file_put_contents($blocked, 'iamafile');
        $original = (string) file_get_contents($this->site . '/page.html');

        try {
            $this->service(['storage_dir' => $blocked . '/sub'])->openForEdit('page.html');
            $this->fail('expected StorageException');
        } catch (StorageException) {
            // expected
        }
        $this->assertSame($original, (string) file_get_contents($this->site . '/page.html'));
    }
}
