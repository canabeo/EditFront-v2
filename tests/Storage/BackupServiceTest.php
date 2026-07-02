<?php

declare(strict_types=1);

namespace EditFront\Tests\Storage;

use EditFront\Storage\BackupService;
use PHPUnit\Framework\TestCase;

final class BackupServiceTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        $this->storage = ef2_temp_dir('backup');
    }

    private function service(array $overrides = []): BackupService
    {
        return new BackupService(ef2_test_config(array_merge([
            'storage_dir' => $this->storage,
        ], $overrides)));
    }

    public function test_creates_verified_gz_snapshot(): void
    {
        $content = '<html><body>контент</body></html>';
        $id = $this->service()->preSave('page.html', $content);

        $file = $this->storage . '/backups/pre-save/' . sha1('page.html') . '/' . $id . '.html.gz';
        $this->assertFileExists($file);
        $this->assertSame($content, gzdecode((string) file_get_contents($file)));
    }

    public function test_gc_keeps_default_retention_with_missing_config_key(): void
    {
        // ef2_test_config has NO backup_retention key → get() returns null →
        // the guard must fall back to 20, never to "delete everything" (v1 bug)
        $svc = $this->service();
        for ($i = 0; $i < 25; $i++) {
            $svc->preSave('page.html', 'content ' . $i);
        }
        $files = glob($this->storage . '/backups/pre-save/' . sha1('page.html') . '/*.html.gz');
        $this->assertCount(20, $files);
    }

    public function test_gc_honors_custom_retention(): void
    {
        $svc = $this->service(['backup_retention' => 3]);
        for ($i = 0; $i < 6; $i++) {
            $svc->preSave('page.html', 'content ' . $i);
        }
        $files = glob($this->storage . '/backups/pre-save/' . sha1('page.html') . '/*.html.gz') ?: [];
        $this->assertCount(3, $files);
        sort($files);
        // newest survive
        $this->assertSame('content 5', gzdecode((string) file_get_contents($files[2])));
    }

    public function test_list_for_page_newest_first(): void
    {
        $svc = $this->service();
        $svc->preSave('page.html', 'a');
        $svc->preSave('page.html', 'b');
        $list = $svc->listForPage('page.html');
        $this->assertCount(2, $list);
        $this->assertGreaterThanOrEqual(0, strcmp($list[0]['id'], $list[1]['id']));
    }
}
