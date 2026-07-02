<?php

declare(strict_types=1);

namespace EditFront\Tests\Draft;

use EditFront\Draft\DraftService;
use EditFront\Draft\UndoPayloadStore;
use EditFront\Storage\StorageException;
use PHPUnit\Framework\TestCase;

final class DraftServiceTest extends TestCase
{
    private string $storage;
    private DraftService $drafts;
    private UndoPayloadStore $payloads;

    protected function setUp(): void
    {
        $this->storage = ef2_temp_dir('draft');
        $config = ef2_test_config(['storage_dir' => $this->storage]);
        $this->payloads = new UndoPayloadStore($config);
        $this->drafts = new DraftService($config, $this->payloads);
    }

    private function validDraft(array $overrides = []): array
    {
        return array_merge([
            'base_sha256' => str_repeat('ab', 32),
            'serial' => 7,
            'cursor' => 1,
            'entries' => [
                ['id' => 'cmd-aabbccdd', 'kind' => 'text.set', 'nodeId' => 'cms-aaaaaaaaaaaa',
                 'ts' => 1, 'forward' => ['html' => 'x'], 'undo' => ['html' => 'y']],
                ['id' => 'cmd-eeff0011', 'kind' => 'node.delete', 'nodeId' => 'cms-bbbbbbbbbbbb',
                 'ts' => 2, 'forward' => [], 'undo' => ['html' => '<p>z</p>', 'parentId' => null, 'index' => 0]],
            ],
        ], $overrides);
    }

    public function test_save_load_roundtrip_is_the_log_itself(): void
    {
        $this->drafts->save('page.html', $this->validDraft());
        $loaded = $this->drafts->load('page.html');

        $this->assertNotNull($loaded);
        $this->assertSame('page.html', $loaded['page']);
        $this->assertSame(1, $loaded['cursor']);
        $this->assertSame(7, $loaded['serial']);
        $this->assertCount(2, $loaded['entries']);
        // entries + cursor — undo/redo/ветвление переживают reload (§7.3)
        $this->assertSame('node.delete', $loaded['entries'][1]['kind']);
        $this->assertSame('<p>z</p>', $loaded['entries'][1]['undo']['html']);
    }

    public function test_load_missing_returns_null(): void
    {
        $this->assertNull($this->drafts->load('nope.html'));
    }

    public function test_malformed_drafts_rejected(): void
    {
        foreach ([
            ['cursor' => 5],                          // cursor > entries
            ['cursor' => -1],
            ['base_sha256' => 'short'],
            ['entries' => 'not-a-list'],
            ['entries' => ['k' => 'v']],
        ] as $broken) {
            try {
                $this->drafts->save('page.html', $this->validDraft($broken));
                $this->fail('expected reject: ' . json_encode($broken));
            } catch (StorageException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_oversized_draft_rejected(): void
    {
        $big = $this->validDraft();
        $big['entries'][0]['forward']['html'] = str_repeat('x', 3 * 1024 * 1024);
        $this->expectException(StorageException::class);
        $this->drafts->save('page.html', $big);
    }

    public function test_delete_clears_undo_payloads_too(): void
    {
        $this->drafts->save('page.html', $this->validDraft());
        $this->payloads->save('page.html', 'cmd-aabbccdd', '{"forward":{},"undo":{}}');

        $this->drafts->delete('page.html');

        $this->assertNull($this->drafts->load('page.html'));
        $this->assertNull($this->payloads->load('page.html', 'cmd-aabbccdd'));
    }

    public function test_expired_draft_is_removed_on_load(): void
    {
        $this->drafts->save('page.html', $this->validDraft());
        $file = $this->storage . '/drafts/' . sha1('page.html') . '.json';
        $decoded = json_decode((string) file_get_contents($file), true);
        $decoded['updated_at'] = time() - 31 * 86400;
        file_put_contents($file, json_encode($decoded));

        $this->assertNull($this->drafts->load('page.html'));
        $this->assertFileDoesNotExist($file);
    }
}
