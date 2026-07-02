<?php

declare(strict_types=1);

namespace EditFront\Tests\Document;

use EditFront\Document\Annotator;
use EditFront\Document\ConflictException;
use EditFront\Document\Html5;
use EditFront\Document\SaveService;
use EditFront\Operation\OperationValidationException;
use EditFront\Operation\SchemaValidator;
use EditFront\Storage\BackupService;
use EditFront\Storage\FileStorage;
use EditFront\Storage\Journal;
use EditFront\Storage\PathGuard;
use EditFront\Storage\StorageException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SaveServiceTest extends TestCase
{
    private string $site;
    private string $storage;

    protected function setUp(): void
    {
        $this->site = ef2_temp_dir('save-site');
        $this->storage = ef2_temp_dir('save-storage');
        file_put_contents(
            $this->site . '/page.html',
            '<!DOCTYPE html><html><head><title>t</title></head><body>'
            . '<h1 data-cms-id="cms-aaaaaaaaaaaa">Old</h1>'
            . '<p data-cms-id="cms-dddddddddddd">Two</p>'
            . "</body></html>\n"
        );
    }

    private function service(array $overrides = []): SaveService
    {
        $config = ef2_test_config(array_merge([
            'site_root' => $this->site,
            'storage_dir' => $this->storage,
        ], $overrides));
        return new SaveService(
            new FileStorage($config, new PathGuard()),
            new Html5(),
            new Annotator(),
            ef2_registry(),
            new SchemaValidator(),
            new BackupService($config),
            new Journal($config),
            ef2_plugin_manager($config),
            ef2_props_store($config),
            new \EditFront\Document\StateCssRenderer(new \EditFront\Security\SanitizerCss()),
            new \EditFront\Document\FontFaceRenderer(new \EditFront\Font\FontService($config, new \EditFront\Http\UrlHelper($config))),
            new NullLogger()
        );
    }

    private function currentSha(): string
    {
        return hash('sha256', (string) file_get_contents($this->site . '/page.html'));
    }

    public function test_save_applies_ops_atomically_with_backup(): void
    {
        $original = (string) file_get_contents($this->site . '/page.html');
        $result = $this->service()->save('page.html', $this->currentSha(), [
            ['op' => 'text.set', 'target' => 'cms-aaaaaaaaaaaa', 'forward' => ['html' => 'New <b>title</b>']],
        ]);

        $onDisk = (string) file_get_contents($this->site . '/page.html');
        $this->assertStringContainsString('New <b>title</b>', $onDisk);
        $this->assertSame(1, $result['applied']);
        $this->assertSame([], $result['warnings']);
        $this->assertSame(hash('sha256', $onDisk), $result['new_sha256']);

        // pre-save backup contains the PREVIOUS content
        $backups = glob($this->storage . '/backups/pre-save/' . sha1('page.html') . '/*.html.gz');
        $this->assertCount(1, $backups);
        $this->assertSame($original, gzdecode((string) file_get_contents($backups[0])));

        // journal got the batch
        $journal = (string) file_get_contents($this->storage . '/journal/' . sha1('page.html') . '.jsonl');
        $this->assertStringContainsString('"_batch"', $journal);
        $this->assertStringContainsString('text.set', $journal);
    }

    public function test_saved_file_is_canonical_fixed_point(): void
    {
        $this->service()->save('page.html', $this->currentSha(), [
            ['op' => 'text.set', 'target' => 'cms-aaaaaaaaaaaa', 'forward' => ['html' => 'X']],
        ]);
        $html5 = new Html5();
        $onDisk = (string) file_get_contents($this->site . '/page.html');
        $this->assertSame($onDisk, $html5->serialize($html5->parse($onDisk)), 'saved file must be canonical — clean diffs');
    }

    public function test_sha_mismatch_conflicts_force_overrides(): void
    {
        try {
            $this->service()->save('page.html', 'deadbeef', [
                ['op' => 'node.delete', 'target' => 'cms-dddddddddddd', 'forward' => []],
            ]);
            $this->fail('expected ConflictException');
        } catch (ConflictException $e) {
            $this->assertSame($this->currentSha(), $e->currentSha256);
        }

        $result = $this->service()->save('page.html', 'deadbeef', [
            ['op' => 'node.delete', 'target' => 'cms-dddddddddddd', 'forward' => []],
        ], force: true);
        $this->assertSame(1, $result['applied']);
        $this->assertStringNotContainsString('cms-dddddddddddd', (string) file_get_contents($this->site . '/page.html'));
    }

    public function test_unknown_op_rejects_whole_batch_before_backup(): void
    {
        $original = (string) file_get_contents($this->site . '/page.html');
        try {
            $this->service()->save('page.html', $this->currentSha(), [
                ['op' => 'text.set', 'target' => 'cms-aaaaaaaaaaaa', 'forward' => ['html' => 'ok']],
                ['op' => 'evil.op', 'target' => 'cms-aaaaaaaaaaaa', 'forward' => []],
            ]);
            $this->fail('expected OperationValidationException');
        } catch (OperationValidationException) {
            // expected
        }
        $this->assertSame($original, (string) file_get_contents($this->site . '/page.html'));
        $this->assertSame([], glob($this->storage . '/backups/pre-save/' . sha1('page.html') . '/*') ?: []);
    }

    public function test_unknown_forward_field_rejects_batch(): void
    {
        $this->expectException(OperationValidationException::class);
        $this->service()->save('page.html', $this->currentSha(), [
            ['op' => 'text.set', 'target' => 'cms-aaaaaaaaaaaa', 'forward' => ['html' => 'x', 'sneaky' => 'y']],
        ]);
    }

    public function test_missing_target_becomes_warning_not_failure(): void
    {
        $result = $this->service()->save('page.html', $this->currentSha(), [
            ['op' => 'text.set', 'target' => 'cms-000000000000', 'forward' => ['html' => 'ghost']],
            ['op' => 'text.set', 'target' => 'cms-aaaaaaaaaaaa', 'forward' => ['html' => 'real']],
        ]);
        $this->assertSame(1, $result['applied']);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('real', (string) file_get_contents($this->site . '/page.html'));
    }

    public function test_backup_failure_aborts_save(): void
    {
        $blocked = $this->storage . '/blocked';
        file_put_contents($blocked, 'iamafile');
        $original = (string) file_get_contents($this->site . '/page.html');

        try {
            $this->service(['storage_dir' => $blocked . '/sub'])->save('page.html', $this->currentSha(), [
                ['op' => 'text.set', 'target' => 'cms-aaaaaaaaaaaa', 'forward' => ['html' => 'never']],
            ]);
            $this->fail('expected StorageException');
        } catch (StorageException) {
            // expected — no backup, no save (fail-closed)
        }
        $this->assertSame($original, (string) file_get_contents($this->site . '/page.html'));
    }

    public function test_empty_and_oversized_batches_rejected(): void
    {
        try {
            $this->service()->save('page.html', $this->currentSha(), []);
            $this->fail('expected exception for empty ops');
        } catch (OperationValidationException) {
        }

        $ops = array_fill(0, 201, ['op' => 'node.delete', 'target' => 'cms-aaaaaaaaaaaa', 'forward' => []]);
        $this->expectException(OperationValidationException::class);
        $this->service()->save('page.html', $this->currentSha(), $ops);
    }
}
