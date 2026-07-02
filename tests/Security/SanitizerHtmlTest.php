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
        $out = $this->sanitizer->sanitize('<div class="wrap"><span style="color:red">text <b>bold</b></span></div>');
        $this->assertStringNotContainsString('<div', $out);
        $this->assertStringNotContainsString('<span', $out);
        $this->assertStringContainsString('text <b>bold</b>', $out);
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
}
