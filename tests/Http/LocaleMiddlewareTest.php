<?php

declare(strict_types=1);

namespace EditFront\Tests\Http;

use EditFront\Http\Middleware\LocaleMiddleware;
use EditFront\Http\UrlHelper;
use EditFront\I18n\LangResolver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/** §8.1/§8.6 — ?lang sets cookie + 302s to the clean SAME-ORIGIN url. */
final class LocaleMiddlewareTest extends TestCase
{
    private function middleware(): LocaleMiddleware
    {
        $config = ef2_test_config();
        return new LocaleMiddleware(new LangResolver(ef2_translator()), ef2_translator(), new UrlHelper($config));
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $r = new Response();
                $r->getBody()->write('HANDLED');
                return $r;
            }
        };
    }

    /** Build a request whose URI query string is real (so the ?lang-strip works). */
    private function req(string $url): ServerRequestInterface
    {
        $req = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', $url);
        parse_str($req->getUri()->getQuery(), $params);
        return $req->withQueryParams($params);
    }

    public function test_lang_query_sets_cookie_and_redirects_to_clean_url(): void
    {
        $resp = $this->middleware()->process($this->req('https://x.test/cms/login?lang=ru'), $this->handler());

        $this->assertSame(302, $resp->getStatusCode());
        $this->assertSame('/cms/login', $resp->getHeaderLine('Location'));
        $this->assertStringContainsString('cms_lang=ru', $resp->getHeaderLine('Set-Cookie'));
        $this->assertStringContainsString('Path=/cms', $resp->getHeaderLine('Set-Cookie'));
        $this->assertStringContainsString('SameSite=Lax', $resp->getHeaderLine('Set-Cookie'));
    }

    public function test_other_query_params_are_preserved(): void
    {
        $resp = $this->middleware()->process($this->req('https://x.test/cms/edit?page=about.html&lang=en'), $this->handler());
        $this->assertSame('/cms/edit?page=about.html', $resp->getHeaderLine('Location'));
    }

    public function test_redirect_is_never_protocol_relative(): void
    {
        // security invariant: the Location must never be protocol-relative (//host)
        // — Slim's Uri normalizes a leading '//' in the path, and LocaleMiddleware
        // additionally rebuilds any non-rooted / '//'-prefixed path from the trusted
        // base, so an open redirect is not reachable from either layer.
        $req = $this->req('https://x.test/cms/?lang=ru');
        $req = $req->withUri($req->getUri()->withPath('//evil.com/cms/'));
        $resp = $this->middleware()->process($req, $this->handler());

        $loc = $resp->getHeaderLine('Location');
        $this->assertStringStartsNotWith('//', $loc, 'Location must not be protocol-relative');
        $this->assertStringStartsWith('/', $loc, 'Location must stay same-origin');
    }

    public function test_no_lang_passes_through_to_handler(): void
    {
        $resp = $this->middleware()->process($this->req('https://x.test/cms/login'), $this->handler());
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('HANDLED', (string) $resp->getBody());
    }
}
