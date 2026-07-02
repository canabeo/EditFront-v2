<?php

declare(strict_types=1);

namespace EditFront\I18n;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves the UI language (§8.1). Strict priority:
 *   ?lang=<code>  →  cookie cms_lang  →  navigator/Accept-Language  →  default 'en'
 *
 * Step 3 is the STRICT rule (not best-match q-weights): the FIRST tag of the
 * signal; /^ru/ → 'ru', everything else (en, de, unreadable, empty) → 'en'.
 * 'en' is the explicit neutral fallback, not "one of the supported". The client
 * refines by navigator (no-FOUC, §8.1) — Accept-Language is only a coarse SSR
 * proxy, navigator is the authority.
 */
final class LangResolver
{
    public const COOKIE = 'cms_lang';

    public function __construct(private readonly Translator $translator)
    {
    }

    public function resolve(ServerRequestInterface $req): string
    {
        $supported = $this->translator->supportedLanguages();

        $q = $req->getQueryParams()['lang'] ?? null;
        if (is_string($q) && in_array($q, $supported, true)) {
            // caller (LocaleMiddleware) sets the cookie + 302s to the clean URL
            return $q;
        }
        $c = $req->getCookieParams()[self::COOKIE] ?? null;
        if (is_string($c) && in_array($c, $supported, true)) {
            return $c;
        }
        $first = $this->firstTag($req->getHeaderLine('Accept-Language'));
        if ($first !== null && preg_match('/^ru/i', $first) === 1 && in_array('ru', $supported, true)) {
            return 'ru';
        }
        return Translator::DEFAULT;
    }

    /** A valid ?lang= value (caller must set cookie + 302), or null. */
    public function queryLang(ServerRequestInterface $req): ?string
    {
        $q = $req->getQueryParams()['lang'] ?? null;
        return (is_string($q) && in_array($q, $this->translator->supportedLanguages(), true)) ? $q : null;
    }

    private function firstTag(string $header): ?string
    {
        if ($header === '') {
            return null;
        }
        $top = explode(',', $header)[0] ?? '';
        $tag = strtolower(trim((string) preg_replace('/;.*/', '', $top)));
        return $tag !== '' ? $tag : null;
    }
}
