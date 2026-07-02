<?php

declare(strict_types=1);

namespace EditFront\Tests\News;

use EditFront\Document\Annotator;
use EditFront\Document\Html5;
use EditFront\News\NewsBodySanitizer;
use EditFront\News\NewsException;
use EditFront\News\NewsPublisher;
use EditFront\News\NewsRenderer;
use EditFront\News\NewsSlug;
use EditFront\News\NewsStore;
use EditFront\News\NewsTemplate;
use EditFront\Security\SanitizerCss;
use EditFront\Security\SanitizerHtml;
use EditFront\Security\SanitizerUrl;
use EditFront\Storage\BackupService;
use EditFront\Storage\FileStorage;
use EditFront\Storage\PagesIndex;
use EditFront\Storage\PathGuard;
use EditFront\Support\Config;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class NewsPublisherTest extends TestCase
{
    private string $site;     // site_root (the user's static site)
    private string $storage;  // storage_dir (sidecars + backups + news/*.json)
    private Config $config;

    protected function setUp(): void
    {
        $this->site = ef2_temp_dir('news-site');
        $this->storage = ef2_temp_dir('news-store');
        $this->config = ef2_test_config([
            'site_root' => $this->site,
            'storage_dir' => $this->storage,
        ]);
    }

    /**
     * Build the whole News stack against the temp config.
     * Pure autowiring is mirrored by hand here (tests don't use the container).
     */
    private function publisher(): NewsPublisher
    {
        $html5 = new Html5();
        $guard = new PathGuard($this->config);
        $storage = new FileStorage($this->config, $guard);
        $annotator = new Annotator();
        $backups = new BackupService($this->config);
        $pages = new PagesIndex($this->config);
        $store = new NewsStore($this->config);
        $slug = new NewsSlug($pages);
        $template = new NewsTemplate($store, $storage, $html5);
        $renderer = new NewsRenderer($template, $annotator, $html5, $store);
        $sanitizer = new NewsBodySanitizer(new SanitizerHtml(new SanitizerUrl(), new SanitizerCss()), new SanitizerUrl());

        return new NewsPublisher(
            $store,
            $renderer,
            $template,
            $slug,
            $storage,
            $backups,
            $pages,
            $html5,
            $annotator,
            $sanitizer,
            new NullLogger(),
        );
    }

    /** Write a file directly under site_root (bypassing FileStorage, for fixtures). */
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

    private function siteExists(string $rel): bool
    {
        return is_file($this->site . '/' . $rel);
    }

    /** A minimal but slot-complete article template + embedded card. */
    private function fullTemplateHtml(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="ru" data-news-template>
<head>
  <title data-nf="title_tag">тут заголовок</title>
  <meta name="description" data-nf="excerpt" content="">
  <link rel="canonical" data-nf="url" href="">
  <meta property="og:url" data-nf="url" content="">
  <meta property="og:title" data-nf="title_tag" content="">
  <meta property="og:description" data-nf="excerpt" content="">
  <meta property="og:image" data-nf="cover_og" content="">
  <meta name="twitter:title" data-nf="title_tag" content="">
  <meta name="twitter:description" data-nf="excerpt" content="">
  <meta name="twitter:image" data-nf="cover_og" content="">
  <script type="application/ld+json" data-nf="jsonld">{}</script>
</head>
<body>
  <nav class="topnav">FIXED CHROME</nav>
  <div class="crumbs"><span class="cur" data-nf="title_short">крошка</span></div>
  <div class="page-head">
    <span class="tag-pill" data-nf="meta_line">метастрока</span>
    <h1 data-nf="title">H1</h1>
  </div>
  <div class="article-cover"><img data-nf="cover" src="" alt=""></div>
  <div class="article-layout">
    <article class="article" data-nf="body"><p>placeholder</p></article>
    <aside>
      <div class="aside-card">
        <h4>Нужна помощь с туром?</h4>
        <a class="btn btn-white" href="/contacts">Оставить заявку</a>
      </div>
    </aside>
  </div>
  <template data-news-card>
    <a class="post" data-nf="url" href="">
      <span class="media"><img data-nf="cover" src="" alt=""></span>
      <span class="date" data-nf="meta_line"></span>
      <h3 data-nf="title_short"></h3>
    </a>
  </template>
  <footer class="ft">FIXED FOOTER</footer>
</body>
</html>
HTML;
    }

    /** A template that is MISSING the required `body` slot → must fail validate(). */
    private function brokenTemplateHtml(): string
    {
        $full = $this->fullTemplateHtml();
        // strip the body slot marker so validate() must throw
        return str_replace(' data-nf="body"', '', $full);
    }

    private function sampleItem(array $overrides = []): array
    {
        return array_merge([
            'title' => 'График работы в День России',
            'title_short' => '',
            'category' => 'Компания',
            'date' => '2026-06-11',
            'cover' => 'images/uploads/abc123def456abcd.jpg',
            'cover_og' => '',
            'excerpt' => 'Краткое описание новости для meta description.',
            'body_html' => '<p class="lead">Тело статьи.</p><h2>Раздел</h2><p>Текст.</p>',
            'published' => true,
        ], $overrides);
    }

    // ---------------------------------------------------------------------
    // N3.1 — template validated BEFORE any write
    // ---------------------------------------------------------------------

    public function test_save_throws_and_writes_nothing_when_template_slot_missing(): void
    {
        // template page exists but is missing the required `body` slot
        $this->putSite('_news-template.html', $this->brokenTemplateHtml());

        $publisher = $this->publisher();

        try {
            $publisher->save($this->sampleItem());
            $this->fail('expected NewsException for missing template slot');
        } catch (NewsException $e) {
            $this->assertStringContainsStringIgnoringCase('body', $e->getMessage());
        }

        // NOTHING written: no article page, no items.json, no backups
        $this->assertSame(
            ['_news-template.html'],
            $this->siteFiles(),
            'no article page must be created when the template is invalid',
        );
        $this->assertFileDoesNotExist(
            $this->storage . '/news/items.json',
            'store must not be written when the template is invalid',
        );
        $this->assertDirectoryDoesNotExist(
            $this->storage . '/backups',
            'no backup may be taken when the template is invalid',
        );
    }

    /** @return list<string> relative paths of *.html under site_root, sorted */
    private function siteFiles(): array
    {
        $out = [];
        $base = rtrim($this->site, '/') . '/';
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->site, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $out[] = substr($f->getPathname(), strlen($base));
            }
        }
        sort($out);
        return $out;
    }

    // ---------------------------------------------------------------------
    // N3.3 — new article: createNew, no preSave backup, markers stripped
    // ---------------------------------------------------------------------

    public function test_save_new_article_creates_clean_page_and_stores_item(): void
    {
        $this->putSite('_news-template.html', $this->fullTemplateHtml());

        $result = $this->publisher()->save($this->sampleItem());

        // Returned shape.
        $this->assertMatchesRegularExpression('/^n-[0-9a-f]{8}$/', $result['id']);
        $this->assertSame($result['slug'] . '.html', $result['page']);
        $this->assertNull($result['backup_id'], 'brand-new article must not take a pre-save backup');

        // The article page exists and is clean.
        $page = $result['page'];
        $this->assertTrue($this->siteExists($page));
        $article = $this->readSite($page);

        // All marker attributes stripped from the published page.
        foreach (['data-nf', 'data-nf-attr', 'data-news-template', 'data-news-card', 'data-news-list', 'data-news-limit'] as $marker) {
            $this->assertStringNotContainsString($marker, $article, "marker '$marker' must be stripped");
        }

        // Filled slots present.
        $this->assertStringContainsString('График работы в День России', $article);
        $this->assertStringContainsString('FIXED CHROME', $article, 'unmarked chrome must survive');
        $this->assertStringContainsString('FIXED FOOTER', $article);

        // ids stamped for later in-CMS editing.
        $this->assertStringContainsString('data-cms-id="cms-', $article);

        // Store has the item.
        $stored = (new NewsStore($this->config))->find($result['id']);
        $this->assertNotNull($stored);
        $this->assertSame($result['slug'], $stored['slug']);
        $this->assertStringContainsString('<h2>', $stored['body_html'], 'body_html persisted sanitized');
    }

    // ---------------------------------------------------------------------
    // N3.4 — list refresh: count, limit, newest-first, markers stripped, backups
    // ---------------------------------------------------------------------

    /** A homepage grid: latest, capped at 3. */
    private function homepageHtml(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="ru">
<head><title>Главная</title></head>
<body>
  <nav>home chrome</nav>
  <section id="newsGrid" class="news-grid" data-news-list="latest" data-news-limit="3"></section>
</body>
</html>
HTML;
    }

    /** A listing page: all, unlimited. */
    private function listingHtml(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="ru">
<head><title>Новости</title></head>
<body>
  <nav>listing chrome</nav>
  <div class="news" data-news-list="all"></div>
</body>
</html>
HTML;
    }

    private function publishThree(): NewsPublisher
    {
        $this->putSite('_news-template.html', $this->fullTemplateHtml());
        $this->putSite('index.html', $this->homepageHtml());
        $this->putSite('news.html', $this->listingHtml());

        $pub = $this->publisher();
        // Publish oldest → newest so we can assert ordering is by date, not insert order.
        $pub->save($this->sampleItem(['title' => 'Старая новость', 'date' => '2026-06-01']));
        $pub->save($this->sampleItem(['title' => 'Средняя новость', 'date' => '2026-06-05']));
        $pub->save($this->sampleItem(['title' => 'Новейшая новость', 'date' => '2026-06-11']));
        return $pub;
    }

    private function cardCount(string $rel): int
    {
        $doc = (new Html5())->parse($this->readSite($rel));
        $xpath = new \DOMXPath($doc);
        return $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' post ')]")->length;
    }

    /** @return list<string> the h3 text of each card in document order */
    private function cardTitles(string $rel): array
    {
        $doc = (new Html5())->parse($this->readSite($rel));
        $out = [];
        foreach ($doc->getElementsByTagName('h3') as $h3) {
            $out[] = trim($h3->textContent);
        }
        return $out;
    }

    public function test_listing_container_gets_all_cards_newest_first(): void
    {
        $this->publishThree();

        // 'all' ⇒ all three, newest first.
        $this->assertSame(3, $this->cardCount('news.html'));
        $this->assertSame(
            ['Новейшая новость', 'Средняя новость', 'Старая новость'],
            $this->cardTitles('news.html'),
        );

        // list markers stripped.
        $html = $this->readSite('news.html');
        $this->assertStringNotContainsString('data-news-list', $html);
        $this->assertStringNotContainsString('data-news-limit', $html);
    }

    public function test_homepage_container_respects_limit_three(): void
    {
        // publish FOUR so the limit (3) actually clips one.
        $this->putSite('_news-template.html', $this->fullTemplateHtml());
        $this->putSite('index.html', $this->homepageHtml());

        $pub = $this->publisher();
        $pub->save($this->sampleItem(['title' => 'A', 'date' => '2026-06-01']));
        $pub->save($this->sampleItem(['title' => 'B', 'date' => '2026-06-05']));
        $pub->save($this->sampleItem(['title' => 'C', 'date' => '2026-06-08']));
        $pub->save($this->sampleItem(['title' => 'D', 'date' => '2026-06-11']));

        // limit=3 ⇒ exactly the 3 newest.
        $this->assertSame(3, $this->cardCount('index.html'));
        $this->assertSame(['D', 'C', 'B'], $this->cardTitles('index.html'));
    }

    // ---------------------------------------------------------------------
    // Fix 2 — the `published` flag gates card-visibility. An unpublished
    // item must NOT appear as a card on the home `latest` or the `all`
    // listing; a missing/true published is treated as published.
    // ---------------------------------------------------------------------

    public function test_unpublished_item_card_is_excluded_from_lists(): void
    {
        $this->putSite('_news-template.html', $this->fullTemplateHtml());
        $this->putSite('index.html', $this->homepageHtml());
        $this->putSite('news.html', $this->listingHtml());

        $pub = $this->publisher();
        // Newest by date is the DRAFT — proves the filter, not just the order.
        $pub->save($this->sampleItem(['title' => 'Опубликованная', 'date' => '2026-06-01', 'published' => true]));
        $pub->save($this->sampleItem(['title' => 'Черновик', 'date' => '2026-06-11', 'published' => false]));

        // Only the published card appears in the 'all' listing.
        $this->assertSame(1, $this->cardCount('news.html'));
        $this->assertSame(['Опубликованная'], $this->cardTitles('news.html'));

        // …and in the home 'latest' container.
        $this->assertSame(1, $this->cardCount('index.html'));
        $this->assertSame(['Опубликованная'], $this->cardTitles('index.html'));

        // The draft's article PAGE is still written (previewable by direct URL),
        // just unlisted.
        $draft = (new NewsStore($this->config))->items();
        $draftSlug = null;
        foreach ($draft as $it) {
            if (($it['title'] ?? '') === 'Черновик') {
                $draftSlug = (string) $it['slug'];
            }
        }
        $this->assertNotNull($draftSlug, 'draft item must still be stored');
        $this->assertTrue($this->siteExists($draftSlug . '.html'), 'draft article page is still written (unlisted, not deleted)');
    }

    public function test_published_ordering_and_limit_hold_with_a_draft_present(): void
    {
        // 3 published + 1 draft mixed by date; the home limit=3 + newest-first
        // ordering must still hold over the PUBLISHED subset only.
        $this->putSite('_news-template.html', $this->fullTemplateHtml());
        $this->putSite('index.html', $this->homepageHtml());
        $this->putSite('news.html', $this->listingHtml());

        $pub = $this->publisher();
        $pub->save($this->sampleItem(['title' => 'P1', 'date' => '2026-06-01', 'published' => true]));
        $pub->save($this->sampleItem(['title' => 'DRAFT', 'date' => '2026-06-07', 'published' => false]));
        $pub->save($this->sampleItem(['title' => 'P2', 'date' => '2026-06-05', 'published' => true]));
        $pub->save($this->sampleItem(['title' => 'P3', 'date' => '2026-06-11', 'published' => true]));

        // 'all' listing: 3 published, newest-first, draft excluded.
        $this->assertSame(3, $this->cardCount('news.html'));
        $this->assertSame(['P3', 'P2', 'P1'], $this->cardTitles('news.html'));

        // home 'latest' limit=3: the 3 newest PUBLISHED (draft never counted).
        $this->assertSame(3, $this->cardCount('index.html'));
        $this->assertSame(['P3', 'P2', 'P1'], $this->cardTitles('index.html'));
    }

    public function test_legacy_item_without_published_key_is_treated_as_published(): void
    {
        // Defensive filter clause: only published === false is excluded, so a
        // legacy/hand-edited items.json entry that lacks the `published` key at
        // all must still be listed. We seed items.json directly (a save would
        // normalize the missing flag to false) to exercise exactly that path.
        $this->putSite('_news-template.html', $this->fullTemplateHtml());
        $this->putSite('news.html', $this->listingHtml());

        // Seed a legacy item with NO `published` key, then publish a normal one
        // to trigger a list refresh that must include both.
        $legacy = [
            'id' => 'n-deadbe01',
            'slug' => 'legacy-no-flag',
            'title' => 'Легаси без флага',
            'title_short' => 'Легаси',
            'category' => 'Компания',
            'date' => '2026-06-15',
            'cover' => '',
            'cover_og' => '',
            'excerpt' => '',
            'body_html' => '<p>legacy</p>',
            // intentionally NO 'published' key
            'created_at' => '2026-06-15T00:00:00Z',
            'updated_at' => '2026-06-15T00:00:00Z',
        ];
        $newsDir = $this->storage . '/news';
        if (!is_dir($newsDir)) {
            mkdir($newsDir, 0775, true);
        }
        file_put_contents($newsDir . '/items.json', json_encode(['items' => [$legacy]]));

        $pub = $this->publisher();
        $pub->save($this->sampleItem(['title' => 'Обычная', 'date' => '2026-06-11', 'published' => true]));

        // Both the legacy (no flag) and the normal published item are listed.
        $this->assertSame(2, $this->cardCount('news.html'));
        $this->assertSame(['Легаси', 'Обычная'], $this->cardTitles('news.html'));
    }

    public function test_each_rewritten_list_page_took_a_presave_backup(): void
    {
        $this->publishThree();

        // index.html and news.html were each rewritten ⇒ each has ≥1 pre-save backup.
        $backups = new BackupService($this->config);
        $this->assertNotEmpty(
            $backups->listForPage('index.html'),
            'homepage rewrite must be preceded by a pre-save backup',
        );
        $this->assertNotEmpty(
            $backups->listForPage('news.html'),
            'listing rewrite must be preceded by a pre-save backup',
        );
    }

    public function test_save_reports_lists_updated_count(): void
    {
        $this->putSite('_news-template.html', $this->fullTemplateHtml());
        $this->putSite('index.html', $this->homepageHtml());
        $this->putSite('news.html', $this->listingHtml());

        $result = $this->publisher()->save($this->sampleItem());

        // Two list pages (index + news), template skipped.
        $this->assertSame(2, $result['lists_updated']);
    }

    public function test_no_news_marker_survives_in_published_list_page(): void
    {
        $this->publishThree();

        // Cross-check: NONE of the six markers may survive anywhere in a fully
        // published list page (the article-strip and the list-strip are two
        // separate code paths — this guards both at once).
        $html = $this->readSite('news.html');
        foreach (['data-nf', 'data-nf-attr', 'data-news-template', 'data-news-card', 'data-news-list', 'data-news-limit'] as $marker) {
            $this->assertStringNotContainsString($marker, $html, "marker '$marker' must not survive in a published list page");
        }
    }

    public function test_template_page_is_never_treated_as_a_list_page(): void
    {
        $this->putSite('_news-template.html', $this->fullTemplateHtml());

        // Only the template exists — no list containers anywhere.
        $result = $this->publisher()->save($this->sampleItem());

        $this->assertSame(0, $result['lists_updated']);
        // The template page itself must remain unmodified (still has its markers).
        $tpl = $this->readSite('_news-template.html');
        $this->assertStringContainsString('data-news-template', $tpl);
        $this->assertStringContainsString('data-news-card', $tpl);
    }

    // ---------------------------------------------------------------------
    // N3.5 — delete: page removed (backed up + sidecars), store cleaned, cards refreshed
    // ---------------------------------------------------------------------

    public function test_delete_removes_article_page_and_its_card_from_lists(): void
    {
        $this->putSite('_news-template.html', $this->fullTemplateHtml());
        $this->putSite('index.html', $this->homepageHtml());
        $this->putSite('news.html', $this->listingHtml());

        $pub = $this->publisher();
        $a = $pub->save($this->sampleItem(['title' => 'Останется', 'date' => '2026-06-01']));
        $b = $pub->save($this->sampleItem(['title' => 'Удалим', 'date' => '2026-06-11']));

        // Both cards present before delete.
        $this->assertSame(2, $this->cardCount('news.html'));
        $this->assertTrue($this->siteExists($b['page']));

        // Seed the v2-real per-page sidecars for the to-be-deleted page so we can
        // prove cleanup. These are the ONLY per-page sidecars v2 has — there is no
        // storage/seo/ (SeoService rewrites the page <head> in place) and no
        // storage/customizations/ (no such feature), so they are not seeded here.
        // file-form: meta (PropsStore) + drafts (DraftService).
        $hash = sha1($b['page']);
        foreach (['meta', 'drafts'] as $kind) {
            $dir = $this->storage . '/' . $kind;
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($dir . '/' . $hash . '.json', '{}');
        }

        // Seed the journal sidecar in its REAL file form (Journal::delete unlinks
        // '<hash>.jsonl' + every rotated '<hash>.<Ymd-His>.jsonl.gz'). The live
        // file AND a rotated archive must both be cleaned by delete().
        $journalDir = $this->storage . '/journal';
        if (!is_dir($journalDir)) {
            mkdir($journalDir, 0775, true);
        }
        $journalLive = $journalDir . '/' . $hash . '.jsonl';
        $journalArchive = $journalDir . '/' . $hash . '.20260611-101500.jsonl.gz';
        file_put_contents($journalLive, "{\"page\":\"{$b['page']}\"}\n");
        file_put_contents($journalArchive, (string) gzencode("old line\n", 6));
        $this->assertFileExists($journalLive, 'precondition: live journal seeded');
        $this->assertFileExists($journalArchive, 'precondition: rotated journal archive seeded');

        // Seed the undo sidecar in its REAL dir form (UndoPayloadStore keeps a
        // directory 'undo/<hash>/' with payload files inside). delete() must
        // removeTree() the whole dir.
        $undoDir = $this->storage . '/undo/' . $hash;
        if (!is_dir($undoDir)) {
            mkdir($undoDir, 0775, true);
        }
        file_put_contents($undoDir . '/op-1.json', '{}');
        $this->assertDirectoryExists($undoDir, 'precondition: undo dir seeded');

        $res = $pub->delete($b['id']);

        // Returned shape.
        $this->assertSame($b['id'], $res['id']);
        $this->assertSame($b['slug'], $res['slug']);
        $this->assertSame(2, $res['lists_updated'], 'both list pages refreshed on delete');

        // Article page gone, but a pre-delete backup exists.
        $this->assertFalse($this->siteExists($b['page']), 'deleted article page must be unlinked');
        $this->assertNotEmpty(
            (new BackupService($this->config))->listForPage($b['page']),
            'a pre-delete backup must exist for the removed article',
        );

        // File-form sidecars cleaned (PageService-equivalent behaviour replicated
        // by hand) — meta (PropsStore) + drafts (DraftService).
        foreach (['meta', 'drafts'] as $kind) {
            $this->assertFileDoesNotExist(
                $this->storage . '/' . $kind . '/' . $hash . '.json',
                "$kind sidecar for the deleted article must be removed",
            );
        }

        // Journal sidecar cleaned — BOTH the live .jsonl and its rotated .gz
        // archive (regression guard: the journal is a FILE+glob, not a dir, so
        // a removeTree() on 'journal/<hash>' was a silent no-op that leaked it).
        $this->assertFileDoesNotExist(
            $journalLive,
            'live journal .jsonl for the deleted article must be removed',
        );
        $this->assertFileDoesNotExist(
            $journalArchive,
            'rotated journal .gz archive for the deleted article must be removed',
        );

        // Undo sidecar cleaned — the whole 'undo/<hash>/' dir (UndoPayloadStore).
        $this->assertDirectoryDoesNotExist(
            $undoDir,
            'undo dir for the deleted article must be removed',
        );

        // Store entry gone.
        $this->assertNull((new NewsStore($this->config))->find($b['id']));

        // Cards: only the survivor remains in every list.
        $this->assertSame(1, $this->cardCount('news.html'));
        $this->assertSame(['Останется'], $this->cardTitles('news.html'));
        $this->assertSame(1, $this->cardCount('index.html'));
        $this->assertSame(['Останется'], $this->cardTitles('index.html'));

        // Survivor's own page untouched.
        $this->assertTrue($this->siteExists($a['page']));
    }

    public function test_delete_unknown_id_throws_404_and_touches_nothing(): void
    {
        $this->putSite('_news-template.html', $this->fullTemplateHtml());
        $this->putSite('news.html', $this->listingHtml());

        $pub = $this->publisher();
        $pub->save($this->sampleItem());

        $before = $this->siteFiles();

        try {
            $pub->delete('n-deadbeef');
            $this->fail('expected NewsException for unknown id');
        } catch (NewsException $e) {
            $this->assertSame(404, $e->statusHint);
        }

        $this->assertSame($before, $this->siteFiles(), 'a failed delete must not change the site');
    }

    // ---------------------------------------------------------------------
    // N3.6 — atomicity: a rewritten list page is never left half-written
    // ---------------------------------------------------------------------

    public function test_rewritten_list_page_is_always_complete_html(): void
    {
        $this->putSite('_news-template.html', $this->fullTemplateHtml());
        $this->putSite('news.html', $this->listingHtml());

        $pub = $this->publisher();
        for ($i = 1; $i <= 12; $i++) {
            $pub->save($this->sampleItem([
                'title' => 'Новость ' . $i,
                'date' => sprintf('2026-06-%02d', $i),
            ]));
        }

        $html = $this->readSite('news.html');

        // The page is a complete, well-formed document — never truncated.
        $this->assertStringContainsString('<html', $html);
        $this->assertStringContainsString('</html>', $html);
        $this->assertStringContainsString('listing chrome', $html, 'chrome preserved after 12 rewrites');
        $this->assertSame(12, $this->cardCount('news.html'));

        // Re-parse must succeed (no malformed markup written).
        $doc = (new Html5())->parse($html);
        $this->assertInstanceOf(\DOMDocument::class, $doc);

        // No leftover tmp files in site_root (atomic rename cleaned up).
        $this->assertSame([], array_values(array_filter(
            $this->siteFiles(),
            static fn (string $p): bool => str_contains($p, '.tmp.'),
        )), 'no orphan .tmp.* files may remain');
    }

    public function test_existing_article_resave_takes_a_presave_backup(): void
    {
        $this->putSite('_news-template.html', $this->fullTemplateHtml());

        $pub = $this->publisher();
        $first = $pub->save($this->sampleItem(['title' => 'Версия один']));

        // Re-save the SAME item (by id) with a changed title.
        $second = $pub->save($this->sampleItem([
            'id' => $first['id'],
            'title' => 'Версия один', // same title ⇒ slug stable
        ]));

        $this->assertSame($first['slug'], $second['slug'], 'slug stable across re-save');
        $this->assertSame($first['id'], $second['id']);
        $this->assertNotNull($second['backup_id'], 'overwriting an existing article must take a pre-save backup');
        $this->assertNotEmpty((new BackupService($this->config))->listForPage($first['page']));
    }

    // ---------------------------------------------------------------------
    // Phase C — prev/next nav consistency across the SITE. publish/edit/delete
    // must keep EVERY article's prev/next correct, by re-rendering all article
    // pages (each with its freshly-computed date-neighbours).
    // ---------------------------------------------------------------------

    /** Find the slug a given article links to in its prev/next nav direction. */
    private function prevNextHref(string $page, string $dir): ?string
    {
        if (!$this->siteExists($page)) {
            return null;
        }
        $doc = (new Html5())->parse($this->readSite($page));
        $xpath = new \DOMXPath($doc);
        $a = $xpath
            ->query("//nav[contains(concat(' ', normalize-space(@class), ' '), ' ef-news-prevnext ')]//a[contains(concat(' ', normalize-space(@class), ' '), ' ef-news-prevnext-{$dir} ')]")
            ->item(0);
        return $a instanceof \DOMElement ? $a->getAttribute('href') : null;
    }

    private function hasPrevNextNav(string $page): bool
    {
        if (!$this->siteExists($page)) {
            return false;
        }
        return str_contains($this->readSite($page), 'class="ef-news-prevnext"');
    }

    public function test_two_articles_link_each_other_prev_and_next_by_date(): void
    {
        $this->putSite('_news-template.html', $this->fullTemplateHtml());

        $pub = $this->publisher();
        // A is OLDER (2026-06-01), B is NEWER (2026-06-11).
        $a = $pub->save($this->sampleItem(['title' => 'Старая A', 'date' => '2026-06-01']));
        $b = $pub->save($this->sampleItem(['title' => 'Новая B', 'date' => '2026-06-11']));

        // A's article links B as the NEXT (newer) news.
        $this->assertTrue($this->hasPrevNextNav($a['page']), 'older article must carry a prev/next nav');
        $this->assertSame('/' . $b['slug'], $this->prevNextHref($a['page'], 'next'), 'A links B as next (newer)');
        $this->assertNull($this->prevNextHref($a['page'], 'prev'), 'A has no older neighbour');

        // B's article links A as the PREVIOUS (older) news.
        $this->assertTrue($this->hasPrevNextNav($b['page']), 'newer article must carry a prev/next nav');
        $this->assertSame('/' . $a['slug'], $this->prevNextHref($b['page'], 'prev'), 'B links A as previous (older)');
        $this->assertNull($this->prevNextHref($b['page'], 'next'), 'B has no newer neighbour');
    }

    public function test_delete_newer_re_renders_older_article_to_drop_its_next_link(): void
    {
        $this->putSite('_news-template.html', $this->fullTemplateHtml());

        $pub = $this->publisher();
        $a = $pub->save($this->sampleItem(['title' => 'Старая A', 'date' => '2026-06-01']));
        $b = $pub->save($this->sampleItem(['title' => 'Новая B', 'date' => '2026-06-11']));

        // Precondition: A links B as next.
        $this->assertSame('/' . $b['slug'], $this->prevNextHref($a['page'], 'next'));

        // Delete the newer (B). A is the last remaining article ⇒ NO neighbours.
        $pub->delete($b['id']);

        $this->assertFalse($this->siteExists($b['page']), 'deleted article page is gone');
        // A re-rendered: it now has neither a next link (B is gone) nor any nav
        // at all (the only article left has no neighbours).
        $this->assertNull($this->prevNextHref($a['page'], 'next'), 'A must no longer link the deleted B as next');
        $this->assertFalse($this->hasPrevNextNav($a['page']), 'lone surviving article has no prev/next nav');
    }

    public function test_three_item_chain_middle_links_both_neighbours(): void
    {
        $this->putSite('_news-template.html', $this->fullTemplateHtml());

        $pub = $this->publisher();
        // oldest → newest by date
        $old = $pub->save($this->sampleItem(['title' => 'Старая', 'date' => '2026-06-01']));
        $mid = $pub->save($this->sampleItem(['title' => 'Средняя', 'date' => '2026-06-05']));
        $new = $pub->save($this->sampleItem(['title' => 'Новая', 'date' => '2026-06-11']));

        // The MIDDLE article links BOTH neighbours: prev=older, next=newer.
        $this->assertTrue($this->hasPrevNextNav($mid['page']));
        $this->assertSame('/' . $old['slug'], $this->prevNextHref($mid['page'], 'prev'), 'middle links the older as previous');
        $this->assertSame('/' . $new['slug'], $this->prevNextHref($mid['page'], 'next'), 'middle links the newer as next');

        // Ends each have exactly one neighbour.
        $this->assertNull($this->prevNextHref($old['page'], 'prev'), 'oldest has no older neighbour');
        $this->assertSame('/' . $mid['slug'], $this->prevNextHref($old['page'], 'next'));
        $this->assertSame('/' . $mid['slug'], $this->prevNextHref($new['page'], 'prev'));
        $this->assertNull($this->prevNextHref($new['page'], 'next'), 'newest has no newer neighbour');
    }

    public function test_editing_a_date_re_renders_neighbours_to_keep_nav_correct(): void
    {
        // Adding a third item BETWEEN two existing ones must re-point the older
        // and newer articles' nav at the new middle item.
        $this->putSite('_news-template.html', $this->fullTemplateHtml());

        $pub = $this->publisher();
        $old = $pub->save($this->sampleItem(['title' => 'Старая', 'date' => '2026-06-01']));
        $new = $pub->save($this->sampleItem(['title' => 'Новая', 'date' => '2026-06-11']));

        // Before: they link directly to each other.
        $this->assertSame('/' . $new['slug'], $this->prevNextHref($old['page'], 'next'));
        $this->assertSame('/' . $old['slug'], $this->prevNextHref($new['page'], 'prev'));

        // Insert a NEW item dated between them.
        $mid = $pub->save($this->sampleItem(['title' => 'Средняя', 'date' => '2026-06-05']));

        // After: the old/new articles were re-rendered to point at the new middle.
        $this->assertSame('/' . $mid['slug'], $this->prevNextHref($old['page'], 'next'), 'older now links the new middle as next');
        $this->assertSame('/' . $mid['slug'], $this->prevNextHref($new['page'], 'prev'), 'newer now links the new middle as previous');
    }

    public function test_secondary_regeneration_does_not_churn_backups_for_unedited_articles(): void
    {
        // Backup discipline: only the PRIMARY edited article (and rewritten list
        // pages) take pre-save backups. A SECONDARY article — regenerated only
        // because its neighbour changed — must NOT accumulate a fresh pre-save
        // backup on every unrelated save.
        $this->putSite('_news-template.html', $this->fullTemplateHtml());

        $pub = $this->publisher();
        $a = $pub->save($this->sampleItem(['title' => 'A', 'date' => '2026-06-01']));

        $backups = new BackupService($this->config);
        // A brand-new article takes NO pre-save backup at creation.
        $this->assertEmpty($backups->listForPage($a['page']), 'new article: no pre-save backup');

        // Publish a SECOND, unrelated article. A is regenerated (its nav now
        // links B) but A is NOT the user-edited primary → no backup of A.
        $pub->save($this->sampleItem(['title' => 'B', 'date' => '2026-06-11']));
        $this->assertEmpty(
            $backups->listForPage($a['page']),
            'secondary regeneration must not take a pre-save backup of the unedited article A',
        );

        // Sanity: A WAS actually regenerated (its nav now points at B).
        $bSlug = null;
        foreach ((new NewsStore($this->config))->items() as $it) {
            if (($it['title'] ?? '') === 'B') {
                $bSlug = (string) $it['slug'];
            }
        }
        $this->assertNotNull($bSlug);
        $this->assertSame('/' . $bSlug, $this->prevNextHref($a['page'], 'next'), 'A was regenerated with the new neighbour');
    }
}
