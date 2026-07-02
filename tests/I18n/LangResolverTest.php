<?php

declare(strict_types=1);

namespace EditFront\Tests\I18n;

use EditFront\I18n\LangResolver;
use EditFront\I18n\Translator;
use PHPUnit\Framework\TestCase;

/**
 * §8.1 — strict priority ?lang → cookie → Accept-Language → default 'en'.
 * Step 3 is the STRICT /^ru/ rule, not q-weight best-match; 'en' is the neutral
 * fallback (§8.0 — NOT changed back to 'ru').
 */
final class LangResolverTest extends TestCase
{
    private LangResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new LangResolver(ef2_translator());
    }

    public function test_query_lang_wins(): void
    {
        $req = ef2_request('/cms/', ['lang' => 'ru'], ['cms_lang' => 'en'], 'en-US');
        $this->assertSame('ru', $this->resolver->resolve($req));
        $this->assertSame('ru', $this->resolver->queryLang($req));
    }

    public function test_unsupported_query_lang_is_ignored(): void
    {
        $req = ef2_request('/cms/', ['lang' => 'zz'], [], 'ru-RU');
        $this->assertNull($this->resolver->queryLang($req));
        $this->assertSame('ru', $this->resolver->resolve($req)); // falls through to Accept-Language
    }

    public function test_cookie_used_when_no_query(): void
    {
        $this->assertSame('ru', $this->resolver->resolve(ef2_request('/cms/', [], ['cms_lang' => 'ru'], 'en')));
        $this->assertSame('en', $this->resolver->resolve(ef2_request('/cms/', [], ['cms_lang' => 'en'], 'ru')));
    }

    public function test_garbage_cookie_falls_through(): void
    {
        $this->assertSame('ru', $this->resolver->resolve(ef2_request('/cms/', [], ['cms_lang' => 'xx'], 'ru-RU')));
    }

    public function test_accept_language_ru_prefix_only(): void
    {
        $this->assertSame('ru', $this->resolver->resolve(ef2_request('/cms/', [], [], 'ru')));
        $this->assertSame('ru', $this->resolver->resolve(ef2_request('/cms/', [], [], 'ru-RU,en;q=0.8')));
        $this->assertSame('en', $this->resolver->resolve(ef2_request('/cms/', [], [], 'en-US,ru;q=0.9'))); // first tag wins, not q
        $this->assertSame('en', $this->resolver->resolve(ef2_request('/cms/', [], [], 'de-DE')));
        $this->assertSame('en', $this->resolver->resolve(ef2_request('/cms/', [], [], '')));
    }

    public function test_default_is_en_not_ru(): void
    {
        // §8.0 — neutral fallback is en even with no signal at all
        $this->assertSame('en', $this->resolver->resolve(ef2_request('/cms/')));
    }
}
