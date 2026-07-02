<?php

declare(strict_types=1);

namespace EditFront\Tests\Reviews;

use EditFront\Reviews\ReviewException;
use EditFront\Reviews\ReviewStore;
use PHPUnit\Framework\TestCase;

final class ReviewStoreTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        $this->storage = ef2_temp_dir('reviews-store');
    }

    private function store(array $overrides = []): ReviewStore
    {
        return new ReviewStore(ef2_test_config(array_merge([
            'storage_dir' => $this->storage,
            'site_root'   => $this->storage,
        ], $overrides)));
    }

    private function item(array $overrides = []): array
    {
        return array_merge([
            'name'    => 'Александр',
            'country' => 'Эмираты',
            'text'    => 'Отличная компания, всем советую.',
            'status'  => 'pending',
            'source'  => 'Сайт',
        ], $overrides);
    }

    public function test_items_empty_when_no_file(): void
    {
        $this->assertSame([], $this->store()->items());
    }

    public function test_nextId_matches_pattern(): void
    {
        $this->assertMatchesRegularExpression('/^r-[0-9a-f]{8}$/', $this->store()->nextId());
    }

    public function test_upsert_creates_round_trips_and_defaults_year(): void
    {
        $store = $this->store();
        $stored = $store->upsert($this->item());

        $this->assertMatchesRegularExpression('/^r-[0-9a-f]{8}$/', $stored['id']);
        $this->assertSame('Александр', $stored['name']);
        $this->assertSame('Эмираты', $stored['country']);
        $this->assertSame('pending', $stored['status']);
        // year defaults to the creation year
        $this->assertMatchesRegularExpression('/^\d{4}$/', $stored['year']);

        $found = $store->find($stored['id']);
        $this->assertNotNull($found);
        $this->assertSame($stored['text'], $found['text']);
    }

    public function test_upsert_requires_name_and_text(): void
    {
        $this->expectException(ReviewException::class);
        $this->store()->upsert($this->item(['name' => '   ']));
    }

    public function test_upsert_requires_text(): void
    {
        $this->expectException(ReviewException::class);
        $this->store()->upsert($this->item(['text' => '']));
    }

    public function test_unknown_status_falls_back_to_pending(): void
    {
        $stored = $this->store()->upsert($this->item(['status' => 'bogus']));
        $this->assertSame('pending', $stored['status']);
    }

    public function test_explicit_year_preserved_when_four_digits(): void
    {
        $stored = $this->store()->upsert($this->item(['year' => '2024']));
        $this->assertSame('2024', $stored['year']);
        $bad = $this->store()->upsert($this->item(['year' => 'ab']));
        $this->assertMatchesRegularExpression('/^\d{4}$/', $bad['year']); // junk → default year
    }

    public function test_update_preserves_created_at(): void
    {
        $store = $this->store();
        $a = $store->upsert($this->item());
        $b = $store->upsert(['id' => $a['id']] + $this->item(['text' => 'Изменённый текст.']));
        $this->assertSame($a['id'], $b['id']);
        $this->assertSame($a['created_at'], $b['created_at']);
        $this->assertSame('Изменённый текст.', $b['text']);
    }

    public function test_text_is_stripped_of_tags(): void
    {
        $stored = $this->store()->upsert($this->item([
            'text' => 'Привет <script>alert(1)</script> мир',
        ]));
        $this->assertStringNotContainsString('<script>', $stored['text']);
        $this->assertStringContainsString('Привет', $stored['text']);
        $this->assertStringContainsString('мир', $stored['text']);
    }

    public function test_byStatus_filters(): void
    {
        $store = $this->store();
        $store->upsert($this->item(['status' => 'pending']));
        $store->upsert($this->item(['status' => 'approved']));
        $store->upsert($this->item(['status' => 'approved']));
        $this->assertCount(1, $store->byStatus('pending'));
        $this->assertCount(2, $store->byStatus('approved'));
        $this->assertCount(0, $store->byStatus('rejected'));
    }

    public function test_remove(): void
    {
        $store = $this->store();
        $a = $store->upsert($this->item());
        $this->assertTrue($store->remove($a['id']));
        $this->assertNull($store->find($a['id']));
        $this->assertFalse($store->remove('r-deadbeef'));
    }

    public function test_sortForOutput_is_created_at_desc(): void
    {
        $items = [
            ['id' => 'r-00000001', 'created_at' => '2026-01-01T00:00:00Z'],
            ['id' => 'r-00000002', 'created_at' => '2026-06-01T00:00:00Z'],
            ['id' => 'r-00000003', 'created_at' => '2026-03-01T00:00:00Z'],
        ];
        $sorted = ReviewStore::sortForOutput($items);
        $this->assertSame(['r-00000002', 'r-00000003', 'r-00000001'], array_column($sorted, 'id'));
    }

    public function test_new_item_honors_valid_provided_created_at(): void
    {
        $stored = $this->store()->upsert($this->item(['created_at' => '2024-10-29T00:00:00Z']));
        $this->assertSame('2024-10-29T00:00:00Z', $stored['created_at']);
        $this->assertSame('2024', $stored['year']); // year defaults from created_at
    }

    public function test_new_item_ignores_bogus_created_at(): void
    {
        $stored = $this->store()->upsert($this->item(['created_at' => 'yesterday']));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $stored['created_at']);
    }

    public function test_config_defaults_and_validation(): void
    {
        $store = $this->store();
        $this->assertSame('', $store->config()['notify_email']);

        $saved = $store->saveConfig(['notify_email' => 'a@b.com', 'notify_from' => 'c@d.com', 'site_label' => 'X']);
        $this->assertSame('a@b.com', $saved['notify_email']);
        $this->assertSame('X', $store->config()['site_label']);
    }

    public function test_config_rejects_bad_email(): void
    {
        $this->expectException(ReviewException::class);
        $this->store()->saveConfig(['notify_email' => 'not-an-email']);
    }

    public function test_upsert_takes_a_serializing_lock(): void
    {
        $store = $this->store();
        $store->upsert($this->item());
        // the read-modify-write runs under an exclusive lock on this sidecar file
        $this->assertFileExists($this->storage . '/reviews/items.json.lock');
    }

    public function test_many_sequential_upserts_all_persist(): void
    {
        $store = $this->store();
        for ($i = 0; $i < 25; $i++) {
            $store->upsert($this->item(['name' => 'Guest ' . $i]));
        }
        $this->assertCount(25, $store->items());
    }
}
