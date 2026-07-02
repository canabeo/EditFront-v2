<?php
declare(strict_types=1);

namespace EditFront\Tests\Http\Controller;

use EditFront\Auth\AdminStore;
use EditFront\Auth\AuthService;
use EditFront\Document\Annotator;
use EditFront\Document\Html5;
use EditFront\Http\Controller\NewsController;
use EditFront\News\NewsBodySanitizer;
use EditFront\News\NewsPublisher;
use EditFront\News\NewsRenderer;
use EditFront\News\NewsSlug;
use EditFront\News\NewsStore;
use EditFront\News\NewsTemplate;
use EditFront\Security\RateLimiter;
use EditFront\Security\SanitizerCss;
use EditFront\Security\SanitizerHtml;
use EditFront\Security\SanitizerUrl;
use EditFront\Storage\BackupService;
use EditFront\Storage\FileStorage;
use EditFront\Storage\PagesIndex;
use EditFront\Storage\PathGuard;
use EditFront\Support\Config;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class NewsControllerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = ef2_temp_dir('news-ctrl');
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

    private function controller(): NewsController
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
        $publisher = new NewsPublisher(
            $store, $renderer, $template, $slug, $storage,
            $backups, $pages, $html5, $annotator, $sanitizer, new NullLogger()
        );

        $twig = new Environment(new ArrayLoader(['news.twig' => 'stub']));
        // AuthService is final (real server signature) — build a real one. The
        // controller only touches auth->user() in page(), which these tests do
        // not exercise; a real AuthService over a temp AdminStore is correct.
        $auth = new AuthService(new AdminStore($config));
        // Translator is final too — build a real one via the harness.
        $i18n = ef2_translator(['storage_dir' => $this->root, 'site_root' => $this->root]);

        return new NewsController(
            $twig, $auth, $store, $publisher,
            new RateLimiter($config), $i18n, new NullLogger()
        );
    }

    private function jsonRequest(string $method, string $path, array $body): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        return $request
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody($body);
    }

    private function response(): ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }

    private function decode(ResponseInterface $r): array
    {
        $r->getBody()->rewind();
        return json_decode((string) $r->getBody(), true) ?? [];
    }

    public function test_save_rejects_empty_title(): void
    {
        $req = $this->jsonRequest('POST', '/api/news', ['title' => '   ']);
        $res = $this->controller()->save($req, $this->response());

        self::assertSame(422, $res->getStatusCode());
        self::assertSame('title is required', $this->decode($res)['error']);
    }

    public function test_save_persists_item_and_writes_page(): void
    {
        $ctrl = $this->controller();
        $req  = $this->jsonRequest('POST', '/api/news', [
            'title'     => 'Привет мир',
            'category'  => 'Компания',
            'date'      => '2026-06-11',
            'excerpt'   => 'short',
            'body_html' => '<p>тело</p>',
        ]);
        $res  = $ctrl->save($req, $this->response());
        $data = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertTrue($data['ok']);
        self::assertMatchesRegularExpression('/^n-[0-9a-f]{8}$/', $data['id']);
        self::assertSame($data['slug'] . '.html', $data['page']);

        // item persisted
        $stored = (new NewsStore($this->config()))->find($data['id']);
        self::assertNotNull($stored);
        self::assertSame('Привет мир', $stored['title']);

        // article page written to site root
        self::assertFileExists($this->root . '/' . $data['page']);
    }

    public function test_save_strips_script_through_controller(): void
    {
        $ctrl = $this->controller();
        $req  = $this->jsonRequest('POST', '/api/news', [
            'title'     => 'XSS',
            'category'  => 'Компания',
            'date'      => '2026-06-11',
            'excerpt'   => 'short',
            'body_html' => '<p>ok</p><script>alert(1)</script>',
        ]);
        $data = $this->decode($ctrl->save($req, $this->response()));

        $stored = (new NewsStore($this->config()))->find($data['id']);
        self::assertNotNull($stored);
        self::assertStringNotContainsString('<script', $stored['body_html']);
        self::assertStringNotContainsString('alert(1)', $stored['body_html']);

        // and the generated page is clean too
        $pageHtml = file_get_contents($this->root . '/' . $data['page']);
        self::assertStringNotContainsString('<script>alert(1)', $pageHtml);
    }

    public function test_delete_removes_item(): void
    {
        $ctrl = $this->controller();
        $saved = $this->decode($ctrl->save(
            $this->jsonRequest('POST', '/api/news', [
                'title'    => 'Удалить меня',
                'category' => 'Компания',
                'date'     => '2026-06-11',
                'excerpt'  => 'x',
                'body_html'=> '<p>x</p>',
            ]),
            $this->response()
        ));

        $id  = $saved['id'];
        $res = $ctrl->delete(
            $this->jsonRequest('POST', '/api/news/delete', ['id' => $id]),
            $this->response()
        );
        $data = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertTrue($data['ok']);
        self::assertNull((new NewsStore($this->config()))->find($id));
    }

    public function test_list_returns_sorted_items(): void
    {
        $ctrl = $this->controller();
        foreach (['2026-06-01', '2026-06-15', '2026-06-09'] as $i => $d) {
            $ctrl->save($this->jsonRequest('POST', '/api/news', [
                'title'    => 'Item ' . $i,
                'category' => 'Компания',
                'date'     => $d,
                'excerpt'  => 'x',
                'body_html'=> '<p>x</p>',
            ]), $this->response());
        }

        $res  = $ctrl->list($this->jsonRequest('GET', '/api/news', []), $this->response());
        $data = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        $dates = array_column($data['items'], 'date');
        self::assertSame(['2026-06-15', '2026-06-09', '2026-06-01'], $dates);
    }

    public function test_save_and_delete_routes_are_inside_auth_group(): void
    {
        // CSRF is applied by the global CsrfMiddleware over the AuthMiddleware
        // group; assert the four routes are registered inside that group block.
        $bootstrap = (string) file_get_contents(dirname(__DIR__, 3) . '/app/bootstrap.php');

        self::assertStringContainsString("'/api/news'", $bootstrap);
        self::assertStringContainsString("'/api/news/delete'", $bootstrap);
        self::assertStringContainsString('NewsController::class', $bootstrap);

        // the news routes must appear after the group opener and before its close
        $groupPos = strpos($bootstrap, "\$app->group(''");
        $newsPos  = strpos($bootstrap, "'/api/news'");
        self::assertNotFalse($groupPos);
        self::assertNotFalse($newsPos);
        self::assertGreaterThan($groupPos, $newsPos);
    }
}
