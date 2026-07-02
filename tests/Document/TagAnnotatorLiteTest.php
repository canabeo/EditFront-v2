<?php

declare(strict_types=1);

namespace EditFront\Tests\Document;

use EditFront\Document\Annotator;
use EditFront\Document\TagAnnotatorLite;
use PHPUnit\Framework\TestCase;

final class TagAnnotatorLiteTest extends TestCase
{
    private TagAnnotatorLite $lite;

    protected function setUp(): void
    {
        $this->lite = new TagAnnotatorLite(new Annotator());
    }

    /** strip inserted ids → must reproduce the original byte-for-byte */
    private function stripIds(string $html): string
    {
        return (string) preg_replace('/ data-cms-id="cms-[0-9a-f]{12}"/', '', $html);
    }

    public function test_preserves_original_formatting(): void
    {
        $raw = "<!DOCTYPE html>\n<html>\n<head>\n\t<title>X</title>\n</head>\n<body>\n"
            . "\t<p   class=\"x\" >Weird   spacing</p>\n\n\n"
            . "\t<div><span>nested</span></div>\n"
            . "</body>\n</html>\n";
        [$out, $changed] = $this->lite->annotate($raw);

        $this->assertTrue($changed);
        $this->assertSame($raw, $this->stripIds($out), 'only id attributes may differ');
    }

    public function test_stamps_eligible_tags_only(): void
    {
        $raw = '<html><head><title>t</title><meta charset="utf-8"></head>'
            . '<body><p>a</p><script>var s = "<p>not me</p>";</script><img src="x.png"></body></html>';
        [$out] = $this->lite->annotate($raw);

        $this->assertSame(2, preg_match_all('/data-cms-id="cms-[0-9a-f]{12}"/', $out));
        $this->assertStringContainsString('"<p>not me</p>"', $out, 'script content untouched');
        $this->assertDoesNotMatchRegularExpression('/<title[^>]*data-cms-id/', $out);
        $this->assertDoesNotMatchRegularExpression('/<meta[^>]*data-cms-id/', $out);
    }

    public function test_comments_untouched(): void
    {
        $raw = '<html><head><title>t</title></head><body><!-- <p>commented</p> --><p>real</p></body></html>';
        [$out] = $this->lite->annotate($raw);

        $this->assertStringContainsString('<!-- <p>commented</p> -->', $out);
        $this->assertSame(1, preg_match_all('/data-cms-id/', $out));
    }

    public function test_existing_id_is_kept(): void
    {
        $raw = '<html><head><title>t</title></head><body><p data-cms-id="cms-aaaaaaaaaaaa">x</p></body></html>';
        [$out, $changed] = $this->lite->annotate($raw);

        $this->assertFalse($changed);
        $this->assertSame($raw, $out);
    }

    public function test_protected_attr_node_skipped(): void
    {
        $raw = '<html><head><title>t</title></head><body><div data-cms-protected="true">x</div></body></html>';
        [$out] = $this->lite->annotate($raw);
        $this->assertDoesNotMatchRegularExpression('/<div[^>]*data-cms-id/', $out);
    }
}
