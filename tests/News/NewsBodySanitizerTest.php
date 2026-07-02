<?php
declare(strict_types=1);

namespace EditFront\Tests\News;

use EditFront\News\NewsBodySanitizer;
use EditFront\Security\SanitizerCss;
use EditFront\Security\SanitizerHtml;
use EditFront\Security\SanitizerUrl;
use PHPUnit\Framework\TestCase;

final class NewsBodySanitizerTest extends TestCase
{
    private function sanitizer(): NewsBodySanitizer
    {
        return new NewsBodySanitizer(
            new SanitizerHtml(new SanitizerUrl(), new SanitizerCss()),
            new SanitizerUrl()
        );
    }

    public function test_keeps_lead_dots_and_callout_class_hooks(): void
    {
        $out = $this->sanitizer()->sanitize(
            '<p class="lead">Лид.</p>'
            . '<ul class="dots"><li>один</li><li>два</li></ul>'
            . '<p class="callout">Врезка.</p>'
        );

        self::assertStringContainsString('class="lead"', $out);
        self::assertStringContainsString('class="dots"', $out);
        // callout lands on a kept tag (<p>), so the class survives
        self::assertStringContainsString('class="callout"', $out);
        self::assertStringContainsString('один', $out);
        self::assertStringContainsString('два', $out);
    }

    public function test_callout_div_is_unwrapped_but_inner_callout_class_survives(): void
    {
        // <div> is not in ALLOWED_TAGS → unwrapped; the inner <p class="callout"> stays.
        $out = $this->sanitizer()->sanitize(
            '<div class="callout"><p class="callout">Врезка.</p></div>'
        );

        self::assertStringNotContainsString('<div', $out);
        self::assertStringContainsString('class="callout"', $out);
        self::assertStringContainsString('Врезка.', $out);
    }

    public function test_strips_script(): void
    {
        $out = $this->sanitizer()->sanitize('<p>safe</p><script>alert(1)</script>');

        self::assertStringNotContainsString('<script', $out);
        self::assertStringNotContainsString('alert(1)', $out);
        self::assertStringContainsString('safe', $out);
    }

    public function test_strips_non_allowlisted_class_but_keeps_lead(): void
    {
        $out = $this->sanitizer()->sanitize('<p class="evil lead">x</p>');

        self::assertStringNotContainsString('evil', $out);
        self::assertStringContainsString('class="lead"', $out);
    }

    public function test_unwraps_unknown_tag_keeping_text(): void
    {
        $out = $this->sanitizer()->sanitize('<span>kept text</span>');

        self::assertStringNotContainsString('<span', $out);
        self::assertStringContainsString('kept text', $out);
    }

    // ---- Phase A: inline images ------------------------------------------

    public function test_keeps_inline_image_with_safe_src_alt_loading_class(): void
    {
        $out = $this->sanitizer()->sanitize(
            '<p>text <img src="/images/uploads/x.webp" class="ef-news-img" alt="a" loading="lazy"></p>'
        );

        self::assertStringContainsString('<img', $out);
        self::assertStringContainsString('src="/images/uploads/x.webp"', $out);
        self::assertStringContainsString('class="ef-news-img"', $out);
        self::assertStringContainsString('alt="a"', $out);
        self::assertStringContainsString('loading="lazy"', $out);
    }

    public function test_keeps_inline_image_with_https_src(): void
    {
        $out = $this->sanitizer()->sanitize(
            '<img src="https://cdn.example.com/a.webp" class="ef-news-img" alt="">'
        );

        self::assertStringContainsString('<img', $out);
        self::assertStringContainsString('src="https://cdn.example.com/a.webp"', $out);
    }

    public function test_drops_javascript_src_on_image(): void
    {
        $out = $this->sanitizer()->sanitize('<img src="javascript:alert(1)" class="ef-news-img">');

        // either the img is dropped or its src is dropped, but never a js: src
        self::assertStringNotContainsString('javascript:', $out);
        self::assertStringNotContainsString('alert(1)', $out);
    }

    public function test_drops_data_uri_src_on_image(): void
    {
        $out = $this->sanitizer()->sanitize(
            '<img src="data:text/html,<script>alert(1)</script>" class="ef-news-img">'
        );

        self::assertStringNotContainsString('data:', $out);
        self::assertStringNotContainsString('<script', $out);
        self::assertStringNotContainsString('alert(1)', $out);
    }

    public function test_drops_data_image_src_on_image(): void
    {
        // even a "valid" data:image (which the core structural pass allows for
        // src) must NOT survive in the news body — body images come from the
        // gallery as http(s)/relative URLs only.
        $out = $this->sanitizer()->sanitize(
            '<img src="data:image/png;base64,iVBORw0KGgo=" class="ef-news-img" alt="">'
        );

        self::assertStringNotContainsString('data:image', $out);
    }

    public function test_drops_protocol_relative_src_on_image(): void
    {
        // `//host/path` is schemeless — SanitizerUrl lets it through, but the
        // news body contract is http(s)/relative gallery URLs only. A
        // protocol-relative src would load an attacker-controlled third-party
        // image (tracking pixel / mixed-content), so the whole <img> is dropped.
        $out = $this->sanitizer()->sanitize('<img src="//evil.example/x.jpg" class="ef-news-img">');

        self::assertStringNotContainsString('<img', $out);
        self::assertStringNotContainsString('//evil.example', $out);
        self::assertStringNotContainsString('src="//', $out);
    }

    public function test_protocol_relative_drop_does_not_affect_legit_src(): void
    {
        // a legitimate relative gallery URL and an absolute https URL must STILL
        // survive after the protocol-relative gate is added.
        $rel = $this->sanitizer()->sanitize('<img src="/images/uploads/x.webp" class="ef-news-img">');
        self::assertStringContainsString('<img', $rel);
        self::assertStringContainsString('src="/images/uploads/x.webp"', $rel);

        $abs = $this->sanitizer()->sanitize('<img src="https://host/x.webp" class="ef-news-img">');
        self::assertStringContainsString('<img', $abs);
        self::assertStringContainsString('src="https://host/x.webp"', $abs);
    }

    public function test_strips_image_handlers_and_non_allowlisted_class(): void
    {
        $out = $this->sanitizer()->sanitize(
            '<img src="/images/uploads/x.webp" onerror="alert(1)" class="evil ef-news-img">'
        );

        self::assertStringNotContainsString('onerror', $out);
        self::assertStringNotContainsString('alert(1)', $out);
        self::assertStringNotContainsString('evil', $out);
        // the whitelisted hook class survives
        self::assertStringContainsString('ef-news-img', $out);
    }

    public function test_keeps_numeric_width_height_on_image_drops_garbage(): void
    {
        $out = $this->sanitizer()->sanitize(
            '<img src="/images/uploads/x.webp" width="640" height="480" data-evil="x" alt="">'
        );

        self::assertStringContainsString('width="640"', $out);
        self::assertStringContainsString('height="480"', $out);
        self::assertStringNotContainsString('data-evil', $out);
    }

    public function test_drops_non_numeric_width_on_image(): void
    {
        $out = $this->sanitizer()->sanitize(
            '<img src="/images/uploads/x.webp" width="100%;evil" alt="">'
        );

        self::assertStringContainsString('<img', $out);
        self::assertStringNotContainsString('100%', $out);
        self::assertStringNotContainsString('evil', $out);
    }

    // ---- Phase A: alignment ----------------------------------------------

    public function test_keeps_text_align_center_on_paragraph(): void
    {
        $out = $this->sanitizer()->sanitize('<p style="text-align: center">centered</p>');

        self::assertStringContainsString('text-align: center', $out);
        self::assertStringContainsString('centered', $out);
    }

    public function test_keeps_only_text_align_dropping_other_declarations(): void
    {
        $out = $this->sanitizer()->sanitize('<p style="color:red;text-align:center">x</p>');

        self::assertStringContainsString('text-align', $out);
        self::assertStringContainsString('center', $out);
        self::assertStringNotContainsString('color', $out);
        self::assertStringNotContainsString('red', $out);
    }

    public function test_keeps_text_align_on_headings(): void
    {
        $out = $this->sanitizer()->sanitize(
            '<h2 style="text-align:right">r</h2><h3 style="text-align:justify">j</h3>'
        );

        self::assertStringContainsString('text-align', $out);
        self::assertStringContainsString('right', $out);
        self::assertStringContainsString('justify', $out);
    }

    public function test_drops_invalid_text_align_value(): void
    {
        $out = $this->sanitizer()->sanitize('<p style="text-align: url(javascript:alert(1))">x</p>');

        self::assertStringNotContainsString('javascript', $out);
        self::assertStringNotContainsString('style=', $out);
    }

    public function test_drops_style_with_no_text_align(): void
    {
        $out = $this->sanitizer()->sanitize('<p style="color:red">x</p>');

        self::assertStringNotContainsString('style=', $out);
        self::assertStringNotContainsString('color', $out);
    }

    // ---- Phase A: underline ----------------------------------------------

    public function test_keeps_underline_tag(): void
    {
        $out = $this->sanitizer()->sanitize('<p>a <u>under</u> b</p>');

        self::assertStringContainsString('<u>', $out);
        self::assertStringContainsString('under', $out);
    }
}
