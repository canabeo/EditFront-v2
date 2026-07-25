<?php

declare(strict_types=1);

namespace EditFront\Tests\News;

use EditFront\News\NewsException;
use EditFront\News\NewsStore;
use EditFront\Support\Config;
use PHPUnit\Framework\TestCase;

final class NewsStoreTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        $this->storage = ef2_temp_dir('news-store');
    }

    private function store(array $overrides = []): NewsStore
    {
        return new NewsStore(ef2_test_config(array_merge([
            'storage_dir' => $this->storage,
            'site_root'   => $this->storage,
        ], $overrides)));
    }

    /** Minimal valid item input (store fills id/timestamps/defaults). */
    private function item(array $overrides = []): array
    {
        return array_merge([
            'slug'      => 'sample-news',
            'title'     => 'Sample news',
            'category'  => 'Company',
            'date'      => '2026-06-11',
            'cover'     => 'images/uploads/abc.jpg',
            'excerpt'   => 'Short summary.',
            'body_html' => '<p>Body.</p>',
            'published' => true,
        ], $overrides);
    }

    // ---- items.json --------------------------------------------------------

    public function test_items_is_empty_list_when_no_file(): void
    {
        $this->assertSame([], $this->store()->items());
    }

    public function test_nextId_matches_n_hex8_pattern(): void
    {
        $id = $this->store()->nextId();
        $this->assertMatchesRegularExpression('/^n-[0-9a-f]{8}$/', $id);
    }

    public function test_upsert_creates_and_round_trips(): void
    {
        $store  = $this->store();
        $stored = $store->upsert($this->item());

        $this->assertMatchesRegularExpression('/^n-[0-9a-f]{8}$/', $stored['id']);
        $this->assertSame('sample-news', $stored['slug']);
        $this->assertTrue($stored['published']);
        $this->assertNotSame('', $stored['created_at']);
        $this->assertSame($stored['created_at'], $stored['updated_at']);

        // re-read from a FRESH store instance => persisted to disk
        $fresh = $this->store();
        $this->assertCount(1, $fresh->items());
        $this->assertEquals($stored, $fresh->find($stored['id']));
    }

    public function test_upsert_defaults_title_short_and_cover_og(): void
    {
        $stored = $this->store()->upsert($this->item([
            'title'        => 'Full headline',
            'title_short'  => '',
            'cover'        => 'images/uploads/c.jpg',
            'cover_og'     => '',
        ]));

        $this->assertSame('Full headline', $stored['title_short']);
        $this->assertSame('images/uploads/c.jpg', $stored['cover_og']);
    }

    public function test_upsert_updates_existing_keeps_created_at(): void
    {
        $store = $this->store();
        $a     = $store->upsert($this->item(['title' => 'First']));

        // bump updated_at deterministically: re-upsert with same id, new title
        $b = $store->upsert(array_merge($a, ['title' => 'Edited']));

        $this->assertSame($a['id'], $b['id']);
        $this->assertSame('Edited', $b['title']);
        $this->assertSame($a['created_at'], $b['created_at']);
        $this->assertCount(1, $this->store()->items());
    }

    public function test_find_returns_null_for_unknown_id(): void
    {
        $this->assertNull($this->store()->find('n-deadbeef'));
    }

    public function test_remove_deletes_and_reports_bool(): void
    {
        $store = $this->store();
        $a     = $store->upsert($this->item());

        $this->assertTrue($store->remove($a['id']));
        $this->assertNull($this->store()->find($a['id']));
        $this->assertFalse($this->store()->remove($a['id']));
    }

    public function test_remove_keeps_empty_items_file_present(): void
    {
        $store = $this->store();
        $a     = $store->upsert($this->item());
        $store->remove($a['id']);

        // primary data: file stays, NOT unlinked like a sidecar
        $this->assertFileExists($this->storage . '/news/items.json');
        $this->assertSame([], $this->store()->items());
    }

    public function test_items_preserves_file_order_unsorted(): void
    {
        $store = $this->store();
        $store->upsert($this->item(['slug' => 'a', 'date' => '2026-01-01']));
        $store->upsert($this->item(['slug' => 'b', 'date' => '2026-12-31']));
        $store->upsert($this->item(['slug' => 'c', 'date' => '2026-06-15']));

        $slugs = array_column($this->store()->items(), 'slug');
        $this->assertSame(['a', 'b', 'c'], $slugs); // insertion order, NOT date-sorted
    }

    // ---- OUTPUT sort helper (reused by the plan-N3 Publisher) --------------

    public function test_sortForOutput_date_desc_then_created_at_desc(): void
    {
        $items = [
            ['slug' => 'old',  'date' => '2026-01-01', 'created_at' => '2026-01-01T00:00:00Z'],
            ['slug' => 'new',  'date' => '2026-12-31', 'created_at' => '2026-12-31T00:00:00Z'],
            ['slug' => 'mid',  'date' => '2026-06-15', 'created_at' => '2026-06-15T00:00:00Z'],
            // same date as 'mid' but created later => must sort ahead of 'mid'
            ['slug' => 'mid2', 'date' => '2026-06-15', 'created_at' => '2026-06-15T09:00:00Z'],
        ];

        $sorted = NewsStore::sortForOutput($items);
        $this->assertSame(['new', 'mid2', 'mid', 'old'], array_column($sorted, 'slug'));
    }

    // ---- config.json -------------------------------------------------------

    public function test_config_returns_defaults_without_writing(): void
    {
        $cfg = $this->store()->config();

        $this->assertSame('_news-template.html', $cfg['template_page']);
        $this->assertSame('', $cfg['title_suffix']);
        $this->assertSame('', $cfg['base_url']);
        $this->assertSame('ru', $cfg['date_locale']);

        // first read must NOT touch disk (seed is in-memory only)
        $this->assertFileDoesNotExist($this->storage . '/news/config.json');
    }

    public function test_config_base_url_falls_back_to_site_base_url(): void
    {
        $cfg = $this->store(['site_base_url' => 'https://example.com'])->config();
        $this->assertSame('https://example.com', $cfg['base_url']);
    }

    public function test_saveConfig_persists_and_merges_defaults(): void
    {
        $store  = $this->store();
        $stored = $store->saveConfig([
            'title_suffix' => ' — Новости компании',
            'base_url'     => 'https://example.com',
        ]);

        $this->assertSame('_news-template.html', $stored['template_page']); // default kept
        $this->assertSame(' — Новости компании', $stored['title_suffix']);
        $this->assertSame('https://example.com', $stored['base_url']);
        $this->assertSame('ru', $stored['date_locale']);

        $this->assertFileExists($this->storage . '/news/config.json');
        $this->assertEquals($stored, $this->store()->config());
    }

    public function test_saveConfig_rejects_non_string_template_page(): void
    {
        $this->expectException(NewsException::class);
        $this->store()->saveConfig(['template_page' => ['oops']]);
    }

    public function test_config_falls_back_to_defaults_on_corrupt_file(): void
    {
        // READ path is corruption-tolerant (mirrors items()): a corrupt or
        // schema-drifted config.json must degrade to defaults, never throw.
        $dir = $this->storage . '/news';
        @mkdir($dir, 0770, true);

        // valid JSON, but invalid values that validateConfig() would reject
        file_put_contents($dir . '/config.json', json_encode([
            'config' => ['title_suffix' => 123, 'base_url' => ['x']],
        ]));

        $cfg = $this->store()->config();

        $this->assertSame('_news-template.html', $cfg['template_page']);
        $this->assertSame('', $cfg['title_suffix']);
        $this->assertSame('', $cfg['base_url']);
        $this->assertSame('ru', $cfg['date_locale']);
    }
    public function test_upsert_rejects_missing_title(): void
    {
        $this->expectException(NewsException::class);
        $this->store()->upsert($this->item(['title' => '   ']));
    }

    public function test_upsert_rejects_bad_date_format(): void
    {
        $this->expectException(NewsException::class);
        $this->store()->upsert($this->item(['date' => '11.06.2026']));
    }

    // ---- gallery (Phase B) -------------------------------------------------

    public function test_gallery_defaults_to_empty_list_when_absent(): void
    {
        $stored = $this->store()->upsert($this->item());
        $this->assertSame([], $stored['gallery']);
        $this->assertSame('after', $stored['gallery_position']);
    }

    public function test_gallery_round_trips_safe_urls(): void
    {
        $urls   = [
            'https://example.com/images/uploads/a.webp',
            'http://example.com/images/uploads/b.webp',
            '/images/uploads/c.webp',
            'images/uploads/d.webp',
        ];
        $stored = $this->store()->upsert($this->item(['gallery' => $urls]));

        $this->assertSame($urls, $stored['gallery']);

        // persisted to disk verbatim, in order
        $fresh = $this->store()->find($stored['id']);
        $this->assertSame($urls, $fresh['gallery']);
    }

    public function test_gallery_drops_protocol_relative_and_dangerous_urls(): void
    {
        $stored = $this->store()->upsert($this->item(['gallery' => [
            '//evil.example/x.webp',                 // protocol-relative — dropped
            'javascript:alert(1)',                    // js scheme — dropped
            'data:image/png;base64,AAAA',             // data: — dropped
            'vbscript:msgbox(1)',                     // vbscript — dropped
            'https://example.com/ok.webp',             // safe — kept
            '/images/uploads/ok2.webp',               // safe relative — kept
        ]]));

        $this->assertSame([
            'https://example.com/ok.webp',
            '/images/uploads/ok2.webp',
        ], $stored['gallery']);
    }

    public function test_gallery_drops_non_string_and_blank_entries(): void
    {
        $stored = $this->store()->upsert($this->item(['gallery' => [
            'https://example.com/ok.webp',
            '',           // blank — dropped
            '   ',        // whitespace — dropped
            123,          // non-string — dropped
            ['x'],        // non-string — dropped
            null,         // non-string — dropped
        ]]));

        $this->assertSame(['https://example.com/ok.webp'], $stored['gallery']);
    }

    public function test_gallery_trims_entries(): void
    {
        $stored = $this->store()->upsert($this->item(['gallery' => [
            '  https://example.com/ok.webp  ',
        ]]));
        $this->assertSame(['https://example.com/ok.webp'], $stored['gallery']);
    }

    public function test_gallery_caps_count_at_50(): void
    {
        $many = [];
        for ($i = 0; $i < 60; $i++) {
            $many[] = 'https://example.com/img-' . $i . '.webp';
        }
        $stored = $this->store()->upsert($this->item(['gallery' => $many]));
        $this->assertCount(50, $stored['gallery']);
        // first 50 kept, in order
        $this->assertSame('https://example.com/img-0.webp', $stored['gallery'][0]);
        $this->assertSame('https://example.com/img-49.webp', $stored['gallery'][49]);
    }

    public function test_gallery_non_array_input_becomes_empty(): void
    {
        $stored = $this->store()->upsert($this->item(['gallery' => 'not-an-array']));
        $this->assertSame([], $stored['gallery']);
    }

    public function test_gallery_position_defaults_and_normalizes(): void
    {
        $before = $this->store()->upsert($this->item(['gallery_position' => 'before']));
        $this->assertSame('before', $before['gallery_position']);

        $after = $this->store()->upsert($this->item(['gallery_position' => 'after']));
        $this->assertSame('after', $after['gallery_position']);

        // unknown / junk normalizes to 'after'
        $junk = $this->store()->upsert($this->item(['gallery_position' => 'sideways']));
        $this->assertSame('after', $junk['gallery_position']);

        $missing = $this->store()->upsert($this->item());
        $this->assertSame('after', $missing['gallery_position']);

        $nonString = $this->store()->upsert($this->item(['gallery_position' => 42]));
        $this->assertSame('after', $nonString['gallery_position']);
    }
}
