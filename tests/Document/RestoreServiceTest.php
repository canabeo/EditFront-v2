<?php

declare(strict_types=1);

namespace EditFront\Tests\Document;

use EditFront\Document\RestoreService;
use EditFront\Storage\BackupService;
use EditFront\Storage\FileStorage;
use EditFront\Storage\PathGuard;
use EditFront\Storage\StorageException;
use EditFront\Support\Config;
use PHPUnit\Framework\TestCase;

final class RestoreServiceTest extends TestCase
{
    private string $site;
    private Config $config;
    private FileStorage $storage;
    private BackupService $backups;
    private RestoreService $restore;

    protected function setUp(): void
    {
        $this->site = ef2_temp_dir('restore-site');
        $this->config = ef2_test_config([
            'site_root' => $this->site,
            'storage_dir' => ef2_temp_dir('restore-storage'),
            'cms_dir' => $this->site . '/cms',
        ]);
        @mkdir($this->config->cmsDir(), 0777, true);
        $this->storage = new FileStorage($this->config, new PathGuard());
        $this->backups = new BackupService($this->config);
        $this->restore = new RestoreService($this->storage, $this->backups);
    }

    private function put(string $rel, string $content): void
    {
        file_put_contents($this->site . '/' . $rel, $content);
    }

    public function test_restore_writes_snapshot_and_backs_up_current_first(): void
    {
        $this->put('p.html', '<html><body>v1</body></html>');
        $snapId = $this->backups->preSave('p.html', '<html><body>v1</body></html>');

        // current advances to v2
        $this->put('p.html', '<html><body>v2-current</body></html>');

        $res = $this->restore->restore('p.html', $snapId);

        // page is back to v1
        $this->assertStringContainsString('v1', file_get_contents($this->site . '/p.html'));
        $this->assertSame(hash('sha256', '<html><body>v1</body></html>'), $res['new_sha256']);
        // a pre-restore backup of v2 was taken (v1 F4-bugfix7 invariant)
        $this->assertNotSame('', $res['pre_restore_backup_id']);
        $preContent = $this->backups->readContent('p.html', $res['pre_restore_backup_id']);
        $this->assertStringContainsString('v2-current', $preContent);
    }

    public function test_restore_bad_id_rejected(): void
    {
        $this->put('p.html', 'x');
        $this->expectException(StorageException::class);
        $this->restore->restore('p.html', '../etc/passwd');
    }

    public function test_restore_missing_snapshot_rejected(): void
    {
        $this->put('p.html', 'x');
        $this->expectException(StorageException::class);
        $this->restore->restore('p.html', '20200101-000000-000000-abcdef');
    }

    public function test_restore_recreates_deleted_page(): void
    {
        $this->put('gone.html', '<html><body>before</body></html>');
        $snapId = $this->backups->preSave('gone.html', '<html><body>before</body></html>');
        unlink($this->site . '/gone.html');

        $res = $this->restore->restore('gone.html', $snapId);
        $this->assertFileExists($this->site . '/gone.html');
        $this->assertSame('', $res['pre_restore_backup_id'], 'no current to back up');
    }

    public function test_read_content_validates_id(): void
    {
        $this->expectException(StorageException::class);
        $this->backups->readContent('p.html', 'not-an-id');
    }
}
