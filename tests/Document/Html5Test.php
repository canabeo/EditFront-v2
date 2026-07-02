<?php

declare(strict_types=1);

namespace EditFront\Tests\Document;

use EditFront\Document\Html5;
use PHPUnit\Framework\TestCase;

final class Html5Test extends TestCase
{
    private Html5 $html5;

    protected function setUp(): void
    {
        $this->html5 = new Html5();
    }

    public function test_serialize_is_idempotent_on_canonical_form(): void
    {
        $raw = (string) file_get_contents(dirname(__DIR__) . '/fixtures/page.html');
        $first = $this->html5->serialize($this->html5->parse($raw));
        $second = $this->html5->serialize($this->html5->parse($first));
        $this->assertSame($first, $second, 'canonical form must be a fixed point');
    }

    public function test_tail_whitespace_is_pinned(): void
    {
        $raw = "<!DOCTYPE html><html><head><title>x</title></head><body><p>hi</p>\n\n\n</body>\n\n\n</html>\n\n\n";
        $out = $this->html5->serialize($this->html5->parse($raw));
        $this->assertStringEndsWith("</body>\n</html>\n", $out);
        // repeated round-trips must not grow the file (v1 whitespace-inflation bug)
        $again = $this->html5->serialize($this->html5->parse($out));
        $this->assertSame(strlen($out), strlen($again));
    }

    public function test_import_fragment(): void
    {
        $doc = ef2_doc('<div id="host"></div>');
        $nodes = $this->html5->importFragment($doc, '<p>a</p><p>b</p>');
        $this->assertCount(2, $nodes);
        $this->assertSame($doc, $nodes[0]->ownerDocument);
    }

    public function test_inner_html(): void
    {
        $doc = ef2_doc('<div id="host"><b>x</b> y</div>');
        $host = $doc->getElementById('host') ?? $doc->getElementsByTagName('div')->item(0);
        $this->assertSame('<b>x</b> y', $this->html5->innerHtml($host));
    }
}
