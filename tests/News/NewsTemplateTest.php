<?php

declare(strict_types=1);

namespace EditFront\Tests\News;

use EditFront\Document\Annotator;
use EditFront\Document\Html5;
use EditFront\News\NewsException;
use EditFront\News\NewsStore;
use EditFront\News\NewsTemplate;
use EditFront\Storage\FileStorage;
use EditFront\Storage\PathGuard;
use EditFront\Support\Config;
use PHPUnit\Framework\TestCase;

final class NewsTemplateTest extends TestCase
{
    private string $storage;
    private string $site;

    protected function setUp(): void
    {
        $this->storage = ef2_temp_dir('news-tpl-storage');
        $this->site = ef2_temp_dir('news-tpl-site');
    }

    private function config(): Config
    {
        return ef2_test_config([
            'storage_dir' => $this->storage,
            'site_root' => $this->site,
        ]);
    }

    private function store(): NewsStore
    {
        return new NewsStore($this->config());
    }

    private function fileStorage(Config $config): FileStorage
    {
        return new FileStorage($config, new PathGuard($config));
    }

    private function template(?string $templateHtml = null, string $templatePage = '_news-template.html'): NewsTemplate
    {
        $config = $this->config();
        $store = new NewsStore($config);
        $store->saveConfig([
            'template_page' => $templatePage,
            'title_suffix' => ' — Test News',
            'base_url' => 'https://example.test',
            'date_locale' => 'ru',
        ]);
        if ($templateHtml !== null) {
            $this->fileStorage($config)->createNew($templatePage, $templateHtml);
        }
        return new NewsTemplate($store, $this->fileStorage($config), new Html5());
    }

    /** Minimal but COMPLETE article skin + card with every required slot present. */
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

    public function test_missing_template_file_throws_news_exception(): void
    {
        $template = $this->template(null); // config saved, but file never created
        $this->expectException(NewsException::class);
        $this->expectExceptionMessage('news template page not found');
        $template->articleSkin();
    }

    public function test_missing_news_template_marker_throws(): void
    {
        $html = str_replace('<html data-news-template lang="ru">', '<html lang="ru">', $this->validTemplateHtml());
        $template = $this->template($html);
        $this->expectException(NewsException::class);
        $this->expectExceptionMessage('[data-news-template] marker');
        $template->articleSkin();
    }

    public function test_missing_card_template_throws(): void
    {
        // remove the entire <template data-news-card> block
        $html = preg_replace('~<template data-news-card>.*?</template>~s', '', $this->validTemplateHtml());
        self::assertIsString($html);
        $template = $this->template($html);
        $this->expectException(NewsException::class);
        $this->expectExceptionMessage('<template data-news-card>');
        $template->cardNode();
    }

    public function test_missing_required_article_slot_throws_with_name(): void
    {
        // drop the data-nf="cover" article slot only (card cover stays)
        $html = str_replace(
            '<figure class="article-cover"><img data-nf="cover" src="placeholder.jpg" alt=""></figure>',
            '<figure class="article-cover"><img src="placeholder.jpg" alt=""></figure>',
            $this->validTemplateHtml(),
        );
        $template = $this->template($html);
        $this->expectException(NewsException::class);
        $this->expectExceptionMessage('cover');
        $template->validate();
    }

    public function test_missing_required_card_slot_throws(): void
    {
        // drop the card's data-nf="title_short" (article title_short stays in .crumbs .cur)
        $html = str_replace(
            '<h3 data-nf="title_short">placeholder card title</h3>',
            '<h3>placeholder card title</h3>',
            $this->validTemplateHtml(),
        );
        $template = $this->template($html);
        $this->expectException(NewsException::class);
        $this->expectExceptionMessage('card template is missing required data-nf slots');
        $template->validate();
    }

    public function test_valid_template_passes_validation_and_exposes_nodes(): void
    {
        $template = $this->template($this->validTemplateHtml());
        $template->validate(); // must not throw

        $doc = $template->articleSkin();
        self::assertInstanceOf(\DOMDocument::class, $doc);

        $card = $template->cardNode();
        self::assertInstanceOf(\DOMElement::class, $card);
        self::assertSame('a', $card->tagName);
        self::assertStringContainsString('post', $card->getAttribute('class'));

        self::assertSame('_news-template.html', $template->templatePath());
    }

    public function test_article_skin_is_cached_same_instance(): void
    {
        $template = $this->template($this->validTemplateHtml());
        self::assertSame($template->articleSkin(), $template->articleSkin());
        self::assertSame($template->cardNode(), $template->cardNode());
    }
}
