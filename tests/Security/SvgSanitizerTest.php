<?php

declare(strict_types=1);

namespace EditFront\Tests\Security;

use EditFront\Security\SvgSanitizer;
use PHPUnit\Framework\TestCase;

final class SvgSanitizerTest extends TestCase
{
    private SvgSanitizer $svg;

    protected function setUp(): void
    {
        $this->svg = new SvgSanitizer();
    }

    public function test_clean_svg_passes_and_keeps_shapes(): void
    {
        $in = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect x="0" y="0" width="10" height="10" fill="#f00"/></svg>';
        $out = $this->svg->sanitize($in);
        $this->assertNotNull($out);
        $this->assertStringContainsString('<rect', (string) $out);
        $this->assertStringContainsString('viewBox', (string) $out, 'camelCase attr preserved');
        $this->assertStringContainsString('fill="#f00"', (string) $out);
    }

    public function test_script_element_is_stripped(): void
    {
        $in = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="5" height="5"/></svg>';
        $out = (string) $this->svg->sanitize($in);
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('alert', $out);
        $this->assertStringContainsString('<rect', $out);
    }

    public function test_event_handler_attribute_is_stripped(): void
    {
        $in = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="5" height="5" onload="evil()"/></svg>';
        $out = (string) $this->svg->sanitize($in);
        $this->assertStringNotContainsString('onload', $out);
    }

    public function test_foreignObject_is_stripped(): void
    {
        $in = '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><body xmlns="http://www.w3.org/1999/xhtml">x</body></foreignObject><rect width="1" height="1"/></svg>';
        $out = (string) $this->svg->sanitize($in);
        $this->assertStringNotContainsString('foreignObject', $out);
        $this->assertStringNotContainsString('<body', $out);
    }

    public function test_external_href_dropped_internal_kept(): void
    {
        $in = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
            . '<use xlink:href="https://evil.test/x.svg#a"/>'
            . '<use xlink:href="#clip"/></svg>';
        $out = (string) $this->svg->sanitize($in);
        $this->assertStringNotContainsString('evil.test', $out);
        $this->assertStringContainsString('#clip', $out);
    }

    public function test_javascript_href_dropped(): void
    {
        $in = '<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><rect width="1" height="1"/></a></svg>';
        $out = (string) $this->svg->sanitize($in);
        $this->assertStringNotContainsString('javascript:', $out);
    }

    public function test_entity_declaration_is_rejected(): void
    {
        $in = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            . '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>';
        $this->assertNull($this->svg->sanitize($in));
    }

    public function test_style_with_css_escape_is_dropped(): void
    {
        // "expressio\6e(" would slip past a plain substring check — backslash rejected
        $in = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="4" height="4" style="x:expressio\6e(alert(1))"/></svg>';
        $out = (string) $this->svg->sanitize($in);
        $this->assertStringNotContainsString('expressio', $out);
        $this->assertStringContainsString('<rect', $out);
    }

    public function test_style_with_url_is_dropped(): void
    {
        $in = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="4" height="4" style="fill:url(http://evil.test/x)"/></svg>';
        $out = (string) $this->svg->sanitize($in);
        $this->assertStringNotContainsString('evil.test', $out);
    }

    public function test_non_svg_returns_null(): void
    {
        $this->assertNull($this->svg->sanitize('<div>not svg</div>'));
        $this->assertNull($this->svg->sanitize('plain text'));
        $this->assertNull($this->svg->sanitize(''));
    }

    public function test_doctype_without_entity_is_stripped_but_svg_kept(): void
    {
        $in = '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">'
            . '<svg xmlns="http://www.w3.org/2000/svg"><circle cx="5" cy="5" r="4"/></svg>';
        $out = $this->svg->sanitize($in);
        $this->assertNotNull($out);
        $this->assertStringNotContainsString('DOCTYPE', (string) $out);
        $this->assertStringContainsString('<circle', (string) $out);
    }
}
