<?php
declare(strict_types=1);

namespace EditFront\Tests\News;

use EditFront\Document\Annotator;
use EditFront\Document\Html5;
use EditFront\News\NewsBodySanitizer;
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

/**
 * End-to-end proof that NewsPublisher::save sanitizes body_html via the injected
 * NewsBodySanitizer (the 11th ctor param): <script> stripped, unknown tags
 * unwrapped, and the lead/dots/callout/inline class hooks preserved — on the
 * STORED item AND in the generated article page.
 *
 * NOTE (corrected vs. plan): the article page legitimately carries ONE
 * <script type="application/ld+json"> (JSON-LD, emitted by NewsRenderer). So we
 * do NOT assert "no <script anywhere" on the full page — that would fail on the
 * JSON-LD block. Instead we assert the body <script> is gone from the stored
 * body_html and that the article's only <script> is the JSON-LD one (no
 * executable / non-ld+json script survives).
 */
final class PublisherSanitizeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = ef2_temp_dir('news-pub');
        // Minimal template page with all required article + card slots so validate() passes.
        $template = <<<'HTML'
<!doctype html>
<html data-news-template>
<head>
<title data-nf="title_tag"></title>
<meta name="description" data-nf="excerpt" content="">
<link rel="canonical" data-nf="url" href="">
<meta property="og:image" data-nf="cover_og" content="">
<script type="application/ld+json" data-nf="jsonld"></script>
</head>
<body>
<span class="cur" data-nf="title_short"></span>
<span class="tag-pill" data-nf="meta_line"></span>
<h1 data-nf="title"></h1>
<img class="cover" data-nf="cover" src="" alt="">
<article class="article" data-nf="body"></article>
<template data-news-card>
  <a class="post" data-nf="url" href="">
    <img data-nf="cover" src="" alt="">
    <span class="date" data-nf="meta_line"></span>
    <h3 data-nf="title_short"></h3>
  </a>
</template>
</body>
</html>
HTML;
        file_put_contents($this->root . '/_news-template.html', $template);
    }

    private function config(): Config
    {
        return ef2_test_config([
            'storage_dir' => $this->root,
            'site_root'   => $this->root,
        ]);
    }

    private function publisher(): NewsPublisher
    {
        $config    = $this->config();
        $html5     = new Html5();
        $guard     = new PathGuard($config);
        $storage   = new FileStorage($config, $guard);
        $store     = new NewsStore($config);
        $pages     = new PagesIndex($config);
        $annotator = new Annotator();
        $template  = new NewsTemplate($store, $storage, $html5);
        $renderer  = new NewsRenderer($template, $annotator, $html5, $store);
        $slug      = new NewsSlug($pages);
        $backups   = new BackupService($config);
        $sanitizer = new NewsBodySanitizer(new SanitizerHtml(new SanitizerUrl(), new SanitizerCss()), new SanitizerUrl());

        return new NewsPublisher(
            $store, $renderer, $template, $slug, $storage,
            $backups, $pages, $html5, $annotator, $sanitizer, new NullLogger()
        );
    }

    public function test_save_strips_script_from_body_html(): void
    {
        $result = $this->publisher()->save([
            'title'     => 'Тест санитайзера',
            'category'  => 'Компания',
            'date'      => '2026-06-11',
            'excerpt'   => 'short',
            'body_html' => '<p>safe</p><script>alert(1)</script><h2>head</h2>',
        ]);

        $store  = new NewsStore($this->config());
        $stored = $store->find($result['id']);

        self::assertNotNull($stored);
        self::assertStringNotContainsString('<script', $stored['body_html']);
        self::assertStringNotContainsString('alert(1)', $stored['body_html']);
        self::assertStringContainsString('safe', $stored['body_html']);
        self::assertStringContainsString('head', $stored['body_html']);
    }

    public function test_save_unwraps_unknown_tag_keeping_text(): void
    {
        $result = $this->publisher()->save([
            'title'     => 'Тест unwrap',
            'category'  => 'Компания',
            'date'      => '2026-06-12',
            'excerpt'   => 'short',
            'body_html' => '<div><p>kept</p></div>',
        ]);

        $stored = (new NewsStore($this->config()))->find($result['id']);
        self::assertNotNull($stored);
        self::assertStringNotContainsString('<div', $stored['body_html']);
        self::assertStringContainsString('<p>kept</p>', $stored['body_html']);
    }

    public function test_save_preserves_lead_dots_callout_class_hooks(): void
    {
        $result = $this->publisher()->save([
            'title'     => 'Классы тела',
            'category'  => 'Компания',
            'date'      => '2026-06-13',
            'excerpt'   => 'short',
            'body_html' => '<p class="lead">Лид.</p>'
                . '<ul class="dots"><li>один</li></ul>'
                . '<p class="callout">Врезка.</p>'
                . '<script>alert("evil")</script>',
        ]);

        $stored = (new NewsStore($this->config()))->find($result['id']);
        self::assertNotNull($stored);
        self::assertStringContainsString('class="lead"', $stored['body_html']);
        self::assertStringContainsString('class="dots"', $stored['body_html']);
        self::assertStringContainsString('class="callout"', $stored['body_html']);
        // body <script> is stripped from the stored body — not just the page
        self::assertStringNotContainsString('<script', $stored['body_html']);
        self::assertStringNotContainsString('alert("evil")', $stored['body_html']);

        // the published article page keeps the class hooks too
        $store   = new NewsStore($this->config());
        $page    = $store->find($result['id'])['slug'] . '.html';
        $article = (string) file_get_contents($this->root . '/' . $page);
        self::assertStringContainsString('class="lead"', $article);
        self::assertStringContainsString('class="dots"', $article);
        self::assertStringContainsString('class="callout"', $article);

        // the user's body <script> never makes it onto the page
        self::assertStringNotContainsString('alert("evil")', $article);

        // CORRECTED ASSERTION (delta #3): the article legitimately carries one
        // JSON-LD <script type="application/ld+json">. So instead of "zero
        // scripts anywhere", assert the ONLY <script> on the page is the JSON-LD
        // block — i.e. no executable / non-ld+json script survived sanitization.
        self::assertSame(
            1,
            substr_count($article, '<script'),
            'article should contain exactly one <script> (the JSON-LD block)'
        );
        self::assertStringContainsString('<script type="application/ld+json"', $article);
    }

    /**
     * Phase A round-trip: a body inline <img class="ef-news-img"> survives the
     * save → it stays in the STORED item AND appears in the published article
     * page (the renderer drops sanitized body_html into the body slot).
     */
    public function test_save_keeps_body_image_in_stored_item_and_article(): void
    {
        $result = $this->publisher()->save([
            'title'     => 'Картинка в теле',
            'category'  => 'Компания',
            'date'      => '2026-06-20',
            'excerpt'   => 'short',
            'body_html' => '<p>before <img src="/images/uploads/inline.webp" '
                . 'class="ef-news-img" alt="подпись" loading="lazy"> after</p>'
                . '<p><img src="javascript:alert(1)" class="ef-news-img"></p>',
        ]);

        $stored = (new NewsStore($this->config()))->find($result['id']);
        self::assertNotNull($stored);

        // the safe gallery image survives in the stored body with its hook class
        self::assertStringContainsString('<img', $stored['body_html']);
        self::assertStringContainsString('src="/images/uploads/inline.webp"', $stored['body_html']);
        self::assertStringContainsString('class="ef-news-img"', $stored['body_html']);
        self::assertStringContainsString('loading="lazy"', $stored['body_html']);
        // the javascript: src image never carries a dangerous src
        self::assertStringNotContainsString('javascript:', $stored['body_html']);
        self::assertStringNotContainsString('alert(1)', $stored['body_html']);

        // and it shows up on the published article page
        $page    = $stored['slug'] . '.html';
        $article = (string) file_get_contents($this->root . '/' . $page);
        self::assertStringContainsString('src="/images/uploads/inline.webp"', $article);
        self::assertStringContainsString('ef-news-img', $article);
        self::assertStringNotContainsString('javascript:', $article);
    }
}
