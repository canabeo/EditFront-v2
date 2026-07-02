<?php

declare(strict_types=1);

namespace EditFront\Tests\Draft;

use EditFront\Draft\UndoPayloadStore;
use EditFront\Storage\StorageException;
use PHPUnit\Framework\TestCase;

final class UndoPayloadStoreTest extends TestCase
{
    private string $storage;
    private UndoPayloadStore $store;

    protected function setUp(): void
    {
        $this->storage = ef2_temp_dir('undopayload');
        $this->store = new UndoPayloadStore(ef2_test_config(['storage_dir' => $this->storage]));
    }

    public function test_save_load_roundtrip(): void
    {
        $payload = json_encode(['forward' => ['html' => str_repeat('big ', 100)], 'undo' => ['html' => 'old']]);
        $this->store->save('page.html', 'cmd-aabbccdd', (string) $payload);
        $this->assertSame($payload, $this->store->load('page.html', 'cmd-aabbccdd'));
    }

    public function test_payload_is_gzipped_on_disk(): void
    {
        $this->store->save('page.html', 'cmd-aabbccdd', str_repeat('compressible ', 1000));
        $file = $this->storage . '/undo/' . sha1('page.html') . '/cmd-aabbccdd.json.gz';
        $this->assertFileExists($file);
        $this->assertLessThan(1000, (int) filesize($file));
    }

    public function test_load_missing_returns_null(): void
    {
        $this->assertNull($this->store->load('page.html', 'cmd-aabbccdd'));
    }

    public function test_invalid_cmd_id_rejected(): void
    {
        foreach (['../escape', 'cmd-XYZ', 'cmd-', 'whatever', 'cmd-' . str_repeat('a', 40)] as $bad) {
            try {
                $this->store->save('page.html', $bad, '{}');
                $this->fail('expected reject: ' . $bad);
            } catch (StorageException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_oversized_payload_rejected(): void
    {
        $this->expectException(StorageException::class);
        $this->store->save('page.html', 'cmd-aabbccdd', str_repeat('x', 6 * 1024 * 1024));
    }

    public function test_clear_removes_all_page_payloads(): void
    {
        $this->store->save('page.html', 'cmd-aabbccdd', '{"a":1}');
        $this->store->save('page.html', 'cmd-eeff0011', '{"b":2}');
        $this->store->save('other.html', 'cmd-aabbccdd', '{"c":3}');

        $this->store->clear('page.html');

        $this->assertNull($this->store->load('page.html', 'cmd-aabbccdd'));
        $this->assertNull($this->store->load('page.html', 'cmd-eeff0011'));
        $this->assertNotNull($this->store->load('other.html', 'cmd-aabbccdd'));
    }
}
