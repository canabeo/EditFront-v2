<?php

declare(strict_types=1);

namespace EditFront\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Locks the contract the formatting toolbar relies on: every tag the toolbar
 * can produce survives the RICH sanitizer, and tag-less styling (<span style>)
 * does NOT — which is exactly why the toolbar forces styleWithCSS=false.
 */
final class SanitizerFormattingTest extends TestCase
{
    private function sanitize(string $html): string
    {
        return ef2_sanitizer_html()->sanitize($html);
    }

    public function test_inline_emphasis_survives(): void
    {
        foreach (['<b>x</b>', '<strong>x</strong>', '<i>x</i>', '<em>x</em>', '<u>x</u>'] as $html) {
            $this->assertSame($html, $this->sanitize($html));
        }
    }

    public function test_link_survives(): void
    {
        $this->assertSame('<a href="https://x.test">l</a>', $this->sanitize('<a href="https://x.test">l</a>'));
    }

    public function test_lists_survive(): void
    {
        $this->assertSame('<ul><li>a</li><li>b</li></ul>', $this->sanitize('<ul><li>a</li><li>b</li></ul>'));
        $this->assertSame('<ol><li>a</li></ol>', $this->sanitize('<ol><li>a</li></ol>'));
    }

    public function test_headings_quote_code_survive(): void
    {
        $this->assertSame('<h2>h</h2>', $this->sanitize('<h2>h</h2>'));
        $this->assertSame('<h3>h</h3>', $this->sanitize('<h3>h</h3>'));
        $this->assertSame('<blockquote>q</blockquote>', $this->sanitize('<blockquote>q</blockquote>'));
        $this->assertSame('<code>c</code>', $this->sanitize('<code>c</code>'));
    }

    public function test_span_style_is_unwrapped(): void
    {
        // styleWithCSS would emit this; the toolbar avoids it because it is lost here
        $this->assertSame('bold', $this->sanitize('<span style="font-weight:bold">bold</span>'));
    }

    public function test_script_is_dropped(): void
    {
        $this->assertStringNotContainsString('<script', $this->sanitize('<b>x</b><script>alert(1)</script>'));
    }
}
