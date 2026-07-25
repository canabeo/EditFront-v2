<?php

declare(strict_types=1);

namespace EditFront\Tests\Document;

use EditFront\Document\Annotator;
use EditFront\Document\DocumentService;
use EditFront\Document\Html5;
use EditFront\Document\TagAnnotatorLite;
use EditFront\Storage\BackupService;
use EditFront\Storage\FileStorage;
use EditFront\Storage\PagesIndex;
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
            new BackupService($config),
            new PagesIndex($config)
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

    /**
     * Bug 2 (MEDIUM, CWE-352 + CWE-20): opening a page WRITES to it (ids are
     * stamped to disk) and happens on a GET, which CsrfMiddleware does not cover
     * by design (WRITE_METHODS is POST/PUT/PATCH/DELETE). The session cookie is
     * SameSite=Lax, so a top-level navigation from an attacker's page carries
     * it: a logged-in admin merely following a link to /edit?page=<file> had
     * that file rewritten through the HTML5 parser. No click inside the editor
     * needed. Anything containing markup was a target — .svg, .xml, templates.
     */
    public function test_open_refuses_files_that_are_not_pages(): void
    {
        file_put_contents($this->site . '/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>');
        $before = (string) file_get_contents($this->site . '/logo.svg');

        try {
            $this->service()->openForEdit('logo.svg');
            $this->fail('expected a non-page file to be refused');
        } catch (StorageException $e) {
            $this->assertStringContainsString('not an editable page', $e->getMessage());
        }

        // untouched, byte for byte
        $this->assertSame($before, (string) file_get_contents($this->site . '/logo.svg'));
    }

    public function test_open_refuses_an_html_file_that_is_not_a_listed_page(): void
    {
        // .html, but hidden away where the index does not look
        @mkdir($this->site . '/.private', 0777, true);
        file_put_contents($this->site . '/.private/secret.html', '<p>secret</p>');
        $before = (string) file_get_contents($this->site . '/.private/secret.html');

        try {
            $this->service()->openForEdit('.private/secret.html');
            $this->fail('expected an unlisted page to be refused');
        } catch (StorageException $e) {
            $this->assertStringContainsString('not a known page', $e->getMessage());
        }

        $this->assertSame($before, (string) file_get_contents($this->site . '/.private/secret.html'));
    }

    public function test_open_still_works_for_a_page_created_moments_ago(): void
    {
        // guards the index-cache fallback: a fresh page must not 404
        $this->service()->openForEdit('page.html');           // warms the cache
        file_put_contents($this->site . '/brandnew.html', '<h1>new</h1>');

        $html = $this->service()->openForEdit('brandnew.html');
        $this->assertStringContainsString('<h1', $html);
    }
}
