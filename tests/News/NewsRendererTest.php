<?php

declare(strict_types=1);

namespace EditFront\Tests\News;

use EditFront\Document\Annotator;
use EditFront\Document\Html5;
use EditFront\News\NewsRenderer;
use EditFront\News\NewsStore;
use EditFront\News\NewsTemplate;
use EditFront\Storage\FileStorage;
use EditFront\Storage\PathGuard;
use EditFront\Support\Config;
use PHPUnit\Framework\TestCase;

final class NewsRendererTest extends TestCase
{
    private string $storage;
    private string $site;

    protected function setUp(): void
    {
        $this->storage = ef2_temp_dir('news-rnd-storage');
        $this->site = ef2_temp_dir('news-rnd-site');
    }

    private function config(): Config
    {
        return ef2_test_config([
            'storage_dir' => $this->storage,
            'site_root' => $this->site,
        ]);
    }

    private function fileStorage(Config $config): FileStorage
    {
        return new FileStorage($config, new PathGuard($config));
    }

    private function renderer(?string $templateHtml = null): NewsRenderer
    {
        $config = $this->config();
        $store = new NewsStore($config);
        $store->saveConfig([
            'template_page' => '_news-template.html',
            'title_suffix' => ' — Новости компании',
            'base_url' => 'https://example.com',
            'date_locale' => 'ru',
        ]);
        $this->fileStorage($config)->createNew('_news-template.html', $templateHtml ?? $this->validTemplateHtml());

        $template = new NewsTemplate($store, $this->fileStorage($config), new Html5());
        return new NewsRenderer($template, new Annotator(), new Html5(), $store);
    }

    /** @return array<string,mixed> */
    private function sampleItem(): array
    {
        return [
            'id' => 'n-0a1b2c3d',
            'slug' => 'den-rossii-2026',
            'title' => 'График работы офисов в праздничный день',
            'title_short' => 'График работы в День России',
            'category' => 'Компания',
            'date' => '2026-06-11',
            'cover' => 'assets/img/news-den-rossii.jpg',
            'cover_og' => 'https://example.com/assets/img/og-news-den-rossii.jpg',
            'excerpt' => 'График работы офисов в праздничные дни.',
            'body_html' => '<p class="lead">Текст лида.</p><h2>Подзаголовок</h2><p>Абзац.</p>',
            'published' => true,
            'created_at' => '2026-06-11T08:00:00Z',
            'updated_at' => '2026-06-11T08:00:00Z',
        ];
    }

    private function validTemplateHtml(): string
    {
        return <<<'HTML'
<!doctype html>
<html data-news-template lang="ru">
<head>
  <meta charset="utf-8">
  <title data-nf="title_tag">placeholder title</title>
  <meta name="description" data-nf="excerpt" content="placeholder desc">
  <link rel="canonical" data-nf="url" href="https://example.test/placeholder">
  <meta name="robots" data-nf="robots" data-nf-attr="content" content="noindex, nofollow">
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
  <nav class="topnav">CHROME NAV</nav>
  <div class="crumbs"><a href="/">Home</a> / <span class="cur" data-nf="title_short">placeholder short</span></div>
  <header class="page-head">
    <span class="tag-pill" data-nf="meta_line">placeholder meta</span>
    <h1 data-nf="title">placeholder h1</h1>
  </header>
  <figure class="article-cover"><img data-nf="cover" src="placeholder.jpg" alt=""></figure>
  <article class="article" data-nf="body"><p>placeholder body</p></article>
  <div class="share">FIXED CHROME</div>
  <template data-news-card>
    <a class="post" data-nf="url" href="/placeholder">
      <span class="media"><img data-nf="cover" src="placeholder.jpg" alt=""></span>
      <span class="date" data-nf="meta_line">placeholder date</span>
      <h3 data-nf="title_short">placeholder card title</h3>
    </a>
  </template>
  <footer class="site-footer">CHROME FOOTER</footer>
</body>
</html>
HTML;
    }

    /**
     * Same valid skin, but the [data-nf="jsonld"] script carries a realistic
     * NewsArticle with the SITE-CONSTANT fields a template author maintains:
     * publisher (Organization with name+logo), author, and inLanguage — plus
     * placeholder per-article fields the renderer must overwrite.
     */
    private function templateWithRichJsonLd(): string
    {
        return <<<'HTML'
<!doctype html>
<html data-news-template lang="ru">
<head>
  <meta charset="utf-8">
  <title data-nf="title_tag">placeholder title</title>
  <meta name="description" data-nf="excerpt" content="placeholder desc">
  <link rel="canonical" data-nf="url" href="https://example.test/placeholder">
  <script type="application/ld+json" data-nf="jsonld">{
  "@context": "https://schema.org",
  "@type": "NewsArticle",
  "headline": "TEMPLATE PLACEHOLDER HEADLINE",
  "datePublished": "1970-01-01T00:00:00Z",
  "dateModified": "1970-01-01T00:00:00Z",
  "url": "https://example.test/placeholder",
  "mainEntityOfPage": { "@type": "WebPage", "@id": "https://example.test/placeholder" },
  "inLanguage": "ru-RU",
  "publisher": {
    "@type": "Organization",
    "name": "Пример",
    "logo": { "@type": "ImageObject", "url": "https://example.com/logo.png" }
  },
  "author": { "@type": "Organization", "name": "Редакция" }
}</script>
</head>
<body>
  <div class="crumbs"><span class="cur" data-nf="title_short">placeholder short</span></div>
  <header class="page-head">
    <span class="tag-pill" data-nf="meta_line">placeholder meta</span>
    <h1 data-nf="title">placeholder h1</h1>
  </header>
  <figure class="article-cover"><img data-nf="cover" src="placeholder.jpg" alt=""></figure>
  <article class="article" data-nf="body"><p>placeholder body</p></article>
  <template data-news-card>
    <a class="post" data-nf="url" href="/placeholder">
      <span class="media"><img data-nf="cover" src="placeholder.jpg" alt=""></span>
      <span class="date" data-nf="meta_line">placeholder date</span>
      <h3 data-nf="title_short">placeholder card title</h3>
    </a>
  </template>
</body>
</html>
HTML;
    }

    /** Extract the decoded JSON-LD object from a rendered article page. */
    private function renderedJsonLd(string $html): array
    {
        self::assertSame(1, substr_count($html, 'application/ld+json'), 'exactly one ld+json block');
        self::assertMatchesRegularExpression('~<script type="application/ld\+json">(.*?)</script>~s', $html);
        preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $m);
        $decoded = json_decode($m[1], true);
        self::assertIsArray($decoded, 'ld+json must decode to a valid array');
        return $decoded;
    }

    public function test_jsonld_merge_preserves_site_constants_and_overwrites_article_fields(): void
    {
        $renderer = $this->renderer($this->templateWithRichJsonLd());
        $decoded = $this->renderedJsonLd($renderer->renderArticle($this->sampleItem()));

        // (a) site constants from the template SURVIVE verbatim
        self::assertSame('ru-RU', $decoded['inLanguage']);
        self::assertSame([
            '@type' => 'Organization',
            'name' => 'Пример',
            'logo' => ['@type' => 'ImageObject', 'url' => 'https://example.com/logo.png'],
        ], $decoded['publisher']);
        self::assertSame(
            ['@type' => 'Organization', 'name' => 'Редакция'],
            $decoded['author'],
        );

        // (b) per-article fields are OVERWRITTEN to the item's values (not the
        //     1970/placeholder values the template carried)
        self::assertSame('NewsArticle', $decoded['@type']);
        self::assertSame('https://schema.org', $decoded['@context']);
        self::assertSame('График работы офисов в праздничный день', $decoded['headline']);
        self::assertSame('График работы офисов в праздничные дни.', $decoded['description']);
        self::assertSame('Компания', $decoded['articleSection']);
        self::assertSame('2026-06-11T08:00:00Z', $decoded['datePublished']);
        self::assertSame('2026-06-11T08:00:00Z', $decoded['dateModified']);
        self::assertSame('https://example.com/den-rossii-2026', $decoded['url']);
        self::assertSame('https://example.com/den-rossii-2026', $decoded['mainEntityOfPage']['@id']);
        self::assertSame(['https://example.com/assets/img/og-news-den-rossii.jpg'], $decoded['image']);
    }

    public function test_jsonld_merge_does_not_break_out_of_script_when_field_carries_script_tag(): void
    {
        $item = $this->sampleItem();
        $item['excerpt'] = 'evil </script><script>alert(1)</script>';

        $renderer = $this->renderer($this->templateWithRichJsonLd());
        $html = $renderer->renderArticle($item);

        // block is still valid JSON and has no LITERAL </script> breakout
        preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $m);
        self::assertStringNotContainsString('</script>', $m[1]);
        $decoded = $this->renderedJsonLd($html);
        self::assertStringContainsString('</script>', (string) $decoded['description']); // value survives decode
        // site constant still present alongside the hostile field
        self::assertSame('ru-RU', $decoded['inLanguage']);
    }

    public function test_jsonld_empty_template_placeholder_still_yields_valid_news_article(): void
    {
        // validTemplateHtml() carries an empty "{}" jsonld placeholder.
        $decoded = $this->renderedJsonLd($this->renderer()->renderArticle($this->sampleItem()));

        self::assertSame('https://schema.org', $decoded['@context']);
        self::assertSame('NewsArticle', $decoded['@type']);
        self::assertSame('График работы офисов в праздничный день', $decoded['headline']);
        self::assertSame('2026-06-11T08:00:00Z', $decoded['datePublished']);
        self::assertSame('https://example.com/den-rossii-2026', $decoded['url']);
        self::assertSame(['https://example.com/assets/img/og-news-den-rossii.jpg'], $decoded['image']);
    }

    public function test_jsonld_merge_keeps_template_image_when_cover_empty(): void
    {
        // Template carries no image; item has neither cover nor cover_og →
        // renderer must NOT inject an empty image, and (here) leave it absent.
        $item = $this->sampleItem();
        unset($item['cover'], $item['cover_og']);

        $renderer = $this->renderer($this->templateWithRichJsonLd());
        $decoded = $this->renderedJsonLd($renderer->renderArticle($item));

        self::assertArrayNotHasKey('image', $decoded);
        // site constants intact
        self::assertSame('ru-RU', $decoded['inLanguage']);
        self::assertArrayHasKey('publisher', $decoded);
    }

    // ---------------------------------------------------------------------
    // Fix 1 — robots is a managed (optional) slot keyed off `published`.
    // A published article must be INDEXABLE; an unpublished one noindex; a
    // template WITHOUT the robots slot must still render (slot is optional).
    // ---------------------------------------------------------------------

    public function test_published_article_robots_is_index_follow(): void
    {
        // validTemplateHtml() carries the robots slot with a noindex skin-default.
        $item = $this->sampleItem();
        $item['published'] = true;

        $html = $this->renderer()->renderArticle($item);

        self::assertMatchesRegularExpression(
            '~<meta name="robots"[^>]*content="index, follow"~',
            $html,
            'a published article must serve robots=index, follow',
        );
        self::assertStringNotContainsString('noindex', $html, 'no noindex may survive on a published article');
    }

    public function test_unpublished_article_robots_is_noindex_nofollow(): void
    {
        $item = $this->sampleItem();
        $item['published'] = false;

        $html = $this->renderer()->renderArticle($item);

        self::assertMatchesRegularExpression(
            '~<meta name="robots"[^>]*content="noindex, nofollow"~',
            $html,
            'an unpublished article must serve robots=noindex, nofollow',
        );
        self::assertStringNotContainsString('index, follow', $html, 'no index, follow may survive on an unpublished article');
    }

    public function test_template_without_robots_slot_still_renders(): void
    {
        // templateWithRichJsonLd() has NO robots slot — the slot is OPTIONAL, so
        // the article must render fine and NOT have a robots meta injected.
        $renderer = $this->renderer($this->templateWithRichJsonLd());

        $html = $renderer->renderArticle($this->sampleItem());

        self::assertStringContainsString('<html', $html);
        self::assertStringContainsString('</html>', $html);
        self::assertStringNotContainsString('name="robots"', $html, 'no robots meta may be injected when the template lacks the slot');
        // and the article body still filled normally
        self::assertStringContainsString('>График работы офисов в праздничный день<', $html);
    }

    public function test_jsonld_merge_carries_publisher_and_author_from_template(): void
    {
        // The template's JSON-LD carries the site-constant publisher + author;
        // the renderer's merge must preserve them into the published article.
        $renderer = $this->renderer($this->templateWithRichJsonLd());
        $decoded = $this->renderedJsonLd($renderer->renderArticle($this->sampleItem()));

        self::assertArrayHasKey('publisher', $decoded);
        self::assertSame('Organization', $decoded['publisher']['@type']);
        self::assertSame('Пример', $decoded['publisher']['name']);
        self::assertSame(
            ['@type' => 'ImageObject', 'url' => 'https://example.com/logo.png'],
            $decoded['publisher']['logo'],
        );
        self::assertArrayHasKey('author', $decoded);
        self::assertSame('Organization', $decoded['author']['@type']);
    }

    public function test_article_fills_every_text_and_attr_slot(): void
    {
        $html = $this->renderer()->renderArticle($this->sampleItem());

        // head
        self::assertStringContainsString('<title>График работы офисов в праздничный день — Новости компании</title>', $html);
        self::assertStringContainsString('content="График работы офисов в праздничные дни."', $html);
        self::assertStringContainsString('href="https://example.com/den-rossii-2026"', $html);
        self::assertStringContainsString('content="https://example.com/den-rossii-2026"', $html);
        self::assertStringContainsString('content="https://example.com/assets/img/og-news-den-rossii.jpg"', $html);

        // body
        self::assertStringContainsString('Компания · 11 июня 2026', $html);            // article meta order
        self::assertStringContainsString('>График работы офисов в праздничный день<', $html); // h1
        self::assertStringContainsString('src="assets/img/news-den-rossii.jpg"', $html);
        // body innerHTML: the lead <p class="lead"> survives importFragment (no xmlns
        // churn); ensureAnnotated stamps a data-cms-id on it, so match the open tag
        // tolerantly rather than as a literal contiguous substring.
        self::assertMatchesRegularExpression('~<p class="lead"[^>]*>Текст лида\.</p>~', $html); // body innerHTML
        self::assertMatchesRegularExpression('~<h2[^>]*>Подзаголовок</h2>~', $html);

        // breadcrumb short title
        self::assertStringContainsString('>График работы в День России<', $html);
    }

    public function test_article_strips_all_marker_attributes(): void
    {
        $html = $this->renderer()->renderArticle($this->sampleItem());
        foreach (['data-nf', 'data-nf-attr', 'data-news-template', 'data-news-card', 'data-news-list', 'data-news-limit'] as $marker) {
            self::assertStringNotContainsString($marker, $html, "marker $marker should be stripped");
        }
    }

    public function test_article_removes_card_template_and_keeps_chrome(): void
    {
        $html = $this->renderer()->renderArticle($this->sampleItem());
        self::assertStringNotContainsString('<template', $html);
        self::assertStringContainsString('CHROME NAV', $html);
        self::assertStringContainsString('CHROME FOOTER', $html);
        self::assertStringContainsString('FIXED CHROME', $html);
    }

    public function test_article_cover_img_alt_is_full_title(): void
    {
        $html = $this->renderer()->renderArticle($this->sampleItem());
        self::assertMatchesRegularExpression(
            '~<img[^>]*src="assets/img/news-den-rossii\.jpg"[^>]*alt="График работы офисов в праздничный день"~',
            $html,
        );
    }

    public function test_article_is_annotated(): void
    {
        $html = $this->renderer()->renderArticle($this->sampleItem());
        self::assertMatchesRegularExpression('/data-cms-id="cms-[0-9a-f]{12}"/', $html);
    }

    public function test_card_fills_slots_with_card_order_meta(): void
    {
        $card = $this->renderer()->renderCard($this->sampleItem());
        self::assertInstanceOf(\DOMElement::class, $card);

        $owner = $card->ownerDocument;
        self::assertInstanceOf(\DOMDocument::class, $owner);
        $out = (new Html5())->serialize($this->wrap($card));

        // Spec §1.2: the CARD href is a site-RELATIVE '/<slug>' — host-independent
        // so cards stay on whatever host serves the page (NOT the absolute
        // canonical the ARTICLE head carries). Extract and assert the exact href.
        self::assertMatchesRegularExpression('~<a class="post"[^>]*href="([^"]*)"~', $out);
        preg_match('~<a class="post"[^>]*href="([^"]*)"~', $out, $hrefMatch);
        $href = $hrefMatch[1];
        self::assertSame('/den-rossii-2026', $href, 'card href must be site-relative /<slug>');
        self::assertStringStartsWith('/', $href, 'card href must start with "/"');
        self::assertStringNotContainsString('http', $href, 'card href must NOT be absolute');
        self::assertStringNotContainsString('example.com', $href, 'card href must carry no host');

        self::assertStringContainsString('src="assets/img/news-den-rossii.jpg"', $out);
        self::assertStringContainsString('11 июня 2026 · Компания', $out);   // card meta order (date first)
        self::assertStringContainsString('>График работы в День России<', $out); // title_short
        self::assertStringNotContainsString('data-nf', $out);
    }

    /**
     * Spec §1.1 vs §1.2 contrast pinned in one place: the ARTICLE head url slot
     * (canonical + og:url) is the ABSOLUTE '{base}/<slug>' production canonical,
     * while the CARD url slot is the site-RELATIVE '/<slug>'. The same item must
     * produce both forms from the two render paths.
     */
    public function test_card_url_relative_but_article_canonical_absolute(): void
    {
        $renderer = $this->renderer();
        $item = $this->sampleItem();

        // CARD: site-relative '/<slug>'
        $cardOut = (new Html5())->serialize($this->wrap($renderer->renderCard($item)));
        preg_match('~<a class="post"[^>]*href="([^"]*)"~', $cardOut, $cm);
        self::assertSame('/den-rossii-2026', $cm[1]);
        self::assertStringStartsWith('/', $cm[1]);
        self::assertStringNotContainsString('http', $cm[1]);

        // ARTICLE head: ABSOLUTE canonical + og:url '{base}/<slug>'
        $articleHtml = $renderer->renderArticle($item);
        self::assertMatchesRegularExpression(
            '~<link rel="canonical"[^>]*href="https://example\.com/den-rossii-2026"~',
            $articleHtml,
        );
        self::assertMatchesRegularExpression(
            '~<meta property="og:url"[^>]*content="https://example\.com/den-rossii-2026"~',
            $articleHtml,
        );
        // and the JSON-LD url is the absolute canonical too
        preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $articleHtml, $jm);
        $decoded = json_decode($jm[1], true);
        self::assertSame('https://example.com/den-rossii-2026', $decoded['url']);
    }

    public function test_card_cover_alt_is_title_short(): void
    {
        $card = $this->renderer()->renderCard($this->sampleItem());
        $out = (new Html5())->serialize($this->wrap($card));
        self::assertMatchesRegularExpression(
            '~<img[^>]*src="assets/img/news-den-rossii\.jpg"[^>]*alt="График работы в День России"~',
            $out,
        );
    }

    // ---------------------------------------------------------------------
    // Empty cover must NOT produce a broken <img src="">. The cover <img> is
    // removed; if its wrapper (.media in a card, .article-cover in an article)
    // is left with no element children, the wrapper is removed too.
    // ---------------------------------------------------------------------

    public function test_card_with_empty_cover_drops_img_and_empty_media_wrapper(): void
    {
        $item = $this->sampleItem();
        $item['cover'] = '';

        $card = $this->renderer()->renderCard($item);
        $out = (new Html5())->serialize($this->wrap($card));

        self::assertStringNotContainsString('<img', $out, 'no <img> may survive for a coverless card');
        self::assertStringNotContainsString('src=""', $out, 'no empty src="" may be emitted');
        self::assertStringNotContainsString('class="media"', $out, 'the empty .media wrapper must be removed');

        // the rest of the card still renders normally
        self::assertStringContainsString('>График работы в День России<', $out); // title_short
        self::assertStringContainsString('11 июня 2026 · Компания', $out);       // meta line
    }

    public function test_card_with_cover_still_has_img(): void
    {
        // control: a card WITH a cover keeps its <img src=...> and .media wrapper
        $card = $this->renderer()->renderCard($this->sampleItem());
        $out = (new Html5())->serialize($this->wrap($card));

        self::assertStringContainsString('<img', $out);
        self::assertStringContainsString('src="assets/img/news-den-rossii.jpg"', $out);
        self::assertStringContainsString('class="media"', $out);
    }

    public function test_article_with_empty_cover_drops_img_and_empty_article_cover_wrapper(): void
    {
        $item = $this->sampleItem();
        $item['cover'] = '';

        $html = $this->renderer()->renderArticle($item);

        // the .article-cover figure (its only child was the cover <img>) is gone
        self::assertStringNotContainsString('class="article-cover"', $html, 'empty .article-cover wrapper must be removed');
        // no cover <img>: there are no OTHER <img> in the template, so none must survive
        self::assertStringNotContainsString('<img', $html, 'no <img> may survive for a coverless article');
        self::assertStringNotContainsString('src=""', $html, 'no empty src="" may be emitted');

        // the article still renders its body + title normally
        self::assertStringContainsString('>График работы офисов в праздничный день<', $html); // h1
        self::assertMatchesRegularExpression('~<h2[^>]*>Подзаголовок</h2>~', $html);             // body
    }

    public function test_article_with_cover_still_has_img_in_article_cover(): void
    {
        // control: an article WITH a cover keeps its <img> inside .article-cover
        $html = $this->renderer()->renderArticle($this->sampleItem());

        self::assertStringContainsString('class="article-cover"', $html);
        // the cover <img> survives inside the .article-cover figure (the figure
        // carries an injected data-cms-id, so match the open tag tolerantly).
        self::assertMatchesRegularExpression(
            '~<figure class="article-cover"[^>]*>\s*<img[^>]*src="assets/img/news-den-rossii\.jpg"~',
            $html,
        );
    }

    public function test_jsonld_regenerated_from_item_fields(): void
    {
        $html = $this->renderer()->renderArticle($this->sampleItem());

        // extract the ld+json script body
        self::assertMatchesRegularExpression(
            '~<script type="application/ld\+json">(.*?)</script>~s',
            $html,
        );
        preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $m);
        $decoded = json_decode($m[1], true);
        self::assertIsArray($decoded);

        self::assertSame('https://schema.org', $decoded['@context']);
        self::assertSame('NewsArticle', $decoded['@type']);
        self::assertSame('График работы офисов в праздничный день', $decoded['headline']);
        self::assertSame('График работы офисов в праздничные дни.', $decoded['description']);
        self::assertSame('Компания', $decoded['articleSection']);
        self::assertSame('2026-06-11T08:00:00Z', $decoded['datePublished']);
        self::assertSame('2026-06-11T08:00:00Z', $decoded['dateModified']);
        self::assertSame('https://example.com/den-rossii-2026', $decoded['url']);
        self::assertSame('https://example.com/den-rossii-2026', $decoded['mainEntityOfPage']['@id']);
        self::assertSame(['https://example.com/assets/img/og-news-den-rossii.jpg'], $decoded['image']);
    }

    public function test_jsonld_promotes_plain_date_when_no_timestamp(): void
    {
        $item = $this->sampleItem();
        unset($item['created_at'], $item['updated_at']); // force date promotion
        $html = $this->renderer()->renderArticle($item);
        preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $m);
        $decoded = json_decode($m[1], true);
        self::assertSame('2026-06-11T00:00:00Z', $decoded['datePublished']);
    }

    public function test_jsonld_does_not_break_out_of_script_with_hostile_excerpt(): void
    {
        $item = $this->sampleItem();
        $item['excerpt'] = 'evil </script><script>alert(1)</script>';
        $html = $this->renderer()->renderArticle($item);

        // there must be exactly ONE ld+json script and no injected raw </script> inside it
        self::assertSame(1, substr_count($html, 'application/ld+json'));
        preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $m);
        self::assertStringNotContainsString('</script>', $m[1]); // hex-escaped, never literal
        $decoded = json_decode($m[1], true);
        self::assertIsArray($decoded); // still valid JSON
        self::assertStringContainsString('</script>', (string) $decoded['description']); // value preserved after decode
    }

    public function test_render_is_round_trip_stable(): void
    {
        $renderer = $this->renderer();
        $html = $renderer->renderArticle($this->sampleItem());

        // Re-parse the PUBLISHED article and re-serialize it through the same
        // Html5 path. The serialized bytes must be identical (no whitespace
        // churn) — this is what the Html5::serialize tail-pin guarantees and
        // what keeps backups/diffs clean on subsequent CMS edits.
        $html5 = new Html5();
        $reSerialized = $html5->serialize($html5->parse($html));

        self::assertSame($html, $reSerialized);
    }

    public function test_re_render_same_item_is_byte_identical(): void
    {
        // Two renders of the same item from the same renderer instance must be
        // identical EXCEPT for the random data-cms-id values. Strip those and
        // compare the structural bytes.
        $renderer = $this->renderer();
        $a = preg_replace('/data-cms-id="cms-[0-9a-f]{12}"/', 'data-cms-id="X"', $renderer->renderArticle($this->sampleItem()));
        $b = preg_replace('/data-cms-id="cms-[0-9a-f]{12}"/', 'data-cms-id="X"', $renderer->renderArticle($this->sampleItem()));
        self::assertSame($a, $b);
    }

    // ---------------------------------------------------------------------
    // Phase B — gallery block: built from item.gallery and inserted INSIDE the
    // [data-nf="body"] element's text flow per gallery_position — as the FIRST
    // child ('before', a strip above the text) or the LAST child ('after', a
    // strip below the text). It lives in the narrower content/text column, NOT
    // full-width below the whole article+aside section and NOT in the aside.
    // Each image carries class="ef-news-img" + loading=lazy so the site-template
    // lightbox hooks it.
    // ---------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function itemWithGallery(string $position): array
    {
        return array_merge($this->sampleItem(), [
            'gallery' => [
                'https://example.com/images/uploads/g1.webp',
                '/images/uploads/g2.webp',
            ],
            'gallery_position' => $position,
        ]);
    }

    public function test_gallery_after_appears_as_last_child_of_body(): void
    {
        $html = $this->renderer()->renderArticle($this->itemWithGallery('after'));

        // exactly one gallery block, with both images, each ef-news-img + lazy
        self::assertSame(1, substr_count($html, 'class="ef-news-gallery"'));
        self::assertStringContainsString('src="https://example.com/images/uploads/g1.webp"', $html);
        self::assertStringContainsString('src="/images/uploads/g2.webp"', $html);
        self::assertSame(2, substr_count($html, 'class="ef-news-img"'));
        self::assertSame(2, substr_count($html, 'loading="lazy"'));

        // POSITION 'after': the gallery is a CHILD of the body element (the
        // <article class="article"> text column), and specifically its LAST
        // element child — a strip below the article text, in the text flow.
        $doc = (new Html5())->parse($html);
        $xpath = new \DOMXPath($doc);
        $gallery = $xpath->query('//div[@class="ef-news-gallery"]')->item(0);
        $body = $xpath->query('//article[contains(concat(" ", normalize-space(@class), " "), " article ")]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $gallery);
        self::assertInstanceOf(\DOMElement::class, $body);

        // the gallery's parent IS the body element (a descendant, not a sibling)
        self::assertSame($body, $gallery->parentNode, 'gallery must be a CHILD of the body element');

        // and it is the body's LAST element child
        $last = $body->lastChild;
        while ($last !== null && !$last instanceof \DOMElement) {
            $last = $last->previousSibling;
        }
        self::assertSame($gallery, $last, "gallery must be the body's LAST child for position 'after'");
    }

    public function test_gallery_before_appears_as_first_child_of_body(): void
    {
        $html = $this->renderer()->renderArticle($this->itemWithGallery('before'));

        self::assertSame(1, substr_count($html, 'class="ef-news-gallery"'));

        // POSITION 'before': the gallery is the FIRST element child of the body
        // element — a strip above the article text, in the text flow.
        $doc = (new Html5())->parse($html);
        $xpath = new \DOMXPath($doc);
        $gallery = $xpath->query('//div[@class="ef-news-gallery"]')->item(0);
        $body = $xpath->query('//article[contains(concat(" ", normalize-space(@class), " "), " article ")]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $gallery);
        self::assertInstanceOf(\DOMElement::class, $body);

        self::assertSame($body, $gallery->parentNode, 'gallery must be a CHILD of the body element');

        $first = $body->firstChild;
        while ($first !== null && !$first instanceof \DOMElement) {
            $first = $first->nextSibling;
        }
        self::assertSame($gallery, $first, "gallery must be the body's FIRST child for position 'before'");
    }

    public function test_empty_gallery_emits_no_gallery_block(): void
    {
        // sampleItem() has no gallery → renderArticle normalizes to [] via the
        // store path, but renderArticle receives the item as-is here, so assert
        // an explicitly-empty gallery yields no block.
        $item = array_merge($this->sampleItem(), ['gallery' => [], 'gallery_position' => 'after']);
        $html = $this->renderer()->renderArticle($item);

        self::assertStringNotContainsString('ef-news-gallery', $html, 'no gallery block for an empty gallery');
    }

    public function test_gallery_absent_key_emits_no_block(): void
    {
        // an item with NO gallery key at all must not error and must emit no block
        $html = $this->renderer()->renderArticle($this->sampleItem());
        self::assertStringNotContainsString('ef-news-gallery', $html);
    }

    public function test_gallery_imgs_have_empty_alt(): void
    {
        $html = $this->renderer()->renderArticle($this->itemWithGallery('after'));
        // each gallery img is decorative — the HTML5 serializer collapses the
        // empty alt to a bare boolean attribute (`alt` not `alt=""`), which is
        // a valid empty alt. Assert the bare `alt` token is present on a gallery
        // <img> and that no NON-empty alt slipped in.
        self::assertMatchesRegularExpression(
            '~<img[^>]*class="ef-news-img"[^>]*\salt(?=[\s>])~',
            $html,
        );
        self::assertStringNotContainsString('alt="', $this->galleryFragment($html), 'gallery imgs must carry an empty alt');
    }

    /** Extract just the .ef-news-gallery div fragment from a rendered article. */
    private function galleryFragment(string $html): string
    {
        if (preg_match('~<div class="ef-news-gallery"[^>]*>.*?</div>~s', $html, $m) === 1) {
            return $m[0];
        }
        return '';
    }

    public function test_gallery_render_is_round_trip_stable(): void
    {
        // a gallery article must also survive the re-parse/serialize round-trip
        // (clean backups/diffs on later edits).
        $renderer = $this->renderer();
        $html = $renderer->renderArticle($this->itemWithGallery('after'));
        $html5 = new Html5();
        self::assertSame($html, $html5->serialize($html5->parse($html)));
    }

    // ---------------------------------------------------------------------
    // Gallery IN-FLOW placement in a multi-column layout: when the body element
    // sits in the host .article-layout (<article> + <aside>), the gallery
    // must stay INSIDE the <article> text column (a child of the body element),
    // NOT lifted out to be a full-width sibling of .article-layout and NOT placed
    // in the aside. It is the body's first child for 'before', last for 'after'.
    // ---------------------------------------------------------------------

    public function test_gallery_after_in_multicolumn_layout_is_last_child_of_body(): void
    {
        // templateWithAside() = body <article> + <aside> inside .article-layout.
        $renderer = $this->renderer($this->templateWithAside());
        $html = $renderer->renderArticle($this->itemWithGallery('after'));
        $doc = (new Html5())->parse($html);
        $xpath = new \DOMXPath($doc);

        // exactly one gallery, both images present
        self::assertSame(1, substr_count($html, 'class="ef-news-gallery"'));
        self::assertSame(2, substr_count($html, 'class="ef-news-img"'));

        $gallery = $xpath->query('//div[@class="ef-news-gallery"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $gallery);

        // IN-FLOW: the gallery lives INSIDE the body <article> (the text column),
        // i.e. it is a DESCENDANT of .article-layout — never a sibling of it, and
        // never inside the <aside>.
        $layout = $xpath
            ->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' article-layout ')]")
            ->item(0);
        $body = $xpath->query('//article[contains(concat(" ", normalize-space(@class), " "), " article ")]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $layout);
        self::assertInstanceOf(\DOMElement::class, $body);

        // parent IS the body element (the article text column)
        self::assertSame($body, $gallery->parentNode, 'gallery must be a CHILD of the body element');
        // and that body lives inside .article-layout → gallery is a descendant
        self::assertSame(1, $xpath->query('.//div[@class="ef-news-gallery"]', $layout)->length, 'gallery must live INSIDE the .article-layout column');
        // never in the <aside>
        self::assertSame(0, $xpath->query('//aside//div[@class="ef-news-gallery"]')->length, 'gallery must NOT live in the aside');

        // POSITION 'after': the gallery is the body's LAST element child.
        $last = $body->lastChild;
        while ($last !== null && !$last instanceof \DOMElement) {
            $last = $last->previousSibling;
        }
        self::assertSame($gallery, $last, "gallery must be the body's LAST child for position 'after'");
    }

    public function test_gallery_before_in_multicolumn_layout_is_first_child_of_body(): void
    {
        $renderer = $this->renderer($this->templateWithAside());
        $html = $renderer->renderArticle($this->itemWithGallery('before'));
        $doc = (new Html5())->parse($html);
        $xpath = new \DOMXPath($doc);

        $gallery = $xpath->query('//div[@class="ef-news-gallery"]')->item(0);
        $body = $xpath->query('//article[contains(concat(" ", normalize-space(@class), " "), " article ")]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $gallery);
        self::assertInstanceOf(\DOMElement::class, $body);

        // child of the body element (in the text column)
        self::assertSame($body, $gallery->parentNode);

        // POSITION 'before': the gallery is the body's FIRST element child.
        $first = $body->firstChild;
        while ($first !== null && !$first instanceof \DOMElement) {
            $first = $first->nextSibling;
        }
        self::assertSame($gallery, $first, "gallery must be the body's FIRST child for position 'before'");
    }

    public function test_gallery_simple_template_is_child_of_body_element(): void
    {
        // validTemplateHtml(): the body <article> is a direct child of <body>
        // (no column wrapper). The gallery still goes INSIDE the body element
        // (its last child for 'after'), not as a sibling of it.
        $renderer = $this->renderer(); // default = no .article-layout
        $html = $renderer->renderArticle($this->itemWithGallery('after'));
        $doc = (new Html5())->parse($html);
        $xpath = new \DOMXPath($doc);

        $gallery = $xpath->query('//div[@class="ef-news-gallery"]')->item(0);
        $body = $xpath->query('//article[contains(concat(" ", normalize-space(@class), " "), " article ")]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $gallery);
        self::assertInstanceOf(\DOMElement::class, $body);

        // the gallery's parent IS the body <article> element (a child of it)
        self::assertSame($body, $gallery->parentNode, 'gallery must be a CHILD of the body element');

        // POSITION 'after': gallery is the body's LAST element child.
        $last = $body->lastChild;
        while ($last !== null && !$last instanceof \DOMElement) {
            $last = $last->previousSibling;
        }
        self::assertSame($gallery, $last, "gallery must be the body's LAST child for position 'after'");
    }

    // ---------------------------------------------------------------------
    // Phase C — prev/next nav. A <nav class="ef-news-prevnext"> is inserted as
    // a sibling immediately AFTER the .aside-card block, with a "previous
    // (older)" and/or "next (newer)" link (relative /<slug> + title_short).
    // ---------------------------------------------------------------------

    /**
     * Same valid skin but the article aside carries the CTA .aside-card the
     * prev/next nav anchors on (mirrors the real site _news-template.html, where
     * the card lives in an <aside> inside .article-layout).
     */
    private function templateWithAside(): string
    {
        return <<<'HTML'
<!doctype html>
<html data-news-template lang="ru">
<head>
  <meta charset="utf-8">
  <title data-nf="title_tag">placeholder title</title>
  <meta name="description" data-nf="excerpt" content="placeholder desc">
  <link rel="canonical" data-nf="url" href="https://example.test/placeholder">
  <script type="application/ld+json" data-nf="jsonld">{}</script>
</head>
<body>
  <nav class="topnav">CHROME NAV</nav>
  <div class="crumbs"><span class="cur" data-nf="title_short">placeholder short</span></div>
  <header class="page-head">
    <span class="tag-pill" data-nf="meta_line">placeholder meta</span>
    <h1 data-nf="title">placeholder h1</h1>
  </header>
  <figure class="article-cover"><img data-nf="cover" src="placeholder.jpg" alt=""></figure>
  <div class="article-layout">
    <article class="article" data-nf="body"><p>placeholder body</p></article>
    <aside>
      <div class="aside-card" style="background:linear-gradient(135deg,#f60,#f83)">
        <h4>Нужна помощь с туром?</h4>
        <a class="btn btn-white" href="/contacts">Оставить заявку</a>
      </div>
    </aside>
  </div>
  <template data-news-card>
    <a class="post" data-nf="url" href="/placeholder">
      <span class="media"><img data-nf="cover" src="placeholder.jpg" alt=""></span>
      <span class="date" data-nf="meta_line">placeholder date</span>
      <h3 data-nf="title_short">placeholder card title</h3>
    </a>
  </template>
  <footer class="site-footer">CHROME FOOTER</footer>
</body>
</html>
HTML;
    }

    /** @return array<string,mixed> a neighbour item with the given slug/title. */
    private function neighbourItem(string $slug, string $title, string $short = ''): array
    {
        return array_merge($this->sampleItem(), [
            'id' => 'n-' . substr(sha1($slug), 0, 8),
            'slug' => $slug,
            'title' => $title,
            'title_short' => $short,
        ]);
    }

    /** Extract just the .ef-news-prevnext nav fragment from a rendered article. */
    private function prevNextFragment(string $html): string
    {
        if (preg_match('~<nav class="ef-news-prevnext"[^>]*>.*?</nav>~s', $html, $m) === 1) {
            return $m[0];
        }
        return '';
    }

    public function test_prevnext_links_carry_neighbour_date_in_ru_format(): void
    {
        $renderer = $this->renderer();
        // neighbourItem() inherits date='2026-06-11' from sampleItem(); override next.
        $prev = $this->neighbourItem('staraya-novost', 'Старая новость', 'Старая');
        $next = array_merge(
            $this->neighbourItem('novaya-novost', 'Новая новость', 'Новая'),
            ['date' => '2026-07-01']
        );

        $nav = $this->prevNextFragment($renderer->renderArticle($this->sampleItem(), $prev, $next));

        self::assertSame(2, substr_count($nav, 'ef-news-prevnext-date'), 'each link carries one date span');
        self::assertStringContainsString('11 июня 2026', $nav, 'older neighbour date, ru-formatted');
        self::assertStringContainsString('1 июля 2026', $nav, 'newer neighbour date, ru-formatted');
    }

    public function test_prevnext_with_both_neighbours_renders_two_links_after_aside_card(): void
    {
        $renderer = $this->renderer($this->templateWithAside());
        $prev = $this->neighbourItem('staraya-novost', 'Старая новость', 'Старая');
        $next = $this->neighbourItem('novaya-novost', 'Новая новость', 'Новая');

        $html = $renderer->renderArticle($this->sampleItem(), $prev, $next);

        // exactly one nav, two links
        self::assertSame(1, substr_count($html, 'class="ef-news-prevnext"'));
        $nav = $this->prevNextFragment($html);
        self::assertNotSame('', $nav, 'a prev/next nav must be present');
        self::assertSame(2, substr_count($nav, '<a '), 'both neighbour links present');

        // correct relative hrefs to OLDER (prev) and NEWER (next)
        self::assertStringContainsString('href="/staraya-novost"', $nav);
        self::assertStringContainsString('href="/novaya-novost"', $nav);
        // titles (title_short preferred)
        self::assertStringContainsString('Старая', $nav);
        self::assertStringContainsString('Новая', $nav);
        // direction labels
        self::assertStringContainsString('Предыдущая', $nav);
        self::assertStringContainsString('Следующая', $nav);

        // POSITION: nav must come AFTER the .aside-card block.
        $asidePos = strpos($html, 'class="aside-card"');
        $navPos = strpos($html, 'class="ef-news-prevnext"');
        self::assertNotFalse($asidePos);
        self::assertNotFalse($navPos);
        self::assertGreaterThan($asidePos, $navPos, 'nav must render AFTER the .aside-card');
    }

    public function test_prevnext_is_sibling_immediately_after_aside_card(): void
    {
        $renderer = $this->renderer($this->templateWithAside());
        $next = $this->neighbourItem('novaya-novost', 'Новая новость', 'Новая');

        $html = $renderer->renderArticle($this->sampleItem(), null, $next);
        $doc = (new Html5())->parse($html);
        $xpath = new \DOMXPath($doc);

        $aside = $xpath
            ->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' aside-card ')]")
            ->item(0);
        self::assertInstanceOf(\DOMElement::class, $aside);

        // walk forward over non-element nodes (whitespace text) to the next element
        $sibling = $aside->nextSibling;
        while ($sibling !== null && !$sibling instanceof \DOMElement) {
            $sibling = $sibling->nextSibling;
        }
        self::assertInstanceOf(\DOMElement::class, $sibling, 'an element sibling must follow the aside-card');
        self::assertSame('nav', $sibling->tagName);
        self::assertStringContainsString('ef-news-prevnext', $sibling->getAttribute('class'));
    }

    public function test_prevnext_with_only_older_neighbour_renders_only_previous_link(): void
    {
        $renderer = $this->renderer($this->templateWithAside());
        $prev = $this->neighbourItem('staraya-novost', 'Старая новость', 'Старая');

        $html = $renderer->renderArticle($this->sampleItem(), $prev, null);
        $nav = $this->prevNextFragment($html);

        self::assertNotSame('', $nav, 'nav present when only the older neighbour exists');
        self::assertSame(1, substr_count($nav, '<a '), 'only one link');
        self::assertStringContainsString('href="/staraya-novost"', $nav);
        self::assertStringContainsString('Предыдущая', $nav);
        self::assertStringNotContainsString('Следующая', $nav);
    }

    public function test_prevnext_with_only_newer_neighbour_renders_only_next_link(): void
    {
        $renderer = $this->renderer($this->templateWithAside());
        $next = $this->neighbourItem('novaya-novost', 'Новая новость', 'Новая');

        $html = $renderer->renderArticle($this->sampleItem(), null, $next);
        $nav = $this->prevNextFragment($html);

        self::assertNotSame('', $nav, 'nav present when only the newer neighbour exists');
        self::assertSame(1, substr_count($nav, '<a '), 'only one link');
        self::assertStringContainsString('href="/novaya-novost"', $nav);
        self::assertStringContainsString('Следующая', $nav);
        self::assertStringNotContainsString('Предыдущая', $nav);
    }

    public function test_prevnext_with_no_neighbours_emits_no_nav(): void
    {
        $renderer = $this->renderer($this->templateWithAside());

        // explicit nulls (the default) → no nav at all
        $html = $renderer->renderArticle($this->sampleItem(), null, null);
        self::assertStringNotContainsString('ef-news-prevnext', $html, 'no nav when neither neighbour exists');

        // and the back-compat single-arg call (existing callers/tests) also emits none
        $html2 = $renderer->renderArticle($this->sampleItem());
        self::assertStringNotContainsString('ef-news-prevnext', $html2);
    }

    public function test_prevnext_titles_and_hrefs_are_escaped(): void
    {
        $renderer = $this->renderer($this->templateWithAside());
        // hostile title + slug-ish href: the DOM API must escape both so no raw
        // markup/attribute breakout survives.
        $prev = $this->neighbourItem(
            'a"><img src=x onerror=alert(1)>',
            'Older <b>bold</b> & "quoted"',
            '', // empty short ⇒ falls back to title (exercises the fallback + escape)
        );

        $html = $renderer->renderArticle($this->sampleItem(), $prev, null);
        $nav = $this->prevNextFragment($html);

        // the title text is escaped (no LIVE <b> / <img> element injected — the
        // hostile markup survives only as escaped entity text).
        self::assertStringNotContainsString('<b>bold</b>', $nav, 'title HTML must be escaped, not live');
        self::assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $nav, 'title HTML escaped to entities');
        self::assertStringContainsString('&amp;', $nav, 'ampersand escaped');

        // the href is attribute-safe: the hostile double-quote is escaped to
        // &quot;, so the value can NEVER break out of its quoted attribute (the
        // would-be <img> sits INSIDE the still-open value, inert).
        self::assertStringContainsString('href="/a&quot;', $nav, 'href quote escaped — no attribute breakout');

        // and parsing the page back confirms the link is a single <a> whose href
        // is exactly the raw (un-escaped) string — no injected element exists.
        $doc = (new Html5())->parse($html);
        $xpath = new \DOMXPath($doc);
        $anchors = $xpath->query('//nav[@class="ef-news-prevnext"]//a');
        self::assertSame(1, $anchors->length, 'exactly one anchor, no injected element');
        self::assertInstanceOf(\DOMElement::class, $anchors->item(0));
        self::assertSame('/a"><img src=x onerror=alert(1)>', $anchors->item(0)->getAttribute('href'));
        // no live <img> anywhere in the nav subtree (breakout would have created one)
        self::assertSame(0, $xpath->query('//nav[@class="ef-news-prevnext"]//img')->length);

        // overall page is still well-formed (round-trips through the parser)
        $html5 = new Html5();
        self::assertSame($html, $html5->serialize($html5->parse($html)));
    }

    public function test_prevnext_falls_back_to_body_when_no_aside_card(): void
    {
        // validTemplateHtml() has NO .aside-card → the nav still renders, appended
        // at end of <body> (graceful fallback), so the link is never lost.
        $renderer = $this->renderer(); // default template = no aside-card
        $next = $this->neighbourItem('novaya-novost', 'Новая новость', 'Новая');

        $html = $renderer->renderArticle($this->sampleItem(), null, $next);

        self::assertStringContainsString('class="ef-news-prevnext"', $html, 'nav still emitted without an aside-card anchor');
        self::assertStringContainsString('href="/novaya-novost"', $html);
    }

    // ---------------------------------------------------------------------
    // Prev/next neighbour COVER as background: when a neighbour item has a
    // non-empty cover, its prev/next link carries the cover as a background
    // image (inline background-image:url('<cover>')) + an
    // `ef-news-prevnext-link--bg` marker class so the CSS lays a dark overlay
    // and renders white/readable text. A neighbour without a cover gets neither.
    // ---------------------------------------------------------------------

    public function test_prevnext_link_with_cover_carries_background_and_marker_class(): void
    {
        $renderer = $this->renderer($this->templateWithAside());
        // neighbourItem() inherits sampleItem()'s cover, so override it to a
        // distinct value to assert it ends up in the background-image.
        $next = array_merge(
            $this->neighbourItem('novaya-novost', 'Новая новость', 'Новая'),
            ['cover' => 'assets/img/neighbour-cover.jpg'],
        );

        $html = $renderer->renderArticle($this->sampleItem(), null, $next);
        $doc = (new Html5())->parse($html);
        $xpath = new \DOMXPath($doc);

        $a = $xpath->query('//nav[@class="ef-news-prevnext"]//a')->item(0);
        self::assertInstanceOf(\DOMElement::class, $a);

        // marker class present (alongside the base + direction classes)
        $cls = ' ' . $a->getAttribute('class') . ' ';
        self::assertStringContainsString(' ef-news-prevnext-link--bg ', $cls, 'cover link must carry the --bg marker class');

        // inline background-image carries the (validated) cover URL
        self::assertSame(
            "background-image:url('assets/img/neighbour-cover.jpg')",
            $a->getAttribute('style'),
            'cover link must set background-image to the neighbour cover',
        );
    }

    public function test_prevnext_link_without_cover_has_no_background(): void
    {
        $renderer = $this->renderer($this->templateWithAside());
        // neighbour with an explicitly empty cover → no bg, no marker class
        $next = array_merge(
            $this->neighbourItem('novaya-novost', 'Новая новость', 'Новая'),
            ['cover' => ''],
        );

        $html = $renderer->renderArticle($this->sampleItem(), null, $next);
        $doc = (new Html5())->parse($html);
        $xpath = new \DOMXPath($doc);

        $a = $xpath->query('//nav[@class="ef-news-prevnext"]//a')->item(0);
        self::assertInstanceOf(\DOMElement::class, $a);

        // no marker class, no inline background style
        self::assertStringNotContainsString('ef-news-prevnext-link--bg', $a->getAttribute('class'), 'coverless link must NOT carry the --bg marker class');
        self::assertStringNotContainsString('background-image', $a->getAttribute('style'), 'coverless link must NOT set a background-image');
        self::assertSame('', $a->getAttribute('style'), 'coverless link carries no inline style');
    }

    public function test_prevnext_cover_background_url_is_attribute_safe(): void
    {
        // a hostile cover value must stay inert inside the quoted style attribute
        // (the DOM API escapes it; no markup/attribute breakout survives).
        $renderer = $this->renderer($this->templateWithAside());
        $next = array_merge(
            $this->neighbourItem('novaya-novost', 'Новая новость', 'Новая'),
            ['cover' => "x'); background:url('y"],
        );

        $html = $renderer->renderArticle($this->sampleItem(), null, $next);
        $doc = (new Html5())->parse($html);
        $xpath = new \DOMXPath($doc);

        $a = $xpath->query('//nav[@class="ef-news-prevnext"]//a')->item(0);
        self::assertInstanceOf(\DOMElement::class, $a);

        // the raw value round-trips exactly inside the style attribute — no extra
        // anchors/elements were injected (breakout would have created some).
        self::assertSame(
            "background-image:url('x'); background:url('y')",
            $a->getAttribute('style'),
        );
        self::assertSame(1, $xpath->query('//nav[@class="ef-news-prevnext"]//a')->length, 'exactly one anchor — no injected element');

        // page still round-trips through the parser (well-formed)
        $html5 = new Html5();
        self::assertSame($html, $html5->serialize($html5->parse($html)));
    }

    /** Wrap a detached element in its own throwaway document for serialization. */
    private function wrap(\DOMElement $el): \DOMDocument
    {
        $doc = new \DOMDocument();
        $doc->appendChild($doc->importNode($el, true));
        return $doc;
    }
}
