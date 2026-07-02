<?php

declare(strict_types=1);

namespace EditFront\Tests\Document;

use EditFront\Document\Annotator;
use EditFront\Document\Html5;
use EditFront\Document\PageCrudException;
use EditFront\Document\PageService;
use EditFront\Draft\DraftService;
use EditFront\Draft\UndoPayloadStore;
use EditFront\Plugin\PropsStore;
use EditFront\Storage\BackupService;
use EditFront\Storage\FileStorage;
use EditFront\Storage\Journal;
use EditFront\Storage\PagesIndex;
use EditFront\Storage\PathGuard;
use EditFront\Support\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PageServiceTest extends TestCase
{
    private string $site;
    private string $storage;
    private PageService $svc;
    private Config $config;

    protected function setUp(): void
    {
        $this->site = ef2_temp_dir('pages-site');
        $this->storage = ef2_temp_dir('pages-storage');
        $this->config = ef2_test_config([
            'site_root' => $this->site,
            'storage_dir' => $this->storage,
            // cmsDir must NOT equal siteRoot (PagesIndex excludes cmsDir)
            'cms_dir' => $this->site . '/cms',
        ]);
        @mkdir($this->config->cmsDir(), 0777, true);

        $guard = new PathGuard();
        $storage = new FileStorage($this->config, $guard);
        $html5 = new Html5();
        $annotator = new Annotator();
        $this->svc = new PageService(
            $this->config,
            $storage,
            $html5,
            $annotator,
            new BackupService($this->config),
            new PagesIndex($this->config),
            new PropsStore($this->config),
            new DraftService($this->config, new UndoPayloadStore($this->config)),
            new Journal($this->config),
            $guard,
        );
    }

    private function put(string $rel, string $content): void
    {
        $abs = $this->site . '/' . $rel;
        @mkdir(\dirname($abs), 0777, true);
        file_put_contents($abs, $content);
    }

    public function test_create_blank_stamps_ids_and_is_editable(): void
    {
        $res = $this->svc->create('about.html');
        $this->assertSame('about.html', $res['path']);

        $html = file_get_contents($this->site . '/about.html');
        $this->assertIsString($html);
        // ids on disk before first open (v1 lesson) — addressable immediately
        $this->assertSame(1, preg_match('/data-cms-id="cms-[0-9a-f]{12}"/', $html));
        $this->assertStringContainsString('<h1', $html);
    }

    public function test_create_clone_copies_source_content(): void
    {
        $this->put('source.html', '<!doctype html><html><head><title>Src</title></head><body><h1>Cloned heading</h1></body></html>');
        $this->svc->create('copy.html', 'source.html');

        $html = file_get_contents($this->site . '/copy.html');
        $this->assertStringContainsString('Cloned heading', (string) $html);
        $this->assertSame(1, preg_match('/data-cms-id="cms-[0-9a-f]{12}"/', (string) $html));
    }

    public function test_create_existing_page_is_conflict(): void
    {
        $this->svc->create('dup.html');
        try {
            $this->svc->create('dup.html');
            $this->fail('expected conflict');
        } catch (PageCrudException $e) {
            $this->assertSame(409, $e->status);
        }
    }

    public function test_clone_missing_source_is_404(): void
    {
        try {
            $this->svc->create('x.html', 'nope.html');
            $this->fail('expected 404');
        } catch (PageCrudException $e) {
            $this->assertSame(404, $e->status);
        }
    }

    #[DataProvider('badNames')]
    public function test_invalid_names_are_rejected(string $bad): void
    {
        try {
            $this->svc->create($bad);
            $this->fail('expected rejection for: ' . $bad);
        } catch (PageCrudException $e) {
            $this->assertSame(422, $e->status, $bad);
        }
    }

    /** @return list<array{0: string}> */
    public static function badNames(): array
    {
        return [
            ['../escape.html'],
            ['nothtml.txt'],
            ['noext'],
            ['-leading.html'],
            ['has space.html'],
            ['newdir/page.html'], // newdir does not exist → no new subfolders
        ];
    }

    public function test_create_inside_existing_subfolder_ok(): void
    {
        @mkdir($this->site . '/blog', 0777, true);
        $res = $this->svc->create('blog/post.html');
        $this->assertFileExists($this->site . '/blog/post.html');
        $this->assertSame('blog/post.html', $res['path']);
    }

    public function test_duplicate_creates_target(): void
    {
        $this->put('orig.html', '<!doctype html><html><head><title>O</title></head><body><p>Original</p></body></html>');
        $this->svc->duplicate('orig.html', 'orig-copy.html');
        $this->assertStringContainsString('Original', (string) file_get_contents($this->site . '/orig-copy.html'));
    }

    public function test_delete_makes_backup_and_cleans_sidecars(): void
    {
        $this->svc->create('gone.html');
        $page = 'gone.html';

        // seed sidecars so we can prove cleanup
        (new PropsStore($this->config))->sync($page, ['cms-aaaaaaaaaaaa' => ['kind' => 'k', 'version' => 1, 'props' => []]]);
        (new DraftService($this->config, new UndoPayloadStore($this->config)))->save($page, [
            'base_sha256' => str_repeat('a', 64), 'cursor' => 0, 'entries' => [], 'serial' => 0,
        ]);
        (new Journal($this->config))->append($page, [['op' => 'text.set']], 'batch1');

        $metaFile = $this->storage . '/meta/' . sha1($page) . '.json';
        $draftFile = $this->storage . '/drafts/' . sha1($page) . '.json';
        $journalFile = $this->storage . '/journal/' . sha1($page) . '.jsonl';
        $this->assertFileExists($metaFile);
        $this->assertFileExists($draftFile);
        $this->assertFileExists($journalFile);

        $res = $this->svc->delete($page);

        $this->assertNotSame('', $res['backup_id']);
        $this->assertFileDoesNotExist($this->site . '/' . $page);
        $this->assertFileDoesNotExist($metaFile);
        $this->assertFileDoesNotExist($draftFile);
        $this->assertFileDoesNotExist($journalFile);
        // pre-delete backup is kept
        $this->assertNotEmpty((new BackupService($this->config))->listForPage($page));
    }

    public function test_delete_missing_page_is_404(): void
    {
        try {
            $this->svc->delete('ghost.html');
            $this->fail('expected 404');
        } catch (PageCrudException $e) {
            $this->assertSame(404, $e->status);
        }
    }
}
