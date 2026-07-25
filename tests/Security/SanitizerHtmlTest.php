<?php

declare(strict_types=1);

namespace EditFront\Tests\Security;

use EditFront\Security\SanitizerHtml;
use PHPUnit\Framework\TestCase;

final class SanitizerHtmlTest extends TestCase
{
    private SanitizerHtml $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = ef2_sanitizer_html();
    }

    public function test_keeps_whitelisted_tags(): void
    {
        $in = '<b>b</b> <i>i</i> <u>u</u> <code>c</code> <blockquote>q</blockquote>'
            . '<ul><li>1</li></ul><h2>h</h2><p>p</p><br>';
        $out = $this->sanitizer->sanitize($in);
        foreach (['<b>', '<i>', '<u>', '<code>', '<blockquote>', '<ul>', '<li>', '<h2>', '<p>', '<br'] as $tag) {
            $this->assertStringContainsString($tag, $out);
        }
    }

    public function test_drops_dangerous_subtrees_entirely(): void
    {
        $out = $this->sanitizer->sanitize(
            'safe<script>alert(1)</script><style>*{}</style><iframe src="x"></iframe>'
            . '<svg onload="x"><circle/></svg><object data="x"></object>'
        );
        $this->assertSame('safe', trim($out));
    }

    public function test_unwraps_unknown_tags_keeping_content(): void
    {
        $out = $this->sanitizer->sanitize('<div class="wrap"><section>text <b>bold</b></section></div>');
        $this->assertStringNotContainsString('<div', $out);
        $this->assertStringNotContainsString('<section', $out);
        $this->assertStringContainsString('text <b>bold</b>', $out);
    }

    public function test_only_a_span_that_draws_something_survives(): void
    {
        // no class = carries nothing (this is what styleWithCSS and word
        // processors emit) → still unwrapped, exactly as before
        $bare = $this->sanitizer->sanitize('<span style="color:red">text <b>bold</b></span>');
        $this->assertStringNotContainsString('<span', $bare);
        $this->assertStringNotContainsString('style', $bare);
        $this->assertStringContainsString('text <b>bold</b>', $bare);

        // a class means the author put it there to draw something → kept
        $drawn = $this->sanitizer->sanitize('<span class="badge">text</span>');
        $this->assertStringContainsString('<span class="badge">', $drawn);
    }

    public function test_strips_all_attributes_except_safe_link_attrs(): void
    {
        $out = $this->sanitizer->sanitize('<b class="x" onclick="evil()" data-y="1">b</b>');
        $this->assertSame('<b>b</b>', $out);
    }

    public function test_link_href_sanitized(): void
    {
        $out = $this->sanitizer->sanitize(
            '<a href="/ok">ok</a><a href="javascript:alert(1)">bad</a><a href="java&#9;script:x">trick</a>'
        );
        $this->assertStringContainsString('href="/ok"', $out);
        $this->assertStringNotContainsString('javascript', $out);
        $this->assertStringNotContainsString('script:x', $out);
    }

    public function test_target_blank_gets_noopener(): void
    {
        $out = $this->sanitizer->sanitize('<a href="https://x.example" target="_blank" rel="evil">x</a>');
        $this->assertStringContainsString('target="_blank"', $out);
        $this->assertStringContainsString('rel="noopener noreferrer"', $out);
        $this->assertStringNotContainsString('evil', $out);
    }

    public function test_plain_text_and_empty(): void
    {
        $this->assertSame('просто текст', $this->sanitizer->sanitize('просто текст'));
        $this->assertSame('', $this->sanitizer->sanitize('  '));
        $this->assertSame('', $this->sanitizer->sanitize('<!-- comment only -->'));
    }

    /**
     * Reported from a live site: correcting a typo in a badge deleted the little
     * dot beside it. The badge is
     * `<span class="kicker"><span class="d"></span> Ошибка 404</span>` — the dot
     * IS that empty inner span, drawn by its class. text.set sends the whole
     * innerHTML through the rich pass, which used to unwrap <span> and drop
     * class, so the decoration disappeared. Every icon inside an edited button
     * or link died the same way.
     */
    public function test_rich_keeps_decorative_spans_inside_edited_text(): void
    {
        $out = $this->sanitizer->sanitize(
            '<span class="d" data-cms-id="cms-b25c79e077c4"></span> Ошибка 404'
        );

        $this->assertStringContainsString('<span', $out);
        $this->assertStringContainsString('class="d"', $out);
        $this->assertStringContainsString('Ошибка 404', $out);
        // the node stays addressable by later operations
        $this->assertStringContainsString('data-cms-id="cms-b25c79e077c4"', $out);
    }

    public function test_rich_keeps_the_class_that_draws_an_icon(): void
    {
        $out = $this->sanitizer->sanitize('Заказать тур <i class="ri-arrow-right-line"></i>');
        $this->assertStringContainsString('class="ri-arrow-right-line"', $out);
    }

    public function test_rich_still_refuses_behaviour_on_those_spans(): void
    {
        $out = $this->sanitizer->sanitize('<span class="x" onclick="evil()" style="color:red">y</span>');

        $this->assertStringContainsString('class="x"', $out);
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('evil', $out);
        $this->assertStringNotContainsString('style', $out);
    }

    public function test_rich_rejects_a_class_value_that_is_not_a_class(): void
    {
        // quotes and angle brackets have no business in a class list
        $out = $this->sanitizer->sanitize('<span class="a\'b(c)">t</span>');
        $this->assertStringNotContainsString('class=', $out);
        $this->assertStringContainsString('t', $out);
    }
}
