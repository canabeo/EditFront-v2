<?php

declare(strict_types=1);

namespace EditFront\Tests\Security;

use PHPUnit\Framework\TestCase;

/** structural mode (node.restore): extended tags + curated attrs, still no active content */
final class SanitizerStructuralTest extends TestCase
{
    private \EditFront\Security\SanitizerHtml $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = ef2_sanitizer_html();
    }

    public function test_keeps_structural_tags_and_attrs(): void
    {
        $in = '<div class="hero" data-cms-id="cms-aaaaaaaaaaaa"><section><img src="/x.png" alt="pic" width="10" height="20">'
            . '<table><tbody><tr><td colspan="2">cell</td></tr></tbody></table></section></div>';
        $out = $this->sanitizer->sanitizeStructural($in);

        foreach (['<div', '<section', '<img', '<table', '<tbody', '<tr', '<td'] as $tag) {
            $this->assertStringContainsString($tag, $out);
        }
        $this->assertStringContainsString('class="hero"', $out);
        $this->assertStringContainsString('data-cms-id="cms-aaaaaaaaaaaa"', $out);
        $this->assertStringContainsString('src="/x.png"', $out);
        $this->assertStringContainsString('colspan="2"', $out);
    }

    public function test_rich_mode_still_unwraps_structural_tags(): void
    {
        $out = $this->sanitizer->sanitize('<div class="x"><p>text</p></div>');
        $this->assertStringNotContainsString('<div', $out);
        $this->assertStringContainsString('<p>text</p>', $out);
    }

    public function test_still_drops_active_content(): void
    {
        $out = $this->sanitizer->sanitizeStructural(
            '<div onclick="evil()"><script>1</script><iframe src="x"></iframe><p onmouseover="x()">ok</p></div>'
        );
        $this->assertStringNotContainsString('script', $out);
        $this->assertStringNotContainsString('iframe', $out);
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('onmouseover', $out);
        $this->assertStringContainsString('<p>ok</p>', $out);
    }

    public function test_style_attr_filtered_per_declaration(): void
    {
        $out = $this->sanitizer->sanitizeStructural(
            '<div style="color: red; background: url(javascript:alert(1)); font-size: 2em">x</div>'
        );
        $this->assertStringContainsString('color: red', $out);
        $this->assertStringContainsString('font-size: 2em', $out);
        $this->assertStringNotContainsString('javascript', $out);
    }

    public function test_invalid_cms_id_and_reserved_data_attrs_dropped(): void
    {
        $out = $this->sanitizer->sanitizeStructural(
            '<div data-cms-id="evil" data-cms-protected="true" data-role="hero">x</div>'
        );
        $this->assertStringNotContainsString('data-cms-id', $out);
        $this->assertStringNotContainsString('data-cms-protected', $out);
        $this->assertStringContainsString('data-role="hero"', $out);
    }

    public function test_src_url_gate(): void
    {
        $out = $this->sanitizer->sanitizeStructural('<img src="javascript:alert(1)" alt="a">');
        $this->assertStringNotContainsString('src=', $out);
        $this->assertStringContainsString('alt="a"', $out);
    }
}
