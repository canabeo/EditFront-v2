<?php

declare(strict_types=1);

namespace EditFront\Tests\Reviews;

use EditFront\Document\Html5;
use EditFront\Reviews\ReviewException;
use EditFront\Reviews\ReviewPublisher;
use EditFront\Reviews\ReviewRenderer;
use EditFront\Reviews\ReviewStore;
use EditFront\Storage\BackupService;
use EditFront\Storage\FileStorage;
use EditFront\Storage\PagesIndex;
use EditFront\Storage\PathGuard;
use EditFront\Support\Config;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ReviewPublisherTest extends TestCase
{
    private string $site;
    private string $storage;
    private Config $config;
    private ReviewStore $store;

    protected function setUp(): void
    {
        $this->site = ef2_temp_dir('reviews-site');
        $this->storage = ef2_temp_dir('reviews-store');
        $this->config = ef2_test_config([
            'site_root' => $this->site,
            'storage_dir' => $this->storage,
        ]);
        $this->store = new ReviewStore($this->config);
    }

    private function publisher(): ReviewPublisher
    {
        $html5 = new Html5();
        $storage = new FileStorage($this->config, new PathGuard($this->config));
        return new ReviewPublisher(
            $this->store,
            new ReviewRenderer(),
            $storage,
            new BackupService($this->config),
            new PagesIndex($this->config),
            $html5,
            new NullLogger(),
        );
    }

    private function putSite(string $rel, string $html): void
    {
        $path = $this->site . '/' . $rel;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $html);
    }

    private function readSite(string $rel): string
    {
        return (string) file_get_contents($this->site . '/' . $rel);
    }

    private function seedSite(): void
    {
        $this->putSite('index.html', '<!doctype html><html><head><title>home</title></head><body>'
            . '<section id="reviews"><div class="reviews" id="revGrid" data-reviews-list="home" data-reviews-limit="3"></div></section>'
            . '</body></html>');
        $this->putSite('reviews.html', '<!doctype html><html><head><title>reviews</title></head><body>'
            . '<div class="reviews-page" id="reviewsPage" data-reviews-list="page"></div>'
            . '</body></html>');
        // a page with no review container — must never be rewritten
        $this->putSite('about.html', '<!doctype html><html><head><title>about</title></head><body><p>about</p></body></html>');
    }

    private function seedReviews(int $approved, int $pending = 0): void
    {
        for ($i = 0; $i < $approved; $i++) {
            $this->store->upsert([
                'name' => 'A' . $i, 'country' => 'Эмираты', 'text' => 'Approved ' . $i,
                'status' => 'approved', 'source' => 'Сайт',
            ]);
        }
        for ($i = 0; $i < $pending; $i++) {
            $this->store->upsert([
                'name' => 'P' . $i, 'country' => 'Куба', 'text' => 'Pending ' . $i,
                'status' => 'pending', 'source' => 'Сайт',
            ]);
        }
    }

    public function test_republish_fills_containers_and_limits_home(): void
    {
        $this->seedSite();
        $this->seedReviews(approved: 5, pending: 2);

        $updated = $this->publisher()->republish();
        $this->assertSame(2, $updated); // index.html + reviews.html (not about.html)

        $home = $this->readSite('index.html');
        $page = $this->readSite('reviews.html');

        // home limit honoured (3), page shows all 5; pending excluded everywhere
        $this->assertSame(3, substr_count($home, 'class="rev"'));
        $this->assertSame(5, substr_count($page, 'class="rev"'));

        // mode-correct skins
        $this->assertStringContainsString('class="mark"', $home);
        $this->assertStringContainsString('class="badge"', $page);

        // pending text never reaches the site
        $this->assertStringNotContainsString('Pending ', $home);
        $this->assertStringNotContainsString('Pending ', $page);
    }

    public function test_authoring_markers_replaced_with_render_hooks(): void
    {
        $this->seedSite();
        $this->seedReviews(approved: 1);
        $this->publisher()->republish();

        $home = $this->readSite('index.html');
        $page = $this->readSite('reviews.html');

        // no authoring marker survives
        $this->assertStringNotContainsString('data-reviews-list', $home);
        $this->assertStringNotContainsString('data-reviews-list', $page);
        // persistent re-find hooks present
        $this->assertStringContainsString('data-reviews-rendered="home"', $home);
        $this->assertStringContainsString('data-reviews-rendered="page"', $page);
        $this->assertStringContainsString('data-reviews-rendered-limit="3"', $home);
    }

    public function test_republish_is_idempotent(): void
    {
        $this->seedSite();
        $this->seedReviews(approved: 2);
        $pub = $this->publisher();
        $pub->republish();
        $first = $this->readSite('reviews.html');
        $pub->republish();
        $second = $this->readSite('reviews.html');
        $this->assertSame($first, $second);
        $this->assertSame(2, substr_count($second, 'class="rev"'));
    }

    public function test_about_page_untouched(): void
    {
        $this->seedSite();
        $this->seedReviews(approved: 1);
        $before = $this->readSite('about.html');
        $this->publisher()->republish();
        $this->assertSame($before, $this->readSite('about.html'));
    }

    public function test_submit_stores_pending_and_does_not_render(): void
    {
        $this->seedSite();
        $pub = $this->publisher();
        $res = $pub->submit(['name' => 'Гость', 'country' => 'Турция', 'text' => 'Спасибо!']);
        $this->assertMatchesRegularExpression('/^r-[0-9a-f]{8}$/', $res['id']);

        $stored = $this->store->find($res['id']);
        $this->assertSame('pending', $stored['status']);

        // submit must NOT re-render — the authoring marker is still intact
        $this->assertStringContainsString('data-reviews-list', $this->readSite('reviews.html'));
        $this->assertSame(0, substr_count($this->readSite('reviews.html'), 'class="rev"'));
    }

    public function test_approve_then_reject_makes_card_appear_then_disappear(): void
    {
        $this->seedSite();
        $id = $this->store->upsert([
            'name' => 'Мария', 'country' => 'Греция', 'text' => 'Чудесно!', 'status' => 'pending',
        ])['id'];
        $pub = $this->publisher();

        $pub->approve($id);
        $this->assertSame(1, substr_count($this->readSite('reviews.html'), 'class="rev"'));
        $this->assertStringContainsString('Чудесно!', $this->readSite('reviews.html'));

        $pub->reject($id);
        $this->assertSame(0, substr_count($this->readSite('reviews.html'), 'class="rev"'));
        $this->assertStringNotContainsString('Чудесно!', $this->readSite('reviews.html'));
    }

    public function test_unpublish_returns_approved_to_pending(): void
    {
        $this->seedSite();
        $id = $this->store->upsert(['name' => 'М', 'country' => 'X', 'text' => 'visibleONsite', 'status' => 'approved'])['id'];
        $pub = $this->publisher();
        $pub->republish();
        $this->assertStringContainsString('visibleONsite', $this->readSite('reviews.html'));

        $res = $pub->unpublish($id);
        $this->assertSame('pending', $res['status']);
        $this->assertSame('pending', $this->store->find($id)['status']);
        $this->assertStringNotContainsString('visibleONsite', $this->readSite('reviews.html'));
    }

    public function test_manual_save_defaults_to_approved_and_renders(): void
    {
        $this->seedSite();
        $pub = $this->publisher();
        $res = $pub->save(['name' => 'Пётр', 'country' => 'Италия', 'text' => 'Из другого источника']);
        $this->assertSame('approved', $res['status']);
        $this->assertStringContainsString('Из другого источника', $this->readSite('reviews.html'));
    }

    public function test_delete_removes_and_unrenders(): void
    {
        $this->seedSite();
        $id = $this->store->upsert(['name' => 'X', 'country' => 'Y', 'text' => 'ToDelete', 'status' => 'approved'])['id'];
        $pub = $this->publisher();
        $pub->republish();
        $this->assertStringContainsString('ToDelete', $this->readSite('reviews.html'));

        $pub->delete($id);
        $this->assertNull($this->store->find($id));
        $this->assertStringNotContainsString('ToDelete', $this->readSite('reviews.html'));
    }

    public function test_moderate_missing_id_throws(): void
    {
        $this->expectException(ReviewException::class);
        $this->publisher()->approve('r-deadbeef');
    }
}
