<?php

declare(strict_types=1);

namespace EditFront\Http\Middleware;

use EditFront\Http\UrlHelper;
use EditFront\I18n\LangResolver;
use EditFront\I18n\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves the UI language for the request and sets it on the Translator before
 * the route handler renders Twig (§8.1). On a valid `?lang=`, it sets the
 * cms_lang cookie and 302s to the same URL without the query — the contract
 * "?lang sets cookie + 302" lives here in code, not just in acceptance.
 */
final class LocaleMiddleware implements MiddlewareInterface
{
    private const COOKIE_MAX_AGE = 31536000; // 1 year

    public function __construct(
        private readonly LangResolver $resolver,
        private readonly Translator $translator,
        private readonly UrlHelper $url,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $forced = $this->resolver->queryLang($request);
        if ($forced !== null) {
            $this->translator->setCurrent($forced);
            return $this->redirectWithCookie($request, $forced);
        }

        $this->translator->setCurrent($this->resolver->resolve($request));
        return $handler->handle($request);
    }

    private function redirectWithCookie(ServerRequestInterface $request, string $lang): ResponseInterface
    {
        // clean URL: same path, query minus ?lang (idempotent — no redirect loop)
        parse_str($request->getUri()->getQuery(), $params);
        unset($params['lang']);
        $query = http_build_query($params);

        // never redirect to a client-supplied path verbatim: a `//host`-prefixed
        // path would become a protocol-relative open redirect. Keep it same-origin.
        $path = $request->getUri()->getPath();
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
            $path = $this->url->path('');
        }
        $target = $path . ($query !== '' ? '?' . $query : '');

        $cookie = LangResolver::COOKIE . '=' . rawurlencode($lang)
            . '; Path=' . $this->url->cookiePath()
            . '; Max-Age=' . self::COOKIE_MAX_AGE
            . '; SameSite=Lax'
            . ($this->isHttps($request) ? '; Secure' : '');

        // fresh empty response — never run the handler just to discard its body
        return (new \Slim\Psr7\Response())
            ->withStatus(302)
            ->withHeader('Location', $target)
            ->withAddedHeader('Set-Cookie', $cookie)
            ->withHeader('Cache-Control', 'no-store');
    }

    private function isHttps(ServerRequestInterface $request): bool
    {
        if ($request->getUri()->getScheme() === 'https') {
            return true;
        }
        if (strtolower($request->getHeaderLine('X-Forwarded-Proto')) === 'https') {
            return true;
        }
        $https = $request->getServerParams()['HTTPS'] ?? '';
        return $https !== '' && strtolower((string) $https) !== 'off';
    }
}
