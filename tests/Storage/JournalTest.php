<?php

declare(strict_types=1);

namespace EditFront\Tests\Storage;

use EditFront\Storage\Journal;
use PHPUnit\Framework\TestCase;

final class JournalTest extends TestCase
{
    private string $storage;
    private Journal $journal;

    protected function setUp(): void
    {
        $this->storage = ef2_temp_dir('journal');
        $this->journal = new Journal(ef2_test_config(['storage_dir' => $this->storage]));
    }

    private function file(): string
    {
        return $this->storage . '/journal/' . sha1('page.html') . '.jsonl';
    }

    public function test_appends_batch_marker_and_ops(): void
    {
        $this->journal->append('page.html', [
            ['op' => 'text.set', 'target' => 'cms-aaaaaaaaaaaa', 'forward' => ['html' => 'x']],
            ['op' => 'node.delete', 'target' => 'cms-bbbbbbbbbbbb', 'forward' => []],
        ], 'batch01');

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->file()))));
        $this->assertCount(3, $lines);

        $marker = json_decode($lines[0], true);
        $this->assertSame('batch01', $marker['_batch']);
        $this->assertSame('page.html', $marker['page']);

        $op1 = json_decode($lines[1], true);
        $this->assertSame('text.set', $op1['op']);
    }

    public function test_appends_accumulate(): void
    {
        $this->journal->append('page.html', [['op' => 'a', 'forward' => []]], 'b1');
        $this->journal->append('page.html', [['op' => 'b', 'forward' => []]], 'b2');
        $content = (string) file_get_contents($this->file());
        $this->assertStringContainsString('"b1"', $content);
        $this->assertStringContainsString('"b2"', $content);
    }

    public function test_rotation_over_limit(): void
    {
        @mkdir(dirname($this->file()), 0777, true);
        file_put_contents($this->file(), str_repeat("{\"op\":\"x\"}\n", 1_000_000)); // ~12 MB

        $this->journal->append('page.html', [['op' => 'fresh', 'forward' => []]], 'b3');

        $archives = glob($this->storage . '/journal/*.jsonl.gz') ?: [];
        $this->assertCount(1, $archives);
        $fresh = (string) file_get_contents($this->file());
        $this->assertLessThan(1024, strlen($fresh));
        $this->assertStringContainsString('fresh', $fresh);
    }
}
